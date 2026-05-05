<?php
/**
 * Transforms a WordPress post into an app.bsky.feed.post record.
 *
 * The post text combines title + excerpt + permalink, truncated to
 * 300 characters.  An external embed card is attached with the
 * post's URL, title, description, and optional thumbnail.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\API;
use function Atmosphere\sanitize_text;
use function Atmosphere\truncate_text;

/**
 * Bluesky feed post transformer.
 */
class Post extends Base {

	/**
	 * Post meta key for the bsky post TID.
	 *
	 * @var string
	 */
	public const META_TID = '_atmosphere_bsky_tid';

	/**
	 * Post meta key for the bsky post AT-URI.
	 *
	 * @var string
	 */
	public const META_URI = '_atmosphere_bsky_uri';

	/**
	 * Post meta key for the bsky post CID.
	 *
	 * @var string
	 */
	public const META_CID = '_atmosphere_bsky_cid';

	/**
	 * Post meta key for the ordered list of bsky post
	 * { uri, cid, tid } triples written for this WordPress post.
	 *
	 * Populated by Publisher on every successful publish — even the
	 * single-record case — so readers can enumerate every Bluesky
	 * record tied to the post from this key alone. The legacy
	 * META_URI / META_TID / META_CID keys continue to mirror index 0
	 * (the root post) for backwards compatibility.
	 *
	 * @var string
	 */
	public const META_THREAD_RECORDS = '_atmosphere_bsky_thread_records';

	/**
	 * Multi-row post meta key indexing every Bluesky record URI tied
	 * to the post — root and every reply — so inbound reaction sync
	 * can resolve a `subject.uri` that targets a reply post back to
	 * the parent WordPress post. `META_URI` still holds the root for
	 * backwards compatibility; this key adds one row per URI,
	 * populated by Publisher on every successful publish / update.
	 *
	 * @var string
	 */
	public const META_URI_INDEX = '_atmosphere_bsky_uri_index';

	/**
	 * Post meta key for thread records left orphaned on the PDS after a
	 * rollback failure.
	 *
	 * Populated by Publisher only when a thread publish fails and the
	 * compensating-delete rollback also fails — the records listed here
	 * are alive on Bluesky but no longer tracked in META_THREAD_RECORDS
	 * (which Publisher clears to keep the local "active" state
	 * consistent with "not published"). Surfaced so an operator or
	 * recovery worker can issue manual deletes. Value shape mirrors
	 * META_THREAD_RECORDS with an added `stamp` key (ISO 8601 UTC).
	 *
	 * @var string
	 */
	public const META_ORPHAN_RECORDS = '_atmosphere_bsky_orphan_records';

	/**
	 * Tracks a deferred `update_document_bsky_ref` failure.
	 *
	 * Set by Publisher when the doc-ref `putRecord` fails after the
	 * thread root + document have already been written, so the bsky
	 * post(s) and the document are both live on the PDS but the
	 * document's `bskyPostRef` is missing or stale. The publish itself
	 * is treated as successful (replies still ship; rewriting the root
	 * on the next edit would be worse) and this meta records the gap so
	 * an operator or admin/Site Health surface can spot it.
	 *
	 * Cleared the next time `update_document_bsky_ref` succeeds for the
	 * post (typical recovery path: any subsequent edit retries the
	 * follow-up putRecord). Value: `[ stamp, code, message ]`.
	 *
	 * @var string
	 */
	public const META_DOC_REF_PENDING = '_atmosphere_doc_ref_pending';

	/**
	 * Transform the post.
	 *
	 * @return array app.bsky.feed.post record.
	 */
	public function transform(): array {
		/**
		 * Filters whether the post should be treated as short-form for Bluesky.
		 *
		 * Short-form posts publish natively (post body as text, no external
		 * embed card). Long-form posts use the teaser composition (title +
		 * excerpt + permalink) with an external card linking back to
		 * WordPress. The default discriminator mirrors the ActivityPub
		 * plugin's Post::get_type() logic: short-form when the post type
		 * does not support titles, the post has an empty title, or the
		 * post has any non-empty post_format.
		 *
		 * @param bool     $is_short Whether the post should be treated as short-form.
		 * @param \WP_Post $post     The post being transformed.
		 */
		$is_short = \wp_validate_boolean(
			\apply_filters(
				'atmosphere_is_short_form_post',
				$this->is_short_form( $this->object ),
				$this->object
			)
		);

		$text  = $is_short ? $this->build_short_form_text() : '';
		$embed = null;

		if ( '' === $text ) {
			$text  = $this->build_text();
			$embed = $this->build_embed();
		}

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->to_iso8601( $this->object->post_date_gmt ),
			'langs'     => $this->get_langs(),
		);

