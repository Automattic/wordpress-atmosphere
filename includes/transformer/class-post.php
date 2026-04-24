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
		 * lint hooks) should branch on record shape (presence of
		 * `reply` or permalink-in-`text`) or inspect
		 * `atmosphere_long_form_composition` to decide per-entry
		 * behavior.
		 *
		 * @param array    $record Bsky post record.
		 * @param \WP_Post $post   WordPress post.
		 */
		return \apply_filters( 'atmosphere_transform_bsky_post', $record, $this->object );
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
	 *   - `'teaser-thread'`: 2+ records forming a reply chain
	 *     (hook + CTA by default; filterable to 3 posts via
	 *     `atmosphere_teaser_thread_posts`).
	 *   - unknown values: treated as `'link-card'`.
	 *
	 * Empty-body guard: for `'teaser-thread'` and `'truncate-link'`,
	 * if neither the post body nor an excerpt has at least 10
	 * characters of prose, the strategy silently degrades to
	 * `'link-card'` and an error_log notice is emitted so operators
	 * can tell the fallback from an intentional configuration.
	 *
	 * Publisher stamps `createdAt` (and `reply` for thread entries
	 * 1..N) at write time — they are intentionally absent from the
	 * returned records.
	 *
	 * `Post::transform()` is unchanged and remains the entry point
	 * for the short-form path and for any legacy caller on today's
	 * single-record contract.
	 *
	 * @return array[] Bsky post records, in thread order (index 0 is
	 *                 the root / parent of any replies).
	 */
	public function build_long_form_records(): array {
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
				$records = array();
				foreach ( $this->build_teaser_thread() as $text ) {
					$records[] = $this->record_for_thread_entry( (string) $text );
				}
				return $records;

			case 'truncate-link':
				return array( $this->record_for_thread_entry( $this->build_truncate_link_text() ) );

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
		if ( \mb_strlen( $text ) <= $max ) {
			return $text;
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
		$permalink = \get_permalink( $this->object );
		$plain     = $this->render_post_content_plain( $this->object );
		$budget    = 300 - \mb_strlen( "\n\n" ) - \mb_strlen( $permalink );

		$body = $this->truncate_to_budget( $plain, $budget, false );

		return $body . "\n\n" . $permalink;
	}

	/**
	 * Compose the default 2-post teaser thread: hook + CTA-with-link.
	 *
	 * Hook precedence:
	 *   1. If the post has a `post_excerpt`, use it (plain-text
	 *      normalized, clamped to 300 chars as a safety floor).
	 *      Excerpts are curated strings — a mid-word cut is unlikely
	 *      at this length, so word-boundary fallback is enough.
	 *   2. Otherwise, use the first ~280 chars of the body text,
	 *      cut at a **sentence boundary**. The hook is the final
	 *      prose shown before the CTA post, so we never end
	 *      mid-sentence. 280 leaves ~20 chars of headroom for future
	 *      variants that append trailing content.
	 *
	 * CTA is an internationalised `Continue reading: <permalink>`.
	 *
	 * Filterable via `atmosphere_teaser_thread_posts`. Downstream
	 * filters may return 3 entries to extend the thread; in that
	 * case the intermediate body-to-body cut (entry 1 → entry 2)
	 * may be at a word boundary, but the final body entry before
	 * the CTA (entry 2 → entry 3) must still cut at a sentence
	 * boundary. The return contract does not capture this — it's
	 * the filter author's responsibility.
	 *
	 * @return string[] Text of each post in order. At least 2 entries.
	 */
	private function build_teaser_thread(): array {
		if ( ! empty( $this->object->post_excerpt ) ) {
			$hook = $this->truncate_to_budget( sanitize_text( $this->object->post_excerpt ), 300, false );
		} else {
			$plain = $this->render_post_content_plain( $this->object );
			$hook  = $this->truncate_to_budget( $plain, 280, true );
		}

		$cta = \sprintf(
			/* translators: %s: the WordPress post permalink. */
			\__( 'Continue reading: %s', 'atmosphere' ),
			\get_permalink( $this->object )
		);

		/**
		 * Filters the default teaser-thread post texts.
		 *
		 * @param string[] $posts 2-entry array: [ hook, cta ].
		 * @param \WP_Post $post  The post being composed.
		 */
		return \apply_filters( 'atmosphere_teaser_thread_posts', array( $hook, $cta ), $this->object );
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
	 * Build one thread-entry record (hook, intermediate, or CTA).
	 *
	 * `createdAt` and `reply` are intentionally omitted — Publisher
	 * stamps both at write time so every record in a thread carries
	 * a fresh timestamp and a reply-ref to the already-written root.
	 *
	 * The `atmosphere_transform_bsky_post` filter fires **once per
	 * thread entry** for the thread strategies. Downstream consumers
	 * that assumed single-post semantics should branch on record
	 * shape (e.g. presence of `reply` is not yet set here, but the
	 * caller can check `$record['text']` against the CTA/permalink
	 * shape, or filter on `atmosphere_long_form_composition`
	 * upstream) to decide whether to transform every record.
	 *
	 * @param string $text Pre-composed post text.
	 * @return array Bsky post record (no createdAt, no reply).
	 */
	private function record_for_thread_entry( string $text ): array {
		$record = array(
			'$type' => 'app.bsky.feed.post',
			'text'  => $text,
			'langs' => $this->get_langs(),
		);

		$facets = Facet::extract( $text );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		/** This filter is documented in Post::transform() above. */
		return \apply_filters( 'atmosphere_transform_bsky_post', $record, $this->object );
	}

	/**
	 * Build the single link-card record (today's long-form output).
	 *
	 * Kept separate from `transform()` so `transform()` stays
	 * byte-compatible for legacy callers while `build_long_form_records()`
	 * can produce the same output when the composition filter
	 * resolves to `'link-card'` (the default) or an unknown value.
	 *
	 * @return array Bsky post record (no createdAt — Publisher stamps).
	 */
	private function record_for_link_card(): array {
		$text  = $this->build_text();
		$embed = $this->build_embed();

		$record = array(
			'$type' => 'app.bsky.feed.post',
			'text'  => $text,
			'langs' => $this->get_langs(),
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
		return \apply_filters( 'atmosphere_transform_bsky_post', $record, $this->object );
	}
}