		$facets = Facet::extract( $text );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		if ( $embed ) {
			$record['embed'] = $embed;
		}

		$tags = $this->collect_tags( $this->object );
		if ( ! empty( $tags ) ) {
			$record['tags'] = $tags;
		}

		/**
		 * Filters the app.bsky.feed.post record before publishing.
		 *
		 * Fires once per record. For single-record strategies
		 * (`link-card`, `truncate-link`, and any short-form post) this
		 * is exactly one call per WordPress post — today's behavior.
		 * For `teaser-thread`, the filter fires for *every* thread
		 * entry (hook, intermediate posts, CTA). Listeners that
		 * accumulate state across calls (rate-limit counters, external
		 * lint hooks) should use the `$context` array to distinguish
		 * single-post output from teaser-thread entries.
		 *
		 * @param array    $record Bsky post record.
		 * @param \WP_Post $post   WordPress post.
		 * @param array    $context Additional composition context.
		 */
		return \apply_filters(
			'atmosphere_transform_bsky_post',
			$record,
			$this->object,
			array(
				'strategy'        => $is_short ? 'short-form' : 'link-card',
				'thread_index'    => 0,
				'is_thread_reply' => false,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_collection(): string {
		return 'app.bsky.feed.post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_rkey(): string {
		$rkey = \get_post_meta( $this->object->ID, self::META_TID, true );

		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
			\update_post_meta( $this->object->ID, self::META_TID, $rkey );
		}

		return $rkey;
	}

	/**
	 * Compose the post text: title + excerpt + permalink within 300 characters.
	 *
	 * @return string
	 */
	private function build_text(): string {
		$title     = sanitize_text( \get_the_title( $this->object ) );
		$excerpt   = $this->get_excerpt( $this->object );
		$permalink = \get_permalink( $this->object );

		$parts = \array_filter( array( $title, $excerpt, $permalink ) );
		$text  = \implode( "\n\n", $parts );

		if ( \mb_strlen( $text ) <= 300 ) {
			return $text;
		}

		// Reserve space for permalink + separators.
		$reserved  = \mb_strlen( $permalink ) + 4;
		$available = 300 - $reserved;

		if ( $available <= 0 ) {
			$prose = \trim( $title . ( ! empty( $excerpt ) ? "\n\n" . $excerpt : '' ) );

			return '' !== $prose ? truncate_text( $prose, 300 ) : truncate_text( $permalink, 300 );
		}

		$prose = $title;
		if ( ! empty( $excerpt ) ) {
			$prose .= "\n\n" . $excerpt;
		}

		$prose = truncate_text( $prose, $available );

		return $prose . "\n\n" . $permalink;
	}

	/**
	 * Build an app.bsky.embed.external card.
	 *
	 * @return array|null
	 */
	private function build_embed(): ?array {
		$permalink   = \get_permalink( $this->object );
		$title       = sanitize_text( \get_the_title( $this->object ) );
		$description = $this->get_excerpt( $this->object, 55 );

		$external = array(
			'uri'         => $permalink,
			'title'       => $title,
			'description' => $description,
		);

		$thumb_id = \get_post_thumbnail_id( $this->object );
		if ( $thumb_id ) {
			$blob = self::upload_thumbnail( $thumb_id );
			if ( $blob ) {
				$external['thumb'] = $blob;
			}
		}

		return array(
			'$type'    => 'app.bsky.embed.external',
			'external' => $external,
		);
	}

	/**
	 * Upload a thumbnail image and return the blob reference.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Blob reference or null.
	 */
	public static function upload_thumbnail( int $attachment_id ): ?array {
		// Check cache first.
		$cached = \get_post_meta( $attachment_id, '_atmosphere_blob_ref', true );
		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$file = \get_attached_file( $attachment_id );
		$mime = \get_post_mime_type( $attachment_id );

		if ( ! $file || ! $mime ) {
			return null;
		}

		// AT Protocol max blob size: 1 MB.
		if ( \filesize( $file ) > 1_000_000 ) {
			$resized = \image_get_intermediate_size( $attachment_id, 'large' );
			if ( $resized ) {
				$upload_dir = \wp_upload_dir();
				$file       = $upload_dir['basedir'] . '/' . $resized['path'];
			}
		}

		if ( ! \is_readable( $file ) || \filesize( $file ) > 1_000_000 ) {
			return null;
		}

		$result = API::upload_blob( $file, $mime );
		if ( \is_wp_error( $result ) ) {
			return null;
		}

		$blob_ref = $result['blob'] ?? null;
		if ( $blob_ref ) {
			\update_post_meta( $attachment_id, '_atmosphere_blob_ref', $blob_ref );
		}

		return $blob_ref;
	}

	/**
	 * Whether the post should be treated as short-form for Bluesky.
	 *
	 * Mirrors the ActivityPub plugin's Post::get_type() discriminator so
	 * a post federated as a Mastodon Note also goes to Bluesky as a
	 * native post instead of a link-card teaser. Short-form when:
	 * - the post type does not support titles, OR
	 * - the post has an empty title, OR
	 * - the post has any non-empty post_format.
	 *
	 * @param \WP_Post $post Post being transformed.
	 * @return bool
	 */
	private function is_short_form( \WP_Post $post ): bool {
		if ( ! \post_type_supports( $post->post_type, 'title' ) || empty( $post->post_title ) ) {
			return true;
		}

		return (bool) \get_post_format( $post );
	}

	/**
	 * Build the bsky.app post text for a short-form post.
	 *
	 * The post body becomes the Bluesky text directly, with no title
	 * prefix or trailing permalink. Defensively clamped to 300
	 * characters; a composer UI is expected to enforce the cap before
	 * publish.
	 *
	 * @return string
	 */
	private function build_short_form_text(): string {
		return truncate_text( $this->render_post_content_plain( $this->object ), 300 );
	}

	/**
	 * Whether this post should be treated as short-form for Bluesky.
	 *
	 * Thin public wrapper around the private discriminator plus the
	 * `atmosphere_is_short_form_post` filter. Callers such as
	 * Publisher branch on short vs. long without reaching into the
	 * transformer's private state.
	 *
	 * @return bool
	 */
	public function is_short_form_post(): bool {
		return \wp_validate_boolean(
			\apply_filters(
				'atmosphere_is_short_form_post',
				$this->is_short_form( $this->object ),
				$this->object
			)
		);
	}

	/**
	 * Produce the record(s) to publish for a long-form post.
	 *
	 * Branches on `atmosphere_long_form_composition`:
	 *   - `'link-card'` (default): 1 record, today's title + excerpt +
	 *     permalink + app.bsky.embed.external card.
	 *   - `'truncate-link'`: 1 record, body text + inline permalink,
	 *     no embed card.
	 *   - `'teaser-thread'`: a reply chain of hook + body chunk + CTA
	 *     (3 records by default; falls back to `[ hook, cta ]` when the
	 *     post body is too short for a body chunk; collapses further
	 *     to a single record when that 2-entry fallback would just be
	 *     `[ entire-body, "Continue reading: <permalink>" ]` — the
	 *     CTA is redundant when the entire post body is already the
	 *     hook). The terminal entry carries an `app.bsky.embed.external`
	 *     link card so the reader has a clear path back to the
	 *     WordPress post regardless of which entry surfaces. Filterable
	 *     via `atmosphere_teaser_thread_posts`.
	 *   - unknown values: treated as `'link-card'`.
	 *
	 * Empty-body guard: for `'teaser-thread'` and `'truncate-link'`,
	 * if neither the post body nor an excerpt has at least 10
	 * characters of prose, the strategy silently degrades to
	 * `'link-card'` and an error_log notice is emitted so operators
	 * can tell the fallback from an intentional configuration.
	 *
	 * Records carry `createdAt` before `atmosphere_transform_bsky_post`
	 * runs so filters see the same timestamp shape as `transform()`.
	 * Publisher fills `createdAt` only if a filter removes it, and adds
	 * `reply` refs for thread entries 1..N at write time after parent
	 * CIDs are known.
	 *
	 * `Post::transform()` is unchanged and remains the entry point
	 * for the short-form path and for any legacy caller on today's
	 * single-record contract.
	 *
	 * @param int $stored_count Number of bsky records currently stored
	 *                          for this post (from `META_THREAD_RECORDS`).
	 *                          Defaults to 0 — a fresh publish, no
	 *                          existing state. Callers updating an
	 *                          already-published post should pass the
	 *                          stored count so shape-shrinking optimisations
	 *                          (e.g. the redundant-CTA collapse) are
	 *                          skipped when they would force a destructive
	 *                          rewrite of an existing thread; preserving
	 *                          the stored shape lets `Publisher::update_post`
	 *                          take the in-place update path and keep
	 *                          external Bluesky engagement intact.
	 * @return array[] Bsky post records, in thread order (index 0 is
	 *                 the root / parent of any replies).
	 */
	public function build_long_form_records( int $stored_count = 0 ): array {
		/**
		 * Filters the long-form composition strategy for this post.
		 *
		 * @param string   $strategy Composition strategy key.
		 * @param \WP_Post $post     The post being transformed.
		 */
		$strategy = (string) \apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->object );

		if ( \in_array( $strategy, array( 'teaser-thread', 'truncate-link' ), true )
			&& ! $this->has_composable_body()
		) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				\sprintf(
					'[atmosphere] post %d has no composable body/excerpt; downgrading "%s" to "link-card"',
					$this->object->ID,
					$strategy
				)
			);

			/**
			 * Fires when a long-form strategy is silently downgraded to
			 * `'link-card'` because the post has neither a usable excerpt
			 * nor enough body text to compose a thread hook from.
			 *
			 * Purpose is observability — the downgrade is not itself an
			 * error, but ops teams may want to distinguish a fallback
			 * from an intentional `'link-card'` configuration.
			 *
			 * @param \WP_Post $post      The post being composed.
			 * @param string   $requested The strategy the filter returned (e.g. 'teaser-thread').
			 * @param string   $effective The strategy actually used ('link-card').
			 */
			\do_action( 'atmosphere_long_form_strategy_downgraded', $this->object, $strategy, 'link-card' );

			$strategy = 'link-card';
		}

		switch ( $strategy ) {
			case 'teaser-thread':
				if ( $this->requires_link_card_for_teaser_thread() ) {
					\do_action( 'atmosphere_long_form_strategy_downgraded', $this->object, $strategy, 'link-card' );
					return array( $this->record_for_link_card() );
				}

				// When the unfiltered default would publish the entire
				// body as the hook followed by a "Continue reading"
				// CTA, the CTA is redundant — there's nothing past
				// the hook to "continue reading" to. Collapse to a
				// single record (body text + link-card embed) so the
				// reader gets one clean post with a card linking back
				// to WordPress, instead of a 2-post self-reply where
				// the reply only restates the link.
				//
				// Backward-compat: skip the collapse when the post
				// already has 2+ stored records. `Publisher::update_post`
				// only takes the in-place update path when stored count
				// matches new count; a 2→1 shape change otherwise falls
				// through to `rewrite_thread()`, which deletes the
				// original root URI and orphans every external Bluesky
				// reply / like / repost on it. Preserving the stored
				// shape costs an extra (still-redundant) reply but
				// keeps engagement intact.
				//
				// Decision is made on the unfiltered default so an
				// `atmosphere_teaser_thread_posts` filter that
				// legitimately produces a 2-entry custom shape (custom
				// hook + custom second post) still runs and ships as 2
				// records.
				$default_texts = $this->compute_default_teaser_thread();
				if ( $stored_count < 2 && $this->default_teaser_thread_is_redundant_two_entry( $default_texts ) ) {
					return array(
						$this->record_for_thread_entry(
							(string) $default_texts[0],
							true,
							array(
								'strategy'        => 'teaser-thread',
								'thread_index'    => 0,
								'is_thread_reply' => false,
							),
							$this->build_embed()
						),
					);
				}

				$texts   = $this->build_teaser_thread();
				$records = array();
				$last    = \count( $texts ) - 1;
				// Attach an `app.bsky.embed.external` link card to the
				// terminal CTA entry. Without it, even when the thread
				// surfaces, the only link affordance is the URL in the
				// CTA's text — a card gives the reader title, excerpt,
				// and thumbnail. The embed attaches to "last entry,"
				// not "index 2," so a 2-entry fallback or filter
				// override still ships a CTA-with-card.
				foreach ( $texts as $i => $text ) {
					$records[] = $this->record_for_thread_entry(
						(string) $text,
						0 === $i,
						array(
							'strategy'        => 'teaser-thread',
							'thread_index'    => $i,
							'is_thread_reply' => 0 !== $i,
						),
						$i === $last ? $this->build_embed() : null
					);
				}
				return $records;

			case 'truncate-link':
				if ( $this->requires_link_card_for_long_permalink() ) {
					\do_action( 'atmosphere_long_form_strategy_downgraded', $this->object, $strategy, 'link-card' );
					return array( $this->record_for_link_card() );
				}

				return array(
					$this->record_for_thread_entry(
						$this->build_truncate_link_text(),
						true,
						array(
							'strategy'        => 'truncate-link',
							'thread_index'    => 0,
							'is_thread_reply' => false,
						)
					),
				);

			case 'link-card':
			default:
				return array( $this->record_for_link_card() );
		}
	}

	/**
	 * Truncate text to a character budget, preferring a sentence break.
	 *
	 * Priority order:
	 *   1. Sentence boundary (`.`, `!`, `?`, optionally followed by a
	 *      close-quote / close-paren / close-bracket) inside the
	 *      budget, when `$prefer_sentence` is true.
	 *   2. Word boundary — the last whitespace before the budget.
	 *   3. Hard cap: `$max - 1` chars + trailing ellipsis (a single
	 *      unbroken token longer than the budget).
	 *
	 * Character length uses `mb_strlen`, matching the convention of
	 * the existing `truncate_text()` helper. Preg offsets are byte
	 * offsets against the `mb_substr`-clamped string; substr on a
	 * match's byte-end is UTF-8-safe because matches end on valid
	 * sequence boundaries.
	 *
	 * @param string $text            Input text.
	 * @param int    $max             Maximum character length (mb_strlen).
	 * @param bool   $prefer_sentence Prefer a sentence boundary over a word boundary.
	 * @return string
	 */
	private function truncate_to_budget( string $text, int $max, bool $prefer_sentence = true ): string {
		if ( $max <= 0 ) {
			return '';
		}

		if ( \mb_strlen( $text ) <= $max ) {
			return $text;
		}

		if ( 1 === $max ) {
			return '…';
		}

		$clamped = \mb_substr( $text, 0, $max );

		if ( $prefer_sentence
			&& \preg_match_all(
				'/[.!?][\"\')\]]?(?=\s|$)/u',
				$clamped,
				$matches,
				\PREG_OFFSET_CAPTURE
			)
		) {
			$last    = \end( $matches[0] );
			$byte_to = $last[1] + \strlen( $last[0] );
			return \substr( $clamped, 0, $byte_to );
		}

		$word_cut = \preg_replace( '/\s+\S*$/u', '', $clamped );
		if ( \is_string( $word_cut ) && '' !== $word_cut && $word_cut !== $clamped ) {
			return $word_cut;
		}

		// Hard cap. Reserve one character for the ellipsis.
		return \mb_substr( $text, 0, \max( 1, $max - 1 ) ) . '…';
	}

	/**
	 * Whether the permalink is too long to place safely in post text.
	 *
	 * Used by the `truncate-link` strategy where the post text is just
	 * `<body>\n\n<permalink>` and the permalink is the load-bearing part.
	 *
	 * @return bool
	 */
	private function requires_link_card_for_long_permalink(): bool {
		return \mb_strlen( \get_permalink( $this->object ) ) >= 300;
	}

	/**
	 * Whether the teaser-thread CTA can't carry the full permalink.
	 *
	 * The CTA text is `Continue reading: <permalink>` (localized — the
	 * prefix length varies by locale). If the composed CTA exceeds the
	 * 300-char post limit, `truncate_to_budget()` would word-cut the URL
	 * fragment off and ship a thread whose final post says
	 * `Continue reading:` with no link. Detect that case and bail to
	 * link-card instead.
	 *
	 * @return bool
	 */
	private function requires_link_card_for_teaser_thread(): bool {
		return \mb_strlen( $this->teaser_thread_cta_text() ) > 300;
	}

	/**
	 * Compose the default teaser-thread CTA text.
	 *
	 * Centralised so the overflow guard (`requires_link_card_for_teaser_thread`)
	 * and the actual thread builder (`build_teaser_thread`) operate on
	 * identical strings.
	 *
	 * @return string
	 */
	private function teaser_thread_cta_text(): string {
		return \sprintf(
			/* translators: %s: the WordPress post permalink. */
			\__( 'Continue reading: %s', 'atmosphere' ),
			\get_permalink( $this->object )
		);
	}

	/**
	 * Compose the single-post truncate-link text.
	 *
	 * Used when `atmosphere_long_form_composition` returns
	 * `'truncate-link'`. Body-as-text plus trailing permalink.
	 * Word-boundary truncation is fine — the permalink follows
	 * immediately in the same post.
	 *
	 * @return string
	 */
	private function build_truncate_link_text(): string {
		$max_length = 300;
		$separator  = "\n\n";
		$permalink  = \get_permalink( $this->object );
		$plain      = $this->render_post_content_plain( $this->object );

		if ( \mb_strlen( $permalink ) >= $max_length ) {
			return $this->truncate_to_budget( $permalink, $max_length, false );
		}

		$budget = $max_length - \mb_strlen( $permalink );

		if ( $budget <= \mb_strlen( $separator ) ) {
			return $permalink;
		}

		$body = $this->truncate_to_budget( $plain, $budget - \mb_strlen( $separator ), false );

		return $body . $separator . $permalink;
	}

	/**
	 * Compose the default teaser thread: hook + body chunk + CTA-with-link.
	 *
	 * 2-post self-reply threads bundle/hide on bsky.app's profile views
	 * (`getAuthorFeed?filter=posts_no_replies` drops the root,
	 * `posts_with_replies` shows the reply but not the root). A 3-post
	 * thread surfaces normally on the Posts tab, so the default shape is
	 * 3 entries: a hook, a body chunk continuing the prose, and the CTA.
	 *
	 * Hook precedence:
	 *   1. If the post has a `post_excerpt`, use it (plain-text
	 *      normalized, clamped to 300 chars as a safety floor).
	 *      Excerpts are curated strings — a mid-word cut is unlikely
	 *      at this length, so word-boundary fallback is enough.
	 *   2. Otherwise, use the first ~280 chars of the body text,
	 *      cut at a **sentence boundary**. The hook is the final
	 *      prose shown before the body chunk, so we never end
	 *      mid-sentence. 280 leaves ~20 chars of headroom for future
	 *      variants that append trailing content.
	 *
	 * Body chunk:
	 *   - Excerpt-as-hook: the chunk starts from the start of the body —
	 *     curated excerpts are not sliding windows over the body.
	 *   - Body-as-hook: the chunk continues after the hook's cut point;
	 *     hook and chunk are non-overlapping windows over the same
	 *     plain-text body.
	 *   - Same ~280-char sentence-bounded budget as the hook.
	 *   - Dropped (and the output reduces to `[ hook, cta ]`) when the
	 *     post body is exhausted or fewer than ~10 chars of prose remain.
	 *
	 * CTA is an internationalised `Continue reading: <permalink>`. The
	 * link-card embed attached at the call site (`build_long_form_records`)
	 * applies to whichever entry is terminal — so the 2-entry fallback
	 * still ships a CTA-with-card.
	 *
	 * Filterable via `atmosphere_teaser_thread_posts`; the filter is the
	 * final transformation point and may return any 2..5 string entries.
	 *
	 * @return string[] Text of each post in order. 2 or 3 entries by
	 *                  default; up to 5 when overridden by filter.
	 */
	private function build_teaser_thread(): array {
		$default = $this->compute_default_teaser_thread();

		/**
		 * Filters the default teaser-thread post texts.
		 *
		 * Filtered entries are not shipped verbatim: each string passes
		 * through `sanitize_text()` and is clamped to 300 chars by
		 * `truncate_to_budget()`, and the array is silently capped at 5
		 * entries (PDS rate-limit blast-radius guard for mid-thread
		 * failures). Returning a non-array, an empty array, or fewer
		 * than 2 valid string entries triggers `_doing_it_wrong` and
		 * falls back to the default array.
		 *
		 * @param string[] $posts Default array: 2 entries `[ hook, cta ]`
		 *                        when the body is too short for a body
		 *                        chunk, otherwise 3 entries
		 *                        `[ hook, body_chunk, cta ]`.
		 * @param \WP_Post $post  The post being composed.
		 */
		$filtered = \apply_filters( 'atmosphere_teaser_thread_posts', $default, $this->object );

		// Defensive: a non-iterable or empty filter return would fatal on
		// the caller's foreach. Surface the misuse so the filter author
		// notices, then fall back to the default array.
		if ( ! \is_array( $filtered ) || empty( $filtered ) ) {
			\_doing_it_wrong(
				'atmosphere_teaser_thread_posts',
				\esc_html__( 'The atmosphere_teaser_thread_posts filter must return a non-empty array of strings; falling back to the default teaser-thread shape.', 'atmosphere' ),
				'unreleased'
			);
			return $default;
		}

		$texts = array();
		foreach ( $filtered as $entry ) {
			if ( \is_string( $entry ) ) {
				$entry = sanitize_text( $entry );
				if ( '' !== $entry ) {
					$texts[] = $this->truncate_to_budget( $entry, 300, false );
				}
			}
		}

		// A 1-entry return would silently route to publish_single() and
		// drop the CTA — confusing for filter authors who expected a
		// thread. Enforce the docblock contract instead.
		if ( \count( $texts ) < 2 ) {
			\_doing_it_wrong(
				'atmosphere_teaser_thread_posts',
				\esc_html__( 'The atmosphere_teaser_thread_posts filter must return at least 2 string entries; falling back to the default teaser-thread shape.', 'atmosphere' ),
				'unreleased'
			);
			return $default;
		}

		// Cap at 5 to contain PDS rate-limit blast radius on mid-thread
		// failure (which triggers N compensating deletes).
		return \array_slice( $texts, 0, 5 );
	}

	/**
	 * Whether the post has enough prose to be worth building a thread from.
	 *
	 * Used by the `build_long_form_records()` empty-body guard. 10
	 * characters is a defensive floor — anything below is noise and
	 * would produce a stub hook post.
	 *
	 * @return bool
	 */
	private function has_composable_body(): bool {
		if ( ! empty( $this->object->post_excerpt )
			&& \mb_strlen( sanitize_text( $this->object->post_excerpt ) ) >= 10
		) {
			return true;
		}

		return \mb_strlen( $this->render_post_content_plain( $this->object ) ) >= 10;
	}

	/**
	 * Compute the default teaser-thread text array, pre-filter.
	 *
	 * Extracted from `build_teaser_thread()` so callers can inspect the
	 * unfiltered default before deciding whether to short-circuit (e.g.
	 * the redundant-2-entry collapse in `build_long_form_records()`)
	 * without coupling to whatever an `atmosphere_teaser_thread_posts`
	 * filter might do.
	 *
	 * Hook precedence:
	 *   1. If the post has a `post_excerpt`, use it (plain-text
	 *      normalized, clamped to 300 chars as a safety floor).
	 *   2. Otherwise, use the first ~280 chars of the body text,
	 *      cut at a sentence boundary.
	 *
	 * Body chunk:
	 *   - Excerpt-as-hook: chunk_source is the entire body.
	 *   - Body-as-hook: chunk_source is what remains after the hook
	 *     consumed its slice.
	 *   - Dropped (output reduces to `[ hook, cta ]`) when fewer than
	 *     10 chars of prose remain after Unicode-aware leading-whitespace
	 *     strip.
	 *
	 * @return string[] 2 or 3 entries.
	 */
	private function compute_default_teaser_thread(): array {
		$excerpt = sanitize_text( (string) $this->object->post_excerpt );
		$plain   = $this->render_post_content_plain( $this->object );

		if ( \mb_strlen( $excerpt ) >= 10 ) {
			$hook         = $this->truncate_to_budget( $excerpt, 300, false );
			$chunk_source = $plain;
		} else {
			$hook = $this->truncate_to_budget( $plain, 280, true );
			// Strip the hard-cap ellipsis (when present) before measuring
			// how much of the plain body the hook consumed; the
			// sentence/word-cut paths return clean prefixes so this is a
			// no-op there. `mb_substr` keeps the strip char-aware —
			// `rtrim($hook, '…')` would strip individual UTF-8 bytes from
			// the multi-byte ellipsis sequence and can corrupt the trailing
			// non-ASCII char before it.
			$consumed     = '…' === \mb_substr( $hook, -1 )
				? \mb_substr( $hook, 0, \mb_strlen( $hook ) - 1 )
				: $hook;
			$chunk_source = \mb_substr( $plain, \mb_strlen( $consumed ) );
		}

		// Unicode-aware leading-whitespace strip: `\ltrim` only handles
		// ASCII whitespace, so NBSP (U+00A0) and ideographic space
		// (U+3000) at the start of `$chunk_source` would otherwise leak
		// into the body chunk as leading invisible whitespace. PCRE in
		// `/u` mode returns null on invalid UTF-8; fall back to the
		// pre-strip slice so the `mb_strlen` check below stays string-safe.
		$stripped     = \preg_replace( '/^\s+/u', '', $chunk_source );
		$chunk_source = \is_string( $stripped ) ? $stripped : $chunk_source;
		$cta          = $this->teaser_thread_cta_text();

		return \mb_strlen( $chunk_source ) >= 10
			? array( $hook, $this->truncate_to_budget( $chunk_source, 280, true ), $cta )
			: array( $hook, $cta );
	}

	/**
	 * Whether the unfiltered default teaser-thread is the redundant
	 * `[ entire-body, "Continue reading: <permalink>" ]` shape.
	 *
	 * Triggers when the post has no usable `post_excerpt` (so the hook
	 * IS the body) AND the body fits entirely in the 280-char hook
	 * budget (so `chunk_source` is below the 10-char floor and the
	 * default falls back to `[ hook, cta ]`). In that shape, the CTA
	 * reply is purely redundant — there's nothing past the hook to
	 * "continue reading" to. Callers can collapse to a single record
	 * with the body text and a link-card embed instead.
	 *
	 * Decision is made on the unfiltered default so filter authors who
	 * legitimately want a 2-entry custom shape (custom hook + custom
	 * second post) still see their filter run on the un-collapsed
	 * default and ship as 2 records.
	 *
	 * @param array $default_texts Precomputed default array (pass to
	 *                             avoid recomputing — this method does
	 *                             not call `compute_default_teaser_thread()`
	 *                             itself).
	 * @return bool
	 */
	private function default_teaser_thread_is_redundant_two_entry( array $default_texts ): bool {
		if ( 2 !== \count( $default_texts ) ) {
			return false;
		}

		$excerpt = sanitize_text( (string) $this->object->post_excerpt );

		return \mb_strlen( $excerpt ) < 10;
	}

	/**
	 * Build one thread-entry record (hook, intermediate, or CTA).
	 *
	 * `reply` is intentionally omitted — Publisher stamps it at write
	 * time for non-root entries after the parent CID is known.
	 *
	 * The root entry (`$is_root === true`) carries the post's `tags`,
	 * mirroring `record_for_link_card()` and `transform()` — the root
	 * is the indexed representation of the WP post for the Bluesky
	 * algorithm. Non-root replies are conversational and omit tags.
	 *
	 * `$embed` is set by the teaser-thread caller for the terminal CTA
	 * entry of a multi-record thread, AND for the root of a collapsed
	 * single-record thread (where the would-be CTA is dropped but the
	 * link-card embed is preserved on the surviving record). `reply`
	 * and `embed` are independent fields in `app.bsky.feed.post`'s
	 * lexicon, so a record carrying both is fine.
	 *
	 * @param string     $text    Pre-composed post text.
	 * @param bool       $is_root Whether this record is the thread root.
	 * @param array      $context Additional filter context.
	 * @param array|null $embed   Optional `app.bsky.embed.external` card.
	 * @return array Bsky post record (no reply).
	 */
	private function record_for_thread_entry( string $text, bool $is_root = false, array $context = array(), ?array $embed = null ): array {
		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->to_iso8601( $this->object->post_date_gmt ),
			'langs'     => $this->get_langs(),
		);

		$facets = Facet::extract( $text );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		if ( null !== $embed ) {
			$record['embed'] = $embed;
		}

		if ( $is_root ) {
			$tags = $this->collect_tags( $this->object );
			if ( ! empty( $tags ) ) {
				$record['tags'] = $tags;
			}
		}

		$context = \wp_parse_args(
			$context,
			array(
				'strategy'        => 'teaser-thread',
				'thread_index'    => 0,
				'is_thread_reply' => ! $is_root,
			)
		);

		/** This filter is documented in Post::transform() above. */
		return \apply_filters( 'atmosphere_transform_bsky_post', $record, $this->object, $context );
	}

	/**
	 * Build the single link-card record (today's long-form output).
	 *
	 * Kept separate from `transform()` so `transform()` stays
	 * byte-compatible for legacy callers while `build_long_form_records()`
	 * can produce the same output when the composition filter
	 * resolves to `'link-card'` (the default) or an unknown value.
	 *
	 * @return array Bsky post record.
	 */
	private function record_for_link_card(): array {
		$text  = $this->build_text();
		$embed = $this->build_embed();

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->to_iso8601( $this->object->post_date_gmt ),
			'langs'     => $this->get_langs(),
		);

		$facets = Facet::extract( $text );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		if ( $embed ) {
			$record['embed'] = $embed;
		}

		$tags = $this->collect_tags( $this->object );
		if ( ! empty( $tags ) ) {
			$record['tags'] = $tags;
		}

		/** This filter is documented in Post::transform() above. */
		return \apply_filters(
			'atmosphere_transform_bsky_post',
			$record,
			$this->object,
			array(
				'strategy'        => 'link-card',
				'thread_index'    => 0,
				'is_thread_reply' => false,
			)
		);
	}
}
