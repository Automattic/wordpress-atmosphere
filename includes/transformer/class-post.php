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
use Atmosphere\CID;
use Atmosphere\Mention;
use function Atmosphere\build_at_uri;
use function Atmosphere\debug_log;
use function Atmosphere\get_did;
use function Atmosphere\get_publishable_content;
use function Atmosphere\grapheme_length;
use function Atmosphere\sanitize_text;
use function Atmosphere\truncate_graphemes;
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
	 * Post meta key for the DID that minted the bsky TID.
	 *
	 * Persisted at the same time as `META_TID` so cleanup paths can
	 * detect when a post's record was written under a different
	 * account (disconnect → reconnect-to-different-DID flow,
	 * `updateHandle` that triggered a migration, atproto account
	 * migration). Without this, `applyWrites#delete` against the
	 * currently-connected DID's repo silently succeeds for a TID that
	 * doesn't exist there — leaving the original record orphaned on
	 * the previous DID's PDS with no local pointer.
	 *
	 * @var string
	 */
	public const META_DID = '_atmosphere_bsky_did';

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
	 * Legacy post meta key for deferred document back-reference failures.
	 *
	 * Kept so cleanup paths remove markers left by older versions that
	 * attempted a follow-up document `putRecord` after publishing the
	 * Bluesky post. New writes no longer set this marker because the
	 * document record is now the stable target of the Bluesky
	 * `associatedRefs` strong reference.
	 *
	 * @var string
	 */
	public const META_DOC_REF_PENDING = '_atmosphere_doc_ref_pending';

	/**
	 * AT Protocol maximum blob size, in bytes.
	 *
	 * @var int
	 */
	private const MAX_BLOB_BYTES = 1_000_000;

	/**
	 * Bracketing tokens for the inline-link placeholder.
	 *
	 * An `<a>` is swapped for `PREFIX{n}SUFFIX` while the post text is
	 * normalized, then substituted back so the link facet lands on the exact
	 * anchor-text byte range. The tokens are alphanumeric (so whitespace
	 * collapse can't split them) and deliberately unlikely to appear in real
	 * content, since a collision would misplace a facet.
	 *
	 * @var string
	 */
	private const LINK_MARKER_PREFIX = 'atmxinlinexlinkx';

	/**
	 * Trailing token for the inline-link placeholder. See {@see self::LINK_MARKER_PREFIX}.
	 *
	 * @var string
	 */
	private const LINK_MARKER_SUFFIX = 'xendxinlinexlink';

	/**
	 * Document strongRef the Publisher pre-computed for the initial
	 * atomic `applyWrites#create`.
	 *
	 * AT Protocol's chicken-and-egg: a strongRef needs the target's
	 * CID, and the document's CID only exists after a write. The
	 * Publisher closes the gap by computing the document's CID
	 * locally via the DAG-CBOR encoder ({@see \Atmosphere\CID}) and
	 * pushing the resulting `{uri, cid}` here before any
	 * `transform()` / `build_long_form_records()` call. The embed
	 * builder picks the ref up and includes it in the post's
	 * `embed.external.associatedRefs` array on the very first
	 * applyWrites — which is what Bluesky's AppView indexes
	 * (subsequent `applyWrites#update` follow-ups are ignored for
	 * `source` / `associatedProfiles` enrichment).
	 *
	 * Null on a fresh transformer; reset whenever a fresh Post object
	 * is constructed. When no ref is injected and meta fallback is
	 * enabled, {@see self::build_embed()} reads from `Document::META_*`.
	 *
	 * @var array{$type: string, uri: string, cid: string}|null
	 */
	private ?array $document_strong_ref = null;

	/**
	 * Whether the embed builder may fall back to the stored document ref.
	 *
	 * The Publisher disables this after it attempted to compute the
	 * current document CID but failed. In that case `Document::META_CID`
	 * points at the previous document version, while the same batch is
	 * about to write a new document record, so advertising the meta ref
	 * would preserve the stale-CID bug this guard exists to avoid.
	 *
	 * @var bool
	 */
	private bool $document_meta_strong_ref_enabled = true;

	/**
	 * Memoized short-form verdict for this post.
	 *
	 * {@see self::is_short_form_post()} is evaluated more than once per
	 * publish (Publisher's document-strongRef precompute and the short/long
	 * routing, plus {@see self::transform()}), and the
	 * `atmosphere_is_short_form_post` filter fires on every call. Caching the
	 * verdict on the instance keeps every caller in agreement even when a
	 * subscriber's filter is stateful, so the embed-strategy label, the
	 * document-strongRef precompute, and the published record cannot disagree.
	 *
	 * @var bool|null
	 */
	private ?bool $short_form_verdict = null;

	/**
	 * Custom Bluesky text to use instead of the post's saved meta value.
	 *
	 * Null means "read the saved {@see ATMOSPHERE_META_CUSTOM_TEXT} meta"
	 * (the publish path). The pre-publish projector sets this to the
	 * *unsaved* textarea value so the preview reflects what the author is
	 * typing before they save. An empty string is a meaningful override
	 * here — it forces the default composition even when meta is non-empty.
	 *
	 * @var string|null
	 */
	private ?string $custom_text_override = null;

	/**
	 * Memoized classification of the post body's @mentions.
	 *
	 * Cached result of {@see Mention::classify_handles()} so the carry-over and
	 * the publish-time mention deny-set share a single HTML walk per transform.
	 *
	 * @var array{linkable:array<string,string>,protected:array<string,true>}|null
	 */
	private ?array $classified_body_handles = null;

	/**
	 * Whether the transformer is running in projection mode.
	 *
	 * Projection mode ({@see self::project()}) reproduces the exact text
	 * and strategy the publish path would produce, but skips the blob
	 * uploads the embed builders would otherwise perform — those make
	 * network calls to the PDS and cache `_atmosphere_blob_ref` meta, so
	 * they must not fire on a read-only editor preview request. Embed
	 * *structure* is preserved (so the null/non-null branches that select
	 * the record text stay identical to a real publish); only the upload
	 * side effects are suppressed.
	 *
	 * Facet extraction is likewise skipped: it resolves @-mentions over
	 * DNS, and running that per keystroke on unsaved, caller-supplied text
	 * would turn the preview endpoint into a DNS-egress amplifier. Facets
	 * annotate the text without changing it, so the grapheme count is
	 * unaffected by their absence.
	 *
	 * The body-mention *carry-over*, however, does change the composed text
	 * (it inserts a `@handle` line), so skipping it entirely would make the
	 * preview under-report a record the publisher will lengthen. Projection
	 * therefore still sizes the carry-over, but from the *syntactic* body
	 * handles ({@see self::body_mentions()}) rather than resolved ones — no
	 * DNS, and an upper bound so the reported count is never short.
	 *
	 * @var bool
	 */
	private bool $projecting = false;

	/**
	 * Project the records this post would publish, without side effects.
	 *
	 * Mirrors the short-form vs. long-form branch the Publisher takes, so
	 * the editor pre-publish panel can show the real strategy and Bluesky
	 * character count before anything is written. Runs in projection mode
	 * ({@see self::$projecting}) so no blobs are uploaded and no meta is
	 * touched.
	 *
	 * Counts are reported in the same unit the publish path clamps on —
	 * graphemes, as used by `truncate_text()` and Bluesky's own composer — so
	 * the preview's "X / 300" matches what the author sees on Bluesky and
	 * never says "within limit" for text the publisher would shorten. They
	 * are measured against the user's *untruncated* text: a
	 * short-form post longer than the limit still publishes a clamped
	 * record, but the panel surfaces the real length (e.g. "340 / 300") so
	 * the author knows truncation will happen before they publish. Composed
	 * long-form records (link card, teaser-thread chunks) are built to fit,
	 * so their record text is the source text.
	 *
	 * @return array{
	 *     is_short_form: bool,
	 *     strategy: string,
	 *     limit: int,
	 *     records: array<int, array{characters: int, over_limit: bool}>
	 * }
	 */
	public function project(): array {
		$this->projecting = true;
		$custom           = $this->has_custom_text();

		try {
			if ( $custom ) {
				// Author-supplied text: one link-card record, its own strategy
				// label so the panel shows the composition setting is skipped.
				$records  = array( $this->transform() );
				$strategy = 'custom-text';
				$is_short = false;
			} else {
				$is_short = $this->is_short_form_post();

				if ( $is_short ) {
					$records  = array( $this->transform() );
					$strategy = $this->is_redacted() ? 'redacted' : 'short-form';
				} else {
					$records  = $this->build_long_form_records();
					$strategy = $this->projected_long_form_strategy( \count( $records ) );
				}
			}
		} finally {
			$this->projecting = false;
		}

		$limit     = self::BLUESKY_MAX_GRAPHEMES;
		$projected = array();

		foreach ( $records as $index => $record ) {
			/*
			 * Measure the author's untruncated text where the publish path
			 * clamps it, so the panel surfaces the real length (e.g. "340 /
			 * 300") instead of the already-shortened record:
			 *  - custom text: the typed value (transform() clamps to 300);
			 *  - the primary short-form record: the rendered post body.
			 * Composed records (link card, teaser chunks) are built to fit,
			 * so their own record text is the right thing to measure.
			 */
			if ( $custom && 0 === $index ) {
				$measured = $this->custom_text_body();
			} elseif ( $is_short && 0 === $index && ! $this->is_redacted() ) {
				$measured = $this->render_post_content_plain( $this->object );
			} else {
				$measured = (string) ( $record['text'] ?? '' );
			}

			$characters  = grapheme_length( $measured );
			$projected[] = array(
				'characters' => $characters,
				'over_limit' => $characters > $limit,
			);
		}

		return array(
			'is_short_form' => $is_short,
			'strategy'      => $strategy,
			'limit'         => $limit,
			'records'       => $projected,
		);
	}

	/**
	 * Build the Bluesky record(s) that would be published for this post.
	 *
	 * Overrides {@see Base::get_preview_records()} to mirror the publish
	 * branch used by Publisher without writing blobs or touching post meta:
	 * a long-form post may project to a thread of several records.
	 *
	 * @return array<int,array> Bsky post records, in publish order.
	 */
	public function get_preview_records(): array {
		$this->projecting = true;

		try {
			if ( $this->is_short_form_post() ) {
				return array( $this->transform() );
			}

			$this->inject_preview_document_ref();

			return $this->build_long_form_records();
		} finally {
			$this->projecting = false;
		}
	}

	/**
	 * Mirror the Publisher's document strongRef precompute for previews.
	 *
	 * The publish path transforms the document, computes its CID locally,
	 * and injects the resulting strongRef before composing the post
	 * ({@see \Atmosphere\Publisher::publish_post()}). Without the same
	 * step a preview falls back to `Document::META_*`, which goes stale
	 * as soon as the document changes — so the projected `associatedRefs`
	 * would not match what the Publisher writes on the next update.
	 *
	 * Stays strictly read-only: `Document::get_rkey()` would reserve
	 * `META_TID`, a publish-state marker `Publisher::update_post()` keys
	 * off, so the reserved TID is read straight from meta instead. A
	 * never-published post has no rkey to read — the ref only exists
	 * once publish reserves one, so the preview omits it rather than
	 * minting a placeholder URI.
	 */
	private function inject_preview_document_ref(): void {
		if ( null !== $this->document_strong_ref ) {
			return;
		}

		$did = get_did();

		if ( '' === $did ) {
			return;
		}

		$rkey = (string) \get_post_meta( $this->object->ID, Document::META_TID, true );

		if ( '' === $rkey ) {
			return;
		}

		/*
		 * Hash the *projected* document record — the same JSON the
		 * `?atproto` document selector serves — so the projection stays
		 * read-only (no blob uploads; see Document::get_preview_records())
		 * and the injected CID matches the displayed document preview.
		 */
		$doc_cid = CID::from_record( ( new Document( $this->object ) )->get_preview_records()[0] );

		if ( \is_wp_error( $doc_cid ) ) {
			// Same degradation as the publish path: no ref beats a stale one.
			$this->set_document_strong_ref( null );
			return;
		}

		$this->set_document_strong_ref(
			array(
				'uri' => build_at_uri( $did, 'site.standard.document', $rkey ),
				'cid' => $doc_cid,
			)
		);
	}

	/**
	 * Resolve the human-facing strategy label for a long-form projection.
	 *
	 * A teaser thread is unambiguous from its record count. A single
	 * record is `truncate-link` only when that strategy was both requested
	 * and not downgraded; every other single-record long-form outcome —
	 * including a `teaser-thread`/`truncate-link` that the empty-body guard
	 * collapsed to a link card — reports as `link-card`.
	 *
	 * @param int $record_count Number of projected records.
	 * @return string Strategy key.
	 */
	private function projected_long_form_strategy( int $record_count ): string {
		if ( $record_count > 1 ) {
			return 'teaser-thread';
		}

		$requested = (string) \apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->object );

		return 'truncate-link' === $requested ? 'truncate-link' : 'link-card';
	}

	/**
	 * Inject the document strongRef the embed builder should advertise
	 * in `associatedRefs` on the initial publish.
	 *
	 * See {@see self::$document_strong_ref} for the why. Passing an
	 * empty array or a malformed shape (missing `uri` / `cid`) clears
	 * the injection and the embed builder falls back to reading from
	 * `Document::META_*`. Passing null clears the injection and
	 * suppresses that fallback for this transformer instance.
	 *
	 * @param array|null $ref StrongRef to advertise (keys: optional `$type`, required `uri` and `cid`).
	 */
	public function set_document_strong_ref( ?array $ref ): void {
		if ( null === $ref ) {
			$this->document_strong_ref              = null;
			$this->document_meta_strong_ref_enabled = false;
			return;
		}

		$this->document_meta_strong_ref_enabled = true;

		if ( empty( $ref['uri'] ) || empty( $ref['cid'] ) ) {
			$this->document_strong_ref = null;
			return;
		}

		$this->document_strong_ref = array(
			'$type' => 'com.atproto.repo.strongRef',
			'uri'   => (string) $ref['uri'],
			'cid'   => (string) $ref['cid'],
		);
	}

	/**
	 * Override the custom Bluesky text for this transformer instance.
	 *
	 * Used by the pre-publish projector so the preview reflects the
	 * *unsaved* textarea value rather than the last-saved meta. Pass `null`
	 * to fall back to the saved {@see ATMOSPHERE_META_CUSTOM_TEXT} meta.
	 *
	 * @param string|null $text Custom text, or null to read saved meta.
	 */
	public function set_custom_text_override( ?string $text ): void {
		$this->custom_text_override = null === $text ? null : (string) $text;
	}

	/**
	 * The custom Bluesky text for this post, trimmed.
	 *
	 * Returns the override when one is set (projection), otherwise the
	 * saved {@see ATMOSPHERE_META_CUSTOM_TEXT} meta. An empty string means
	 * "no custom text — run the default composition".
	 *
	 * @return string
	 */
	private function get_custom_text(): string {
		$text = null !== $this->custom_text_override
			? $this->custom_text_override
			: (string) \get_post_meta( $this->object->ID, ATMOSPHERE_META_CUSTOM_TEXT, true );

		return \trim( $text );
	}

	/**
	 * Whether this post has custom Bluesky text that overrides composition.
	 *
	 * Redacted posts never expose custom text — a non-published or
	 * password-protected post must not leak author-written copy into a PDS
	 * record, exactly as its body is suppressed.
	 *
	 * @return bool
	 */
	private function has_custom_text(): bool {
		return ! $this->is_redacted() && '' !== $this->get_custom_text();
	}

	/**
	 * The author's custom text decoded to its literal, human-readable form.
	 *
	 * Decodes HTML entities back to the characters the author typed and keeps
	 * their line breaks (a Bluesky post can span lines). It deliberately does
	 * NOT strip tags: the meta is already tag-sanitized on save
	 * (`sanitize_textarea_field` removes real tags and escapes a stray `<` to
	 * `&lt;`), so there are no live tags left — and stripping after decoding
	 * would eat a literal `<3` (PHP `strip_tags()` reads `<3 ...` as an
	 * unclosed tag and drops the rest). Bluesky renders post text as plain
	 * UTF-8, so the decoded `<` is shown literally, never as markup.
	 *
	 * Not truncated — callers that build a record clamp it; the projector
	 * measures this length to surface the real (untruncated) character count.
	 *
	 * @return string
	 */
	private function custom_text_body(): string {
		return \trim( \html_entity_decode( $this->get_custom_text(), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * The custom text shaped into a Bluesky post body.
	 *
	 * Hard-clamped to Bluesky's 300-grapheme limit via `truncate_text()`,
	 * which counts graphemes the way Bluesky's composer does — the same clamp
	 * the short-form path uses, so an over-long custom text is shortened
	 * rather than rejected.
	 *
	 * @return string
	 */
	private function prepare_custom_text(): string {
		return truncate_text( $this->custom_text_body(), self::BLUESKY_MAX_GRAPHEMES );
	}

	/**
	 * Transform the post.
	 *
	 * @return array app.bsky.feed.post record.
	 */
	public function transform(): array {
		$redacted = $this->is_redacted();

		$custom = ! $redacted && $this->has_custom_text();

		/*
		 * Redacted posts return short-form (empty text, no embed) without
		 * exposing the post to the `atmosphere_is_short_form_post` filter. A
		 * custom-text post is always a single link-card record, so it skips
		 * the short/long discriminator (and its body-length work) entirely.
		 * For everyone else the public discriminator decides — including the
		 * length gate that routes an overflowing titleless post to long-form.
		 */
		if ( $redacted ) {
			$is_short = true;
		} elseif ( $custom ) {
			$is_short = false;
		} else {
			$is_short = $this->is_short_form_post();
		}

		$text        = '';
		$embed       = null;
		$link_facets = array();

		if ( ! $redacted ) {
			if ( $custom ) {
				/*
				 * Author-supplied text wins over the automatic composition.
				 * Post exactly what they wrote, with an external link card
				 * back to the WordPress post so the Bluesky note still
				 * connects to the blog entry — the link-card strategy with
				 * the prose replaced by the custom text. Reported as
				 * `link-card` to the filter/embed strategy below (it is a
				 * single link-card record); the pre-publish projector labels
				 * it `custom-text` for the author.
				 */
				$is_short = false;
				$text     = $this->prepare_custom_text();
				$embed    = $this->build_embed();
			} elseif ( $is_short ) {
				$short       = $this->build_short_form_text();
				$text        = $short['text'];
				$link_facets = $short['facets'];

				$embed = $this->build_images_embed();
				if ( '' === $text && null === $embed ) {
					/*
					 * Empty body and no images: there is nothing to publish
					 * natively, so fall back to the link-card composition. This
					 * is a link-card record, so flip $is_short to false (the
					 * embed-filter strategy label and the
					 * atmosphere_transform_bsky_post context below must report
					 * `link-card`, not `short-form`), and the short-form anchor
					 * facets no longer apply — the text is now the excerpt, not
					 * the post body.
					 */
					$is_short    = false;
					$text        = $this->build_text();
					$embed       = $this->build_embed();
					$link_facets = array();
				}
			} else {
				$text  = $this->build_text();
				$embed = $this->build_embed();
			}
		}

		if ( ! $redacted ) {
			$embed = $this->apply_post_embed_filter( $embed, $is_short ? 'short-form' : 'link-card' );
		}

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->to_iso8601( $this->object->post_date_gmt ),
			'langs'     => $this->get_langs(),
		);

		if ( $this->projecting ) {
			// Skip DNS-resolving facet extraction in projection mode (see $projecting).
			$facets = array();
		} else {
			$facets = $this->merge_link_facets(
				$link_facets,
				Facet::extract( $text, $this->mentions_enabled(), $this->blocked_mention_handles() )
			);
		}
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		// `apply_post_embed_filter()` guarantees `$embed` is either null
		// or a well-formed array with a `$type` key, so this matches
		// the `null !== $embed` check in `record_for_thread_entry()`.
		if ( null !== $embed ) {
			$record['embed'] = $embed;
		}

		if ( ! $redacted ) {
			$tags = $this->collect_tags( $this->object );
			if ( ! empty( $tags ) ) {
				$record['tags'] = $tags;
			}
		}

		if ( $redacted ) {
			return $record;
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
		 * Filters that return a non-array fall back to the pre-filter
		 * record — protects the applyWrites batch from a misbehaving
		 * listener.
		 *
		 * A custom-text record reports `strategy => 'link-card'` (it is
		 * structurally a single link-card record) but also sets
		 * `is_custom_text => true`, so listeners can tell author-supplied
		 * text apart from the automatically composed link card.
		 *
		 * @param array    $record  Bsky post record.
		 * @param \WP_Post $post    WordPress post.
		 * @param array    $context Additional composition context. Keys:
		 *                          `strategy` (string), `thread_index` (int),
		 *                          `is_thread_reply` (bool),
		 *                          `is_custom_text` (bool).
		 */
		$filtered = \apply_filters(
			'atmosphere_transform_bsky_post',
			$record,
			$this->object,
			array(
				'strategy'        => $is_short ? 'short-form' : 'link-card',
				'thread_index'    => 0,
				'is_thread_reply' => false,
				'is_custom_text'  => $custom,
			)
		);

		if ( ! \is_array( $filtered ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_transform_bsky_post must return an array; falling back to the unfiltered record.', 'atmosphere' ),
				'1.0.0'
			);
			return $record;
		}

		return $filtered;
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
		/*
		 * Persist DID provenance on every call, not only on first
		 * reservation. After a disconnect+reconnect-to-different-DID,
		 * `META_TID` already exists from the prior account, so a
		 * one-shot reservation guard would never refresh `META_DID`
		 * to the current account — letting the mismatch guard in
		 * `delete_post()` later block a legitimate cleanup against
		 * the current account.
		 *
		 * Written BEFORE `META_TID` so a partial-failure between the
		 * two writes leaves the row in "DID set, no TID" state. The
		 * cleanup gates skip that state cleanly; the inverse ("TID
		 * set, no DID") would let the mismatch guard fall through to
		 * `get_did()` and re-open the wrong-repo-delete bypass.
		 *
		 * Compare before writing so the read-path callers (the
		 * `wp_head` document-link renderer) don't issue a DB write on
		 * every pageload — only on the actual transition.
		 */
		$current_did = \Atmosphere\get_did();
		$stored_did  = (string) \get_post_meta( $this->object->ID, self::META_DID, true );
		if ( $stored_did !== $current_did ) {
			\update_post_meta( $this->object->ID, self::META_DID, $current_did );
		}

		$rkey = \get_post_meta( $this->object->ID, self::META_TID, true );

		if ( empty( $rkey ) ) {
			$rkey = $this->original_time ? $this->historical_rkey() : TID::generate();
			\update_post_meta( $this->object->ID, self::META_TID, $rkey );
		}

		return $rkey;
	}

	/**
	 * Mint the rkey for a thread reply at the given index.
	 *
	 * Publisher calls this for each non-root entry in a teaser thread so
	 * reply keys honor `--original-time`: when original-time minting is
	 * on the reply is dated just after the root within the same second;
	 * otherwise a fresh live TID is used. Not persisted here — replies
	 * are tracked in `META_THREAD_RECORDS` by Publisher.
	 *
	 * @param int $index Reply index within the thread (>= 1).
	 * @return string
	 */
	public function mint_reply_rkey( int $index ): string {
		return $this->original_time ? $this->historical_rkey( $index ) : TID::generate();
	}

	/**
	 * Compose the post text: title + excerpt + permalink within 300 characters.
	 *
	 * @return string
	 */
	private function build_text(): string {
		if ( $this->is_redacted() ) {
			return '';
		}

		$title     = sanitize_text( \get_the_title( $this->object ) );
		$excerpt   = $this->get_excerpt( $this->object );
		$permalink = \get_permalink( $this->object );

		$parts = \array_filter( array( $title, $excerpt, $permalink ) );
		$text  = \implode( "\n\n", $parts );

		if ( grapheme_length( $text ) <= self::BLUESKY_MAX_GRAPHEMES ) {
			return $this->carry_body_mentions( $text, $permalink );
		}

		/*
		 * Reserve space for the permalink plus the one "\n\n" separator that
		 * joins it to the prose below (the title/excerpt separator is already
		 * inside $prose).
		 */
		$reserved  = grapheme_length( $permalink ) + 2;
		$available = self::BLUESKY_MAX_GRAPHEMES - $reserved;

		if ( $available <= 0 ) {
			$prose = \trim( $title . ( ! empty( $excerpt ) ? "\n\n" . $excerpt : '' ) );

			return '' !== $prose ? truncate_text( $prose, self::BLUESKY_MAX_GRAPHEMES ) : truncate_text( $permalink, self::BLUESKY_MAX_GRAPHEMES );
		}

		$prose = $title;
		if ( ! empty( $excerpt ) ) {
			$prose .= "\n\n" . $excerpt;
		}

		$prose = truncate_text( $prose, $available );

		return $this->carry_body_mentions( $prose . "\n\n" . $permalink, $permalink );
	}

	/**
	 * Resolvable `@handle.tld` mentions found in the post body.
	 *
	 * Returns a map of handle => DID, first-appearance order. Empty for
	 * redacted posts.
	 *
	 * The body is scanned as HTML through {@see Mention::classify_handles()},
	 * which shares the display linkifier's tokenizer, so only handles the front
	 * end would actually link are collected — a handle inside a `<code>` sample
	 * or an existing `<a href>` link is *not*. Otherwise publish and display
	 * would disagree: a handle the linkifier leaves as plain text would still be
	 * carried into the Bluesky post and mint a `#mention` facet + notification.
	 *
	 * In projection mode the preview must not resolve mentions over DNS (see
	 * {@see self::$projecting}), but the carry-over still lengthens the composed
	 * record at publish. So the preview returns the *syntactic* linkable
	 * handles (no lookups) with empty DIDs — an upper bound, since some may not
	 * resolve — so the carry-over sizing, and therefore the reported grapheme
	 * count, never under-reports the record the publisher will write.
	 *
	 * @return array<string,string>
	 */
	private function body_mentions(): array {
		if ( $this->is_redacted() ) {
			return array();
		}

		$linkable = $this->classified_body_handles()['linkable'];

		if ( empty( $linkable ) ) {
			return array();
		}

		if ( $this->projecting ) {
			return \array_fill_keys( \array_values( $linkable ), '' );
		}

		return Facet::resolve_handle_list( \array_values( $linkable ) );
	}

	/**
	 * Classify the post body's @mentions once per transform.
	 *
	 * Memoizes {@see Mention::classify_handles()} over the rendered body so the
	 * carry-over ({@see self::body_mentions()}) and the publish-time mention
	 * deny-set ({@see self::blocked_mention_handles()}) share one HTML walk.
	 *
	 * @return array{linkable:array<string,string>,protected:array<string,true>}
	 */
	private function classified_body_handles(): array {
		if ( null === $this->classified_body_handles ) {
			$this->classified_body_handles = Mention::classify_handles(
				$this->render_post_content_html( $this->object )
			);
		}

		return $this->classified_body_handles;
	}

	/**
	 * Handles that must never mint a `#mention` facet for this post.
	 *
	 * The set of body handles that appear *only* inside a protected region (a
	 * `<code>`/`<pre>` sample or an existing `<a href>` link) and nowhere the
	 * front end would linkify. {@see Facet::extract()} skips these, so a handle
	 * buried in a code sample never notifies anyone even when it leaks into the
	 * excerpt — keeping every record's `#mention` facets in lockstep with what
	 * the site's own page renders as a link.
	 *
	 * @return array<string,true> Lowercased handles.
	 */
	private function blocked_mention_handles(): array {
		$classified = $this->classified_body_handles();

		$blocked = array();
		foreach ( $classified['protected'] as $key => $unused ) {
			if ( ! isset( $classified['linkable'][ $key ] ) ) {
				$blocked[ $key ] = true;
			}
		}

		return $blocked;
	}

	/**
	 * Whether this post's records may resolve and mint `#mention` facets.
	 *
	 * False for a redacted post (no body to mention from) and for a body so
	 * large the linkifier bails ({@see Mention::the_content()} leaves >1 MB
	 * content unlinked), so the publish path mints nothing the front end would
	 * not have linked.
	 *
	 * @return bool
	 */
	private function mentions_enabled(): bool {
		return ! $this->is_redacted()
			&& \strlen( $this->render_post_content_html( $this->object ) ) <= MB_IN_BYTES;
	}

	/**
	 * Carry resolvable body @mentions into a long-form post text.
	 *
	 * No-op when the post has no resolvable body mentions, so a mention-free
	 * record composes byte-identically to the un-carried text. Otherwise the
	 * resolvable body handles not already present in the text are appended as
	 * a single space-separated line placed immediately before the trailing
	 * permalink, so {@see Facet::extract()} attaches a `#mention` facet and
	 * Bluesky notifies the mentioned accounts even when the mention lived deep
	 * in the post body.
	 *
	 * The permalink is preserved in full (it is the load-bearing link); as
	 * many handles as fit are kept; the prose shrinks last to stay within the
	 * 300-grapheme cap. Shrinking the prose can truncate away a handle that was
	 * visible before the carry line was added, so the fit is computed to a
	 * fixpoint: any such handle is pulled into the carried line rather than
	 * silently lost. Handles that still don't fit are dropped and logged.
	 *
	 * @param string $text      Composed post text (may end with `\n\n$permalink`).
	 * @param string $permalink Post permalink, or '' when the text carries no
	 *                          trailing link.
	 * @return string
	 */
	private function carry_body_mentions( string $text, string $permalink ): string {
		$handles = $this->body_mentions();
		if ( empty( $handles ) ) {
			return $text;
		}

		$sep = "\n\n";
		$max = self::BLUESKY_MAX_GRAPHEMES;

		// Peel a trailing permalink off the prose so the mention line lands
		// before it.
		$suffix = '';
		$prose  = $text;
		if ( '' !== $permalink && \str_ends_with( $text, $sep . $permalink ) ) {
			$suffix = $sep . $permalink;
			$prose  = \substr( $text, 0, \strlen( $text ) - \strlen( $suffix ) );
		} elseif ( '' !== $permalink && $text === $permalink ) {
			// Carry the separator with the permalink so the mention line lands
			// before it with a `\n\n` gap; without it the kept handle glues
			// straight onto the URL (`@handle.tldhttps://…`), which over-extends
			// MENTION_PATTERN and drops the #mention facet entirely.
			$suffix = $sep . $permalink;
			$prose  = '';
		}

		// Every resolvable body handle, as an `@mention`, keyed by lowercased
		// handle so membership tests compare on token boundaries, not by
		// substring: a plain `mb_stripos` would treat `@alice.com` as present
		// inside `@alice.company` and silently skip carrying (and notifying) it.
		$all = array();
		foreach ( $handles as $handle => $did ) {
			$all[ \strtolower( $handle ) ] = '@' . $handle;
		}

		$suffix_len  = grapheme_length( $suffix );
		$kept        = '';
		$prose_final = $prose;
		$carried     = array(); // Lowercased handles now in the carried line.
		$dropped     = array(); // Lowercased handles that fit nowhere.

		/*
		 * Fit handles into a carried line, then shrink the prose to make room —
		 * but which handles need carrying depends on which survive in the
		 * shrunken prose, and that depends on how long the carried line is.
		 * Iterate to a fixpoint: a handle assumed visible in the prose but then
		 * truncated away is pulled into the carried line on the next pass, so a
		 * mention is never silently lost between the presence check and the
		 * truncation. Each pass only shrinks the prose, so it converges in at
		 * most one pass per handle.
		 */
		for ( $pass = 0, $limit = \count( $all ) + 1; $pass <= $limit; $pass++ ) {
			$present = Facet::handles_in( $prose_final );

			$need = array();
			foreach ( $all as $key => $mention ) {
				// Skip handles already visible in the surviving prose, already
				// carried, or already given up on.
				if ( isset( $present[ $key ] ) || isset( $carried[ $key ] ) || isset( $dropped[ $key ] ) ) {
					continue;
				}
				$need[ $key ] = $mention;
			}

			if ( empty( $need ) ) {
				break;
			}

			// Greedily add the needed handles to the carried line. A handle that
			// cannot fit even against an empty prose is dropped (and logged).
			foreach ( $need as $key => $mention ) {
				$candidate = '' === $kept ? $mention : $kept . ' ' . $mention;
				// Worst case needs a separator before the line; reserve one.
				if ( grapheme_length( $candidate ) + grapheme_length( $sep ) + $suffix_len > $max ) {
					$dropped[ $key ] = true;
					continue;
				}
				$kept            = $candidate;
				$carried[ $key ] = true;
			}

			if ( '' === $kept ) {
				$prose_final = $prose;
				break;
			}

			// Reshrink the prose to whatever room the carried line leaves.
			$line_sep     = '' !== $prose ? grapheme_length( $sep ) : 0;
			$prose_budget = $max - grapheme_length( $kept ) - $line_sep - $suffix_len;

			if ( $prose_budget <= 0 ) {
				$prose_final = '';
			} elseif ( grapheme_length( $prose ) > $prose_budget ) {
				$prose_final = truncate_text( $prose, $prose_budget );
			} else {
				$prose_final = $prose;
			}
		}

		if ( ! empty( $dropped ) ) {
			debug_log(
				\sprintf(
					'post %d: %d body mention(s) dropped from the Bluesky post — no room within the %d-character limit',
					$this->object->ID,
					\count( $dropped ),
					$max
				)
			);
		}

		if ( '' === $kept ) {
			return $text;
		}

		$head = '' !== $prose_final ? $prose_final . $sep : '';

		return $head . $kept . $suffix;
	}

	/**
	 * Prepend resolvable body @mentions absent from a teaser thread into its
	 * terminal CTA entry, before the permalink.
	 *
	 * A mention already shipping in any thread entry (hook or body chunk)
	 * already notifies, so only handles absent from every entry are carried.
	 * They are prepended to the CTA (the entry that holds the permalink),
	 * dropping any that don't fit the 300-grapheme cap; the CTA text is never
	 * trimmed. No-op when the post has no resolvable body mentions.
	 *
	 * @param string[] $texts Thread entry texts, in order (CTA last).
	 * @return string[]
	 */
	private function carry_mentions_into_teaser( array $texts ): array {
		$handles = $this->body_mentions();
		if ( empty( $handles ) || \count( $texts ) < 1 ) {
			return $texts;
		}

		$shipped = \implode( "\n", $texts );

		// Token-boundary membership, not substring: see carry_body_mentions().
		$present = Facet::handles_in( $shipped );
		$missing = array();
		foreach ( $handles as $handle => $did ) {
			if ( ! isset( $present[ \strtolower( $handle ) ] ) ) {
				$missing[] = '@' . $handle;
			}
		}
		if ( empty( $missing ) ) {
			return $texts;
		}

		$last = \count( $texts ) - 1;
		$cta  = $texts[ $last ];
		$sep  = "\n\n";
		$room = self::BLUESKY_MAX_GRAPHEMES - grapheme_length( $cta ) - grapheme_length( $sep );

		$kept    = '';
		$dropped = 0;
		foreach ( $missing as $mention ) {
			$candidate = '' === $kept ? $mention : $kept . ' ' . $mention;
			// Skip this handle rather than stop: a longer handle may not fit
			// where a later, shorter one still would.
			if ( grapheme_length( $candidate ) > $room ) {
				++$dropped;
				continue;
			}
			$kept = $candidate;
		}

		if ( $dropped > 0 ) {
			debug_log(
				\sprintf(
					'post %d: %d body mention(s) dropped from the Bluesky teaser thread — no room within the %d-character limit',
					$this->object->ID,
					$dropped,
					self::BLUESKY_MAX_GRAPHEMES
				)
			);
		}

		if ( '' === $kept ) {
			return $texts;
		}

		$texts[ $last ] = $kept . $sep . $cta;

		return $texts;
	}

	/**
	 * Build an `app.bsky.embed.images` record from the post's images.
	 *
	 * Source priority:
	 *   1. `core/image` blocks parsed from `post_content` (deduped,
	 *      document order, capped at 4).
	 *   2. Featured image (`get_post_thumbnail_id`) when no in-body
	 *      images are found.
	 *
	 * Returns null when neither source yields an image, when every
	 * attempted blob upload fails, or for redacted posts. Partial upload
	 * failures are silently skipped; the record ships with whatever
	 * uploaded successfully. Used by the
	 * short-form `transform()` path so aside/status/quote posts that
	 * contain images actually ship them to Bluesky instead of silently
	 * dropping them with the post content's HTML.
	 *
	 * @return array|null app.bsky.embed.images record or null.
	 */
	private function build_images_embed(): ?array {
		if ( $this->is_redacted() ) {
			return null;
		}

		$attachment_ids = $this->collect_image_attachment_ids();

		if ( empty( $attachment_ids ) ) {
			$thumb_id = \get_post_thumbnail_id( $this->object );
			if ( $thumb_id ) {
				$attachment_ids[] = (int) $thumb_id;
			}
		}

		if ( empty( $attachment_ids ) ) {
			return null;
		}

		// AT Protocol `app.bsky.embed.images` caps at 4 images.
		$attachment_ids = \array_slice( $attachment_ids, 0, 4 );

		$images = array();
		foreach ( $attachment_ids as $attachment_id ) {
			/*
			 * In projection mode, stand in a placeholder blob rather than
			 * uploading. The preview only reads record text, but the embed
			 * must stay non-null so `transform()` keeps selecting the
			 * short-form body text instead of falling through to the
			 * link-card path — matching what a real publish would produce.
			 */
			$blob = $this->projecting
				? array( '$type' => 'blob' )
				: self::upload_image_blob( $attachment_id );
			if ( ! $blob ) {
				continue;
			}

			$image = array(
				'image' => $blob,
				'alt'   => $this->image_alt_text( $attachment_id ),
			);

			$aspect_ratio = self::get_attachment_aspect_ratio( $attachment_id );
			if ( null !== $aspect_ratio ) {
				$image['aspectRatio'] = $aspect_ratio;
			}

			$images[] = $image;
		}

		if ( empty( $images ) ) {
			return null;
		}

		return array(
			'$type'  => 'app.bsky.embed.images',
			'images' => $images,
		);
	}

	/**
	 * Collect attachment IDs from `core/image` blocks in post_content.
	 *
	 * Walks the block tree recursively (into `innerBlocks`) so an image
	 * nested in a group, column, or cover block is still picked up.
	 * Order is document order; duplicates are removed; non-positive IDs
	 * are skipped. Only `core/image` blocks are inspected — `core/cover`
	 * background images and `core/media-text` images are intentionally
	 * out of scope; consumers needing those can wire them in via the
	 * `atmosphere_post_embed` filter.
	 *
	 * The walker stops collecting once 32 IDs have been gathered — well
	 * above the 4-image AT Protocol cap, but enough headroom that dedupe
	 * still preserves document order on realistic posts. Bounds the
	 * memory profile so an attacker-controlled `post_content` packed with
	 * thousands of `core/image` blocks can't grow the working array
	 * past a constant ceiling.
	 *
	 * Recursion is also depth-capped at 16 levels. The 32-ID breadth cap
	 * only protects against wide trees: a deeply-nested input with no
	 * images (e.g. 500 nested `core/group` wrappers) would never
	 * accumulate IDs and never trip the breadth guard, but each level
	 * still costs a PHP frame on the C stack. 16 leaves ample headroom
	 * over realistic theme/block nesting (cover → group → columns →
	 * column → group → image is six) while keeping the worst-case stack
	 * use bounded against an adversarial `post_content`.
	 *
	 * @return int[]
	 */
	private function collect_image_attachment_ids(): array {
		$content = get_publishable_content( $this->object );

		if ( '' === $content || ! \has_blocks( $content ) ) {
			return array();
		}

		$blocks = \parse_blocks( $content );
		$ids    = array();

		// Generous ceiling: well above the 4-image cap, enough that
		// dedupe still preserves document order on realistic posts,
		// small enough to bound attacker-controlled memory growth.
		$max_ids   = 32;
		$max_depth = 16;

		$walker = static function ( array $blocks, int $depth ) use ( &$walker, &$ids, $max_ids, $max_depth ): void {
			if ( $depth > $max_depth ) {
				return;
			}

			foreach ( $blocks as $block ) {
				if ( \count( $ids ) >= $max_ids ) {
					return;
				}

				if ( ( $block['blockName'] ?? '' ) === 'core/image'
					&& isset( $block['attrs']['id'] )
					&& (int) $block['attrs']['id'] > 0
				) {
					$ids[] = (int) $block['attrs']['id'];
				}

				if ( ! empty( $block['innerBlocks'] ) && \is_array( $block['innerBlocks'] ) ) {
					$walker( $block['innerBlocks'], $depth + 1 );
				}
			}
		};

		$walker( $blocks, 0 );

		return \array_values( \array_unique( $ids ) );
	}

	/**
	 * Resolve the alt text for an attachment.
	 *
	 * Uses the WordPress canonical attachment alt meta key
	 * (`_wp_attachment_image_alt`). Returns an empty string when the meta
	 * is missing or non-string — AT Protocol's `app.bsky.embed.images`
	 * requires the `alt` field to be present, and an empty string is a
	 * valid value.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function image_alt_text( int $attachment_id ): string {
		$alt = \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return \is_string( $alt ) ? truncate_text( sanitize_text( $alt ), 1000 ) : '';
	}

	/**
	 * Build an app.bsky.embed.external card.
	 *
	 * @return array|null
	 */
	private function build_embed(): ?array {
		if ( $this->is_redacted() ) {
			return null;
		}

		$permalink   = \get_permalink( $this->object );
		$title       = sanitize_text( \get_the_title( $this->object ) );
		$description = $this->get_excerpt( $this->object, 55 );

		$external = array(
			'uri'         => $permalink,
			'title'       => $title,
			'description' => $description,
		);

		$thumb_id = \get_post_thumbnail_id( $this->object );
		if ( $thumb_id && ! $this->projecting ) {
			$blob = self::upload_thumbnail( $thumb_id );
			if ( $blob ) {
				$external['thumb'] = $blob;
			}
		}

		/*
		 * Build the `associatedRefs` array. Order: publication first,
		 * document second — matches what Bluesky's manual-share UI
		 * emits and keeps the test fixtures stable. Lexicon does not
		 * mandate ordering, but pinning a deterministic order avoids
		 * spurious CID drift on no-op republishes.
		 *
		 * Publication ref comes from a stored site-wide option, set
		 * by `Publisher::sync_publication()` once the publication
		 * record has been written.
		 *
		 * Document ref has two sources:
		 *   - The Publisher precomputes the document's CID locally via
		 *     DAG-CBOR and injects via `set_document_strong_ref()`.
		 *     Without this, the document ref could only be added after
		 *     the atomic write returned — and Bluesky's AppView ignores
		 *     subsequent `applyWrites#update` for the purposes of
		 *     indexing `source` / `associatedProfiles` enrichment.
		 *   - Read-only or legacy paths may omit injection and fall back
		 *     to `Document::META_*`, which represents the last document
		 *     record known to have been written.
		 *
		 * The injection wins if both sources are present — it
		 * reflects what the Publisher is *about* to write, the meta
		 * reflects the previous write.
		 */
		$associated_refs = array();

		$publication_ref = Publication::get_strong_ref();
		if ( null !== $publication_ref ) {
			$associated_refs[] = $publication_ref;
		}

		if ( null !== $this->document_strong_ref ) {
			$associated_refs[] = $this->document_strong_ref;
		} elseif ( $this->document_meta_strong_ref_enabled ) {
			$doc_uri = (string) \get_post_meta( $this->object->ID, Document::META_URI, true );
			$doc_cid = (string) \get_post_meta( $this->object->ID, Document::META_CID, true );
			if ( '' !== $doc_uri && '' !== $doc_cid ) {
				$associated_refs[] = array(
					'$type' => 'com.atproto.repo.strongRef',
					'uri'   => $doc_uri,
					'cid'   => $doc_cid,
				);
			}
		}

		if ( ! empty( $associated_refs ) ) {
			$external['associatedRefs'] = $associated_refs;
		}

		return array(
			'$type'    => 'app.bsky.embed.external',
			'external' => $external,
		);
	}

	/**
	 * When true, {@see self::upload_image_blob()} ignores the cached blob
	 * ref and re-uploads. Set by the Publisher's self-heal retry; see
	 * {@see \Atmosphere\Publisher::publish_post()} for why a cached ref
	 * goes stale.
	 *
	 * @var bool
	 */
	private static bool $force_blob_reupload = false;

	/**
	 * Force, or stop forcing, blob re-upload on subsequent uploads.
	 *
	 * @since unreleased
	 *
	 * @param bool $force Whether to bypass the blob-ref cache.
	 * @return void
	 */
	public static function set_force_blob_reupload( bool $force ): void {
		self::$force_blob_reupload = $force;
	}

	/**
	 * Upload an image attachment and return the blob reference.
	 *
	 * Used for any image that needs to land on the PDS — featured-image
	 * thumbnails for link cards, publication icons, and (downstream)
	 * native `app.bsky.embed.images` attachments. Blob refs are cached
	 * in `_atmosphere_blob_ref` postmeta so a re-publish of the same
	 * attachment skips the upload.
	 *
	 * If the original file exceeds AT Protocol's 1 MB blob cap, falls
	 * back to a smaller intermediate size. When no readable local file
	 * is available — e.g. offloaded media on WordPress.com,
	 * where intermediate sizes are virtual and never hit local disk —
	 * the image is fetched over HTTP from its attachment URL instead.
	 * Returns null only when no candidate under the cap can be obtained.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Blob reference or null.
	 */
	public static function upload_image_blob( int $attachment_id ): ?array {
		/*
		 * Check the cache first, unless a self-heal retry is forcing a
		 * re-upload. Forcing bypasses the cache for every image a publish
		 * touches — featured thumbnail, in-body images, publication icon,
		 * content-parser images alike — so a stale ref is replaced whichever
		 * image carried it, with no need to enumerate the upload set.
		 */
		if ( ! self::$force_blob_reupload ) {
			$cached = self::cached_image_blob( $attachment_id );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		$mime = \get_post_mime_type( $attachment_id );
		if ( ! $mime ) {
			return null;
		}

		/*
		 * Resolve a file under the 1 MB cap: a readable local file when
		 * one exists, otherwise a temp file fetched from the CDN.
		 */
		list( $file, $is_temp, $upload_mime ) = self::resolve_uploadable_image( $attachment_id, $mime );

		if ( null === $file ) {
			debug_log(
				\sprintf(
					'could not resolve an uploadable image for attachment %d (no readable local file and no fetchable size URL under the 1 MB cap); the image blob will be omitted',
					$attachment_id
				)
			);
			return null;
		}

		/*
		 * Clean up a fetched temp file in `finally` so it can't leak if
		 * `upload_blob` (or an `atmosphere_pre_upload_blob` filter) throws.
		 */
		try {
			$result = API::upload_blob( $file, $upload_mime );
		} finally {
			if ( $is_temp ) {
				\wp_delete_file( $file );
			}
		}

		if ( \is_wp_error( $result ) ) {
			self::log_image_blob_upload_error( $attachment_id, $result );
			return null;
		}

		$blob_ref = $result['blob'] ?? null;
		if ( $blob_ref ) {
			\update_post_meta( $attachment_id, '_atmosphere_blob_ref', $blob_ref );
		}

		return $blob_ref;
	}

	/**
	 * Resolve a file path under the blob-size cap, ready to upload.
	 *
	 * Tries the local filesystem first (fast path), then falls back to
	 * fetching the image over HTTP. The second element of the return
	 * tuple flags a temp file the caller must delete after uploading.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $mime          Attachment MIME type.
	 * @return array{0:?string,1:bool,2:?string} `[ $path, $is_temp, $mime ]`; `[ null, false, null ]` on failure.
	 */
	private static function resolve_uploadable_image( int $attachment_id, string $mime ): array {
		$local = self::resolve_local_image( $attachment_id, $mime );
		if ( null !== $local ) {
			return array( $local['path'], false, $local['mime'] );
		}

		$remote = self::fetch_remote_image_to_temp( $attachment_id );
		if ( null !== $remote ) {
			return array( $remote['path'], true, $remote['mime'] );
		}

		return array( null, false, null );
	}

	/**
	 * Find a readable local image file under the blob-size cap.
	 *
	 * Checks readability *before* `filesize()` so an unreadable path
	 * (a virtual/offloaded intermediate) can't trip a stat warning.
	 * Tries the original path first, then every generated size in
	 * attachment metadata from largest to smallest — for local files the
	 * only constraint is the cap, so highest quality under it wins (no
	 * fetch cost to weigh, unlike the remote path which defers the
	 * full-size original to last). Each candidate carries its own MIME so
	 * a sub-size a plugin transcoded to another format (e.g. WebP) isn't
	 * uploaded under the original's MIME.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $mime          Attachment MIME type, used for the original file.
	 * @return array{path:string,mime:string}|null Readable local file under the cap, or null.
	 */
	private static function resolve_local_image( int $attachment_id, string $mime ): ?array {
		$file       = \get_attached_file( $attachment_id );
		$upload_dir = \wp_upload_dir();

		// Keyed by path so duplicate paths collapse while iteration order
		// (original first, then sizes largest-to-smallest) is preserved.
		$candidates = array();

		if ( $file ) {
			$candidates[ $file ] = $mime;
		}

		foreach ( self::get_image_size_candidates( $attachment_id ) as $size ) {
			$resized = \image_get_intermediate_size( $attachment_id, $size );
			if ( $resized && ! empty( $resized['path'] ) ) {
				$path = $upload_dir['basedir'] . '/' . $resized['path'];
				// Prefer the size's own recorded MIME; fall back to the
				// attachment MIME when metadata doesn't carry one.
				$candidates[ $path ] = empty( $resized['mime-type'] ) ? $mime : $resized['mime-type'];
			}
		}

		foreach ( $candidates as $candidate => $candidate_mime ) {
			if ( ! \is_readable( $candidate ) ) {
				continue;
			}

			/*
			 * `filesize()` returns false on a stat failure (some stream
			 * wrappers, race with deletion). `false <= MAX` would coerce to
			 * `0 <= MAX` and wrongly accept an unknown-size file, so fail
			 * closed: skip the candidate when the size can't be read.
			 */
			$size = \filesize( $candidate );
			if ( false !== $size && $size <= self::MAX_BLOB_BYTES ) {
				return array(
					'path' => $candidate,
					'mime' => $candidate_mime,
				);
			}
		}

		return null;
	}

	/**
	 * Return generated image size names ordered from largest to smallest.
	 *
	 * Uses attachment metadata so custom/intermediate sizes registered by
	 * WordPress or third-party code are considered alongside core sizes.
	 * Known core names are appended as a fallback for sparse metadata.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return string[] Image size names.
	 */
	private static function get_image_size_candidates( int $attachment_id ): array {
		$metadata = \wp_get_attachment_metadata( $attachment_id );
		$sizes    = array();

		if ( \is_array( $metadata ) && ! empty( $metadata['sizes'] ) && \is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $details ) {
				if ( ! \is_string( $name ) || '' === $name || ! \is_array( $details ) ) {
					continue;
				}

				$width          = isset( $details['width'] ) && \is_numeric( $details['width'] ) ? (int) $details['width'] : 0;
				$height         = isset( $details['height'] ) && \is_numeric( $details['height'] ) ? (int) $details['height'] : 0;
				$sizes[ $name ] = $width * $height;
			}
		}

		\arsort( $sizes, \SORT_NUMERIC );

		$candidates = \array_keys( $sizes );
		foreach ( array( '2048x2048', '1536x1536', 'large', 'medium_large', 'medium', 'thumbnail' ) as $size ) {
			if ( ! \in_array( $size, $candidates, true ) ) {
				$candidates[] = $size;
			}
		}

		return $candidates;
	}

	/**
	 * Fetch an image from its attachment URL into a temp file.
	 *
	 * For offloaded-media hosts (WordPress.com, WP Offload
	 * Media, etc.) the resized files don't exist on local disk, so we
	 * fetch them over HTTP. Candidate sizes are tried largest-first,
	 * and the first response that is an image under the cap wins. The
	 * URLs come from the site's own attachment metadata — not user
	 * input — and `wp_safe_remote_get()` blocks internal hosts, so the
	 * SSRF surface is minimal.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array{path:string,mime:string}|null Temp file path and MIME type, or null.
	 */
	private static function fetch_remote_image_to_temp( int $attachment_id ): ?array {
		// Resolve the full-size URL up front so we can keep it out of the
		// intermediate candidates: when a requested size hasn't been
		// generated, `wp_get_attachment_image_url()` falls back to the
		// full-size URL, which would otherwise jump the queue and force a
		// wasted download of the (likely oversized) original before the
		// smaller sizes are tried.
		$full = \wp_get_attachment_url( $attachment_id );

		$urls = array();
		foreach ( self::get_image_size_candidates( $attachment_id ) as $size ) {
			$url = \wp_get_attachment_image_url( $attachment_id, $size );
			if ( $url && $url !== $full ) {
				$urls[ $url ] = true;
			}
		}
		// Full-size original last, in case it's already under the cap.
		if ( $full ) {
			$urls[ $full ] = true;
		}

		/*
		 * Cap the in-memory buffer at one byte over the blob limit. A
		 * response truncated exactly at the cap would pass the size check
		 * below as a corrupt image; the +1 lets an at-or-over-cap body
		 * register as oversized and be rejected, while a body exactly at
		 * the cap still downloads in full.
		 */
		$get_args = array(
			'timeout'             => 15,
			'limit_response_size' => self::MAX_BLOB_BYTES + 1,
		);

		foreach ( \array_keys( $urls ) as $url ) {
			$response = \wp_safe_remote_get( $url, $get_args );

			if ( \is_wp_error( $response ) || 200 !== \wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			/*
			 * Cheap pre-filter on the header before buffering the body.
			 * The value isn't case-normalised by WP — a CDN may send
			 * `Image/JPEG` — so normalise before comparing.
			 */
			$content_type = self::normalize_image_content_type( \wp_remote_retrieve_header( $response, 'content-type' ) );
			if ( 0 !== \strpos( $content_type, 'image/' ) ) {
				continue;
			}

			$body = \wp_remote_retrieve_body( $response );
			$size = \strlen( $body );
			if ( 0 === $size || $size > self::MAX_BLOB_BYTES ) {
				continue;
			}

			/*
			 * Derive the temp-file hint from the URL path only — CDN /
			 * offload URLs often carry a query string, and the raw
			 * basename (`photo.jpg?w=769`) makes a messy/invalid filename.
			 */
			$path_part = (string) \wp_parse_url( $url, \PHP_URL_PATH );
			$hint      = '' !== $path_part ? \basename( $path_part ) : 'image';
			$temp      = \wp_tempnam( $hint );
			if ( ! $temp ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === \file_put_contents( $temp, $body ) ) {
				\wp_delete_file( $temp );
				continue;
			}

			/*
			 * Validate the bytes, not just the header: a misconfigured or
			 * compromised CDN could serve arbitrary bytes labelled
			 * `image/*`. Derive the blob MIME from the verified bytes so
			 * what we upload always matches what was actually fetched.
			 */
			$mime = self::verified_image_mime( $temp );
			if ( null === $mime ) {
				\wp_delete_file( $temp );
				continue;
			}

			return array(
				'path' => $temp,
				'mime' => $mime,
			);
		}

		return null;
	}

	/**
	 * Verify a file is a supported raster image and return its MIME type.
	 *
	 * Inspects the actual bytes via `wp_getimagesize()` rather than
	 * trusting a caller-supplied or header-supplied type, and restricts
	 * the result to the formats AT Protocol image blobs accept.
	 *
	 * @param string $path Local file path.
	 * @return string|null Detected MIME type, or null when not a supported image.
	 */
	private static function verified_image_mime( string $path ): ?string {
		$info = \wp_getimagesize( $path );
		if ( ! \is_array( $info ) || empty( $info[2] ) ) {
			return null;
		}

		$allowed = array(
			\IMAGETYPE_JPEG => 'image/jpeg',
			\IMAGETYPE_PNG  => 'image/png',
			\IMAGETYPE_GIF  => 'image/gif',
			\IMAGETYPE_WEBP => 'image/webp',
		);

		return $allowed[ $info[2] ] ?? null;
	}

	/**
	 * Normalize an HTTP Content-Type header for blob upload.
	 *
	 * @param mixed $content_type Raw response header value.
	 * @return string Lowercase MIME type without parameters.
	 */
	private static function normalize_image_content_type( mixed $content_type ): string {
		if ( \is_array( $content_type ) ) {
			$content_type = \reset( $content_type );
		}

		$parts = \explode( ';', (string) $content_type );

		return \strtolower( \trim( $parts[0] ) );
	}

	/**
	 * Upload a thumbnail image and return the blob reference.
	 *
	 * Alias of `upload_image_blob()` retained so existing callers
	 * (Publication icons, Document thumbnails, third-party integrations)
	 * keep working. New call sites should prefer `upload_image_blob()`
	 * for clarity — the body is attachment-agnostic and works for any
	 * image, not just post thumbnails.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Blob reference or null.
	 */
	public static function upload_thumbnail( int $attachment_id ): ?array {
		return self::upload_image_blob( $attachment_id );
	}

	/**
	 * Read a previously-uploaded image blob ref from cache, never uploading.
	 *
	 * Read-only companion to {@see self::upload_image_blob()} for preview
	 * projections: a blob that already landed on the PDS is reused (so the
	 * projected record matches what a publish would write), while an
	 * uncached image yields null instead of a network upload and a meta
	 * write from a GET request.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Cached blob reference or null.
	 */
	public static function cached_image_blob( int $attachment_id ): ?array {
		$cached = \get_post_meta( $attachment_id, '_atmosphere_blob_ref', true );

		return empty( $cached ) ? null : $cached;
	}

	/**
	 * Log an image blob upload failure before returning null to callers.
	 *
	 * Callers intentionally degrade differently (skip image, hotlink the
	 * origin URL, or omit a cover image), so this is the common point
	 * where transient PDS/auth/network failures stay visible.
	 *
	 * @param int       $attachment_id Attachment ID.
	 * @param \WP_Error $error         Upload error.
	 * @return void
	 */
	private static function log_image_blob_upload_error( int $attachment_id, \WP_Error $error ): void {
		debug_log(
			\sprintf(
				'image blob upload failed for attachment %d: %s — %s',
				$attachment_id,
				$error->get_error_code(),
				$error->get_error_message()
			)
		);
	}

	/**
	 * Read an image attachment's intrinsic dimensions.
	 *
	 * Returns the integer width / height pair from
	 * `wp_get_attachment_metadata()`. The AT Protocol `app.bsky.embed.images`
	 * lexicon expects integer pixel values in its `aspectRatio` field, so
	 * callers can pass this dict through directly. Returns null when
	 * metadata is missing or non-numeric — typical for newly-uploaded
	 * attachments before WordPress has finished generating intermediates,
	 * or for non-image MIME types.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null `[ 'width' => int, 'height' => int ]` or null.
	 */
	public static function get_attachment_aspect_ratio( int $attachment_id ): ?array {
		$meta = \wp_get_attachment_metadata( $attachment_id );

		if ( ! \is_array( $meta ) ) {
			return null;
		}

		/*
		 * Validate with `is_numeric` BEFORE casting. The earlier shape
		 * did `(int) $meta['width']`, which silently accepts strings
		 * with a leading numeric prefix — `"1600px"` casts to `1600`
		 * and passes the `> 0` gate. A misbehaving third-party metadata
		 * filter could otherwise inject a unit-suffixed string and
		 * have it propagate into the AT Protocol record's
		 * `aspectRatio` field as a misleading integer. Requiring a
		 * pure numeric input matches the docblock's "non-numeric"
		 * contract.
		 */
		if ( ! isset( $meta['width'], $meta['height'] )
			|| ! \is_numeric( $meta['width'] )
			|| ! \is_numeric( $meta['height'] )
		) {
			return null;
		}

		$width  = (int) $meta['width'];
		$height = (int) $meta['height'];

		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Apply the `atmosphere_post_embed` filter to a candidate embed.
	 *
	 * Centralizes the filter call so every composition path —
	 * `transform()` (short-form and default), `record_for_link_card()`,
	 * and the two teaser-thread embed sites in `build_long_form_records()`
	 * — gives the same observable seam to downstream consumers.
	 *
	 * Valid filter returns: `null` (suppress the embed) or an array with
	 * a non-empty string `$type` key. Anything else — non-array, empty
	 * array, or array missing/with a non-string `$type` — is rejected
	 * with `_doing_it_wrong` and the pre-filter value is used. Failing
	 * loudly on half-formed returns keeps the three composition call
	 * sites consistent (all use `null !== $embed`) and protects the
	 * applyWrites batch from a malformed embed.
	 *
	 * @param array|null $embed    Default embed for this strategy
	 *                             (an `app.bsky.embed.images` record for
	 *                             short-form posts with images, null for
	 *                             short-form posts without images, an
	 *                             `app.bsky.embed.external` card for the
	 *                             link-card and teaser-thread strategies).
	 * @param string     $strategy Composition strategy: 'short-form',
	 *                             'link-card', or 'teaser-thread'.
	 * @return array|null Final embed to attach to the record, or null.
	 */
	private function apply_post_embed_filter( ?array $embed, string $strategy ): ?array {
		/**
		 * Filters the embed attached to a Bluesky post record.
		 *
		 * Fires for every composition strategy. The default for short-form
		 * posts is an `app.bsky.embed.images` record when the post has
		 * images (in-body `core/image` blocks, or the featured image as a
		 * fallback), and `null` otherwise. Consumers can:
		 *
		 *   - Replace the default external link card with a richer
		 *     embed type (`app.bsky.embed.images`, `app.bsky.embed.video`,
		 *     `app.bsky.embed.record`).
		 *   - Attach an embed to a short-form post that would otherwise
		 *     ship plain (e.g. an image-free aside).
		 *   - Suppress the default embed by returning null.
		 *
		 * Valid returns are `null` or an array with a non-empty string
		 * `$type` key. Non-array returns, empty arrays, and arrays
		 * without a non-empty string `$type` are rejected with
		 * `_doing_it_wrong` and the pre-filter value is restored —
		 * protects the applyWrites batch from a misbehaving listener
		 * and keeps every composition strategy treating half-formed
		 * returns the same way.
		 *
		 * The filter is called *after* the default embed has been
		 * built, so listeners can read the default before deciding to
		 * replace it (e.g. a photo-projector that wants to fall back to
		 * the external card when the post has zero image attachments).
		 *
		 * Not fired for redacted (password-protected) transforms — the
		 * record carries no text or tags in that branch and exposing the
		 * post object to embed filters would leak the protected payload.
		 *
		 * @param array|null $embed    Default embed for this strategy
		 *                             (an `app.bsky.embed.images` record
		 *                             for short-form posts with images,
		 *                             null for short-form posts without
		 *                             images, an `app.bsky.embed.external`
		 *                             card for the link-card and
		 *                             teaser-thread strategies).
		 * @param \WP_Post   $post     The post being transformed.
		 * @param string     $strategy Composition strategy: 'short-form',
		 *                             'link-card', or 'teaser-thread'.
		 */
		$filtered = \apply_filters( 'atmosphere_post_embed', $embed, $this->object, $strategy );

		if ( null === $filtered ) {
			return null;
		}

		if ( ! \is_array( $filtered ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_post_embed must return an array or null; falling back to the unfiltered embed.', 'atmosphere' ),
				'unreleased'
			);
			return $embed;
		}

		/*
		 * Reject empty arrays and arrays missing the `$type` key.
		 * Without this gate the three call sites disagreed: the
		 * `if ( $embed )` truthy checks in `transform()` and
		 * `record_for_link_card()` silently dropped an empty-array
		 * return, while `record_for_thread_entry()` used
		 * `null !== $embed` and attached the malformed embed to the
		 * record. Failing loudly here means every composition
		 * strategy treats a half-formed filter return the same way,
		 * and the call sites can use `null !== $embed` consistently.
		 */
		if ( empty( $filtered ) || empty( $filtered['$type'] ) || ! \is_string( $filtered['$type'] ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_post_embed must return an embed array with a non-empty $type string, or null; falling back to the unfiltered embed.', 'atmosphere' ),
				'unreleased'
			);
			return $embed;
		}

		return $filtered;
	}

	/**
	 * Whether the post should be treated as short-form for Bluesky.
	 *
	 * Starts from the ActivityPub plugin's Post::get_type() discriminator so
	 * a post federated as a Mastodon Note also goes to Bluesky as a native
	 * post instead of a link-card teaser. Categorically short-form when:
	 * - the post type does not support titles, OR
	 * - the post has an empty title, OR
	 * - the post has any non-empty post_format.
	 *
	 * A categorically short-form post is still treated as long-form when its
	 * body overflows Bluesky's 300-character native cap *and* it has no
	 * in-body images: short-form ships the body verbatim, so a body that
	 * cannot fit is not really "short", and routing it to the long-form
	 * composition (excerpt + permalink + external card) gives the reader a
	 * teaser plus a route back to the original instead of a sentence fragment
	 * with no link home. The overflow length is measured in graphemes to
	 * match `build_short_form_text()`'s own `truncate_text()` cap, so the gate
	 * and the truncation it avoids agree.
	 *
	 * An overflowing post that *does* carry in-body images stays short-form:
	 * the long-form link card can only show the featured thumbnail, so
	 * converting would silently drop the post's native `app.bsky.embed.images`
	 * gallery. A photo post with a long caption keeps its images and accepts
	 * the caption truncation; only the text-only link-blog case converts.
	 *
	 * @param \WP_Post $post Post being transformed.
	 * @return bool
	 */
	private function is_short_form( \WP_Post $post ): bool {
		if ( \post_type_supports( $post->post_type, 'title' ) && ! empty( $post->post_title ) && ! \get_post_format( $post ) ) {
			return false;
		}

		if ( grapheme_length( $this->render_post_content_plain( $post ) ) <= self::BLUESKY_MAX_GRAPHEMES ) {
			return true;
		}

		// Overflowing: only convert to long-form when there are no in-body
		// images to preserve as a native gallery.
		return ! empty( $this->collect_image_attachment_ids() );
	}

	/**
	 * Build the bsky.app post text and link facets for a short-form post.
	 *
	 * The post body becomes the Bluesky text directly, with no title prefix
	 * or trailing permalink, clamped to 300 characters. Inline links are
	 * preserved as `app.bsky.richtext.facet#link` facets over their
	 * human-readable anchor text, so a link-blog note keeps its links
	 * clickable on Bluesky instead of having the URLs silently stripped by
	 * `sanitize_text()` before facet extraction.
	 *
	 * @return array{text:string,facets:array} Text and its inline-link facets.
	 */
	private function build_short_form_text(): array {
		if ( $this->is_redacted() ) {
			return array(
				'text'   => '',
				'facets' => array(),
			);
		}

		$html = Mention::without_links(
			fn() => \apply_filters( 'the_content', get_publishable_content( $this->object ) ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
		);

		/*
		 * Fast path: no anchors, so the plain render is the whole story.
		 * Match `<a ` / `<a>` only, not `<article>` / `<aside>`. Sanitize the
		 * already-rendered $html rather than calling render_post_content_plain(),
		 * which would run `the_content` a second time — doubling any shortcode
		 * or filter side effect and risking text that differs from $html.
		 */
		if ( ! \preg_match( '/<a[\s>]/i', $html ) ) {
			return array(
				'text'   => truncate_text( sanitize_text( $html ), self::BLUESKY_MAX_GRAPHEMES ),
				'facets' => array(),
			);
		}

		list( $full_text, $facets ) = $this->resolve_inline_link_facets( $html );

		$ellipsis = '...';
		$text     = truncate_text( $full_text, self::BLUESKY_MAX_GRAPHEMES, $ellipsis );

		/*
		 * Drop facets that fall past the truncation point. `truncate_text()`
		 * appends the ellipsis marker when it cuts, so the surviving body is
		 * everything before that marker. A facet that merely straddles the
		 * cut (starts inside, ends past it) is dropped too — a half-range
		 * would mislink in Bluesky's renderer.
		 */
		if ( $text !== $full_text ) {
			$body_bytes = \strlen( $text ) - \strlen( $ellipsis );
			$facets     = \array_values(
				\array_filter(
					$facets,
					static fn( $facet ) => $facet['index']['byteEnd'] <= $body_bytes
				)
			);
		}

		return array(
			'text'   => $text,
			'facets' => $facets,
		);
	}

	/**
	 * Render content to plain text, preserving inline links as facets.
	 *
	 * Anchor text would otherwise be flattened by `sanitize_text()` before
	 * `Facet::extract()` runs, dropping the link. To keep both the readable
	 * anchor text and a precise link facet, each `<a>` is swapped for a
	 * unique synthetic marker before the text is normalized, then the marker
	 * is substituted back with the byte offsets recorded. Searching for a
	 * unique marker — rather than the anchor text itself — keeps the offsets
	 * correct even when the same word is linked twice, or appears as plain
	 * text elsewhere in the post.
	 *
	 * The anchor regex mirrors `Content_Parser\Markpub`'s link handling;
	 * `WP_HTML_Tag_Processor` reads attributes but not the text between an
	 * element's open and close tags, so it cannot recover the anchor text.
	 *
	 * Only `http(s)` anchors with non-empty text become facets; other hrefs
	 * (relative, `mailto:`, fragments) keep their text but carry no link.
	 *
	 * @param string $html Rendered post HTML (`the_content` output).
	 * @return array{0:string,1:array} `[ $text, $facets ]`.
	 */
	private function resolve_inline_link_facets( string $html ): array {
		$anchors = array();
		$index   = 0;

		/*
		 * `\shref=` requires whitespace before `href` so the value of another
		 * attribute (e.g. `data-href="…"`) is never mistaken for the link
		 * target — `\bhref=` would match after the hyphen.
		 */
		$marked_html = \preg_replace_callback(
			'#<a\b[^>]*\shref=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			function ( $matches ) use ( &$anchors, &$index ) {
				$raw_href    = \trim( \html_entity_decode( $matches[2], \ENT_QUOTES, 'UTF-8' ) );
				$anchor_text = sanitize_text( $matches[3] );

				/*
				 * Require an explicit http(s) scheme on the *raw* href before
				 * normalizing. esc_url_raw() prepends `http://` to a
				 * scheme-less value (`relative/page` -> `http://relative/page`),
				 * so checking after sanitizing would turn a relative link into
				 * a bogus external facet. Non-http and text-less anchors are
				 * left in place; sanitize_text() then strips the tag and keeps
				 * any inner text, without a facet.
				 */
				if ( ! \preg_match( '#^https?://#i', $raw_href ) || '' === $anchor_text ) {
					return $matches[0];
				}

				$href = \esc_url_raw( $raw_href );
				if ( '' === $href ) {
					return $matches[0];
				}

				$marker             = self::LINK_MARKER_PREFIX . $index . self::LINK_MARKER_SUFFIX;
				$anchors[ $marker ] = array(
					'text' => $anchor_text,
					'uri'  => $href,
				);
				++$index;

				/*
				 * Keep any whitespace that sat just inside the anchor tags
				 * (e.g. `Click<a> here</a>`): sanitize_text() trims the facet
				 * text, so without re-emitting that boundary space around the
				 * marker the words would fuse into `Clickhere`. A single space
				 * matches what sanitize_text() would have collapsed it to.
				 */
				$inner = (string) \preg_replace( '/<[^>]*>/', '', \html_entity_decode( $matches[3], \ENT_QUOTES, 'UTF-8' ) );
				$lead  = \preg_match( '/^\s/u', $inner ) ? ' ' : '';
				$trail = \preg_match( '/\s$/u', $inner ) ? ' ' : '';

				return $lead . $marker . $trail;
			},
			$html
		);

		/*
		 * preg_replace_callback returns null on PCRE failure (e.g. a
		 * backtrack-limit blowout on pathological input); fall back to the
		 * sanitized HTML with no link facets rather than emitting a broken
		 * record. Reuse $html so `the_content` is not run a second time.
		 */
		if ( null === $marked_html ) {
			return array( sanitize_text( $html ), array() );
		}

		$marked = sanitize_text( $marked_html );

		$text   = '';
		$facets = array();
		$cursor = 0;
		foreach ( $anchors as $marker => $anchor ) {
			$pos = \strpos( $marked, $marker, $cursor );
			if ( false === $pos ) {
				continue;
			}

			$text      .= \substr( $marked, $cursor, $pos - $cursor );
			$byte_start = \strlen( $text );
			$text      .= $anchor['text'];
			$facets[]   = array(
				'index'    => array(
					'byteStart' => $byte_start,
					'byteEnd'   => \strlen( $text ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $anchor['uri'],
					),
				),
			);
			$cursor     = $pos + \strlen( $marker );
		}
		$text .= \substr( $marked, $cursor );

		return array( $text, $facets );
	}

	/**
	 * Merge inline-link facets with the extracted mention/hashtag/URL facets.
	 *
	 * Inline-link facets win on overlap: an extracted facet whose byte range
	 * intersects an inline link is dropped (e.g. a bare URL or hashtag that
	 * happens to sit inside the anchor's visible text), so a single range
	 * never carries two features. The result is sorted by `byteStart`, the
	 * order `Facet::extract()` itself guarantees.
	 *
	 * @param array $link_facets      Inline-link facets from the anchor pass.
	 * @param array $extracted_facets Facets from `Facet::extract()`.
	 * @return array
	 */
	private function merge_link_facets( array $link_facets, array $extracted_facets ): array {
		if ( empty( $link_facets ) ) {
			return $extracted_facets;
		}

		$kept = array();
		foreach ( $extracted_facets as $facet ) {
			$start = $facet['index']['byteStart'];
			$end   = $facet['index']['byteEnd'];

			$overlaps = false;
			foreach ( $link_facets as $link ) {
				if ( $start < $link['index']['byteEnd'] && $link['index']['byteStart'] < $end ) {
					$overlaps = true;
					break;
				}
			}

			if ( ! $overlaps ) {
				$kept[] = $facet;
			}
		}

		$merged = \array_merge( $link_facets, $kept );

		\usort(
			$merged,
			static fn( $a, $b ) => $a['index']['byteStart'] <=> $b['index']['byteStart']
		);

		return $merged;
	}

	/**
	 * Whether this post should be treated as short-form for Bluesky.
	 *
	 * Exposes the private type/title/format-plus-length discriminator (see
	 * {@see self::is_short_form()}) through the `atmosphere_is_short_form_post`
	 * filter. Callers such as Publisher branch on short vs. long without
	 * reaching into the transformer's private state.
	 *
	 * Redacted posts return true without invoking the filter so direct
	 * transformer callers do not expose protected post objects to
	 * subscribers.
	 *
	 * @return bool
	 */
	public function is_short_form_post(): bool {
		if ( $this->is_redacted() ) {
			return true;
		}

		/*
		 * Custom text always publishes as a single external link card, which
		 * is the long-form publish path — that is what makes Publisher run the
		 * document-CID precompute and attach the standard.site associatedRef
		 * to the card at create time (Bluesky only indexes it then). So a
		 * custom-text post is long-form regardless of its body length, and
		 * this supersedes the short/long heuristic and its filter.
		 */
		if ( $this->has_custom_text() ) {
			return false;
		}

		if ( null !== $this->short_form_verdict ) {
			return $this->short_form_verdict;
		}

		/**
		 * Filters whether the post should be treated as short-form for Bluesky.
		 *
		 * Short-form posts publish natively (post body as text, no external
		 * embed card). Long-form posts use the teaser composition (title +
		 * excerpt + permalink) with an external card linking back to
		 * WordPress. The default discriminator mirrors the ActivityPub
		 * plugin's Post::get_type() logic — short-form when the post type
		 * does not support titles, the post has an empty title, or the post
		 * has any non-empty post_format — but additionally treats a post
		 * whose body overflows the 300-character native cap as long-form, so
		 * a long, titleless link-blog post links back to the original
		 * instead of being truncated.
		 *
		 * @param bool     $is_short Whether the post should be treated as short-form.
		 * @param \WP_Post $post     The post being transformed.
		 */
		$this->short_form_verdict = \wp_validate_boolean(
			\apply_filters(
				'atmosphere_is_short_form_post',
				$this->is_short_form( $this->object ),
				$this->object
			)
		);

		return $this->short_form_verdict;
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
		if ( $this->is_redacted() ) {
			return array(
				$this->record_for_thread_entry(
					'',
					true,
					array(
						'strategy'        => 'redacted',
						'thread_index'    => 0,
						'is_thread_reply' => false,
					)
				),
			);
		}

		/*
		 * Custom text overrides the composition strategy entirely: post the
		 * author's text as a single link-card record (see transform()), no
		 * thread, regardless of the `atmosphere_long_form_composition` setting.
		 */
		if ( $this->has_custom_text() ) {
			return array( $this->transform() );
		}

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
			debug_log(
				\sprintf(
					'post %d has no composable body/excerpt; downgrading "%s" to "link-card"',
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
							$this->apply_post_embed_filter( $this->build_embed(), 'teaser-thread' )
						),
					);
				}

				$texts   = $this->build_teaser_thread( $default_texts );
				$texts   = $this->carry_mentions_into_teaser( $texts );
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
						$i === $last ? $this->apply_post_embed_filter( $this->build_embed(), 'teaser-thread' ) : null
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
	 * Character length is measured in graphemes, matching the convention of
	 * the `truncate_text()` helper and Bluesky's 300-character cap. Preg
	 * offsets are byte offsets against the grapheme-clamped string; substr on
	 * a match's byte-end is UTF-8-safe because matches end on valid sequence
	 * boundaries.
	 *
	 * @param string $text            Input text.
	 * @param int    $max             Maximum length in graphemes.
	 * @param bool   $prefer_sentence Prefer a sentence boundary over a word boundary.
	 * @return string
	 */
	private function truncate_to_budget( string $text, int $max, bool $prefer_sentence = true ): string {
		if ( $max <= 0 ) {
			return '';
		}

		if ( grapheme_length( $text ) <= $max ) {
			return $text;
		}

		if ( 1 === $max ) {
			return '…';
		}

		$clamped = truncate_graphemes( $text, $max );

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

		// Hard cap. Reserve one grapheme for the ellipsis.
		return truncate_graphemes( $text, \max( 1, $max - 1 ) ) . '…';
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
		return grapheme_length( \get_permalink( $this->object ) ) >= self::BLUESKY_MAX_GRAPHEMES;
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
		return grapheme_length( $this->teaser_thread_cta_text() ) > self::BLUESKY_MAX_GRAPHEMES;
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
		$max_length = self::BLUESKY_MAX_GRAPHEMES;
		$separator  = "\n\n";
		$permalink  = \get_permalink( $this->object );
		$plain      = $this->render_post_content_plain( $this->object );

		if ( grapheme_length( $permalink ) >= $max_length ) {
			return $this->truncate_to_budget( $permalink, $max_length, false );
		}

		$budget = $max_length - grapheme_length( $permalink );

		if ( $budget <= grapheme_length( $separator ) ) {
			return $permalink;
		}

		$body = $this->truncate_to_budget( $plain, $budget - grapheme_length( $separator ), false );

		return $this->carry_body_mentions( $body . $separator . $permalink, $permalink );
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
	 * @param array|null $precomputed_default Precomputed default array
	 *                                        from `compute_default_teaser_thread()`.
	 *                                        Pass to avoid re-running
	 *                                        the `render_post_content_plain`
	 *                                        / `truncate_to_budget`
	 *                                        pipeline when the caller
	 *                                        already needed the default
	 *                                        for its own decision (e.g.
	 *                                        the redundant-CTA collapse
	 *                                        predicate). When null,
	 *                                        computed here.
	 * @return string[] Text of each post in order. 2 or 3 entries by
	 *                  default; up to 5 when overridden by filter.
	 */
	private function build_teaser_thread( ?array $precomputed_default = null ): array {
		$default = $precomputed_default ?? $this->compute_default_teaser_thread();

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
				'1.0.0'
			);
			return $default;
		}

		$texts = array();
		foreach ( $filtered as $entry ) {
			if ( \is_string( $entry ) ) {
				$entry = sanitize_text( $entry );
				if ( '' !== $entry ) {
					$texts[] = $this->truncate_to_budget( $entry, self::BLUESKY_MAX_GRAPHEMES, false );
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
				'1.0.0'
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
		if ( $this->is_redacted() ) {
			return false;
		}

		if ( ! empty( $this->object->post_excerpt )
			&& \mb_strlen( sanitize_text( $this->object->post_excerpt ) ) >= 10
		) {
			return true;
		}

		return \mb_strlen( $this->render_post_content_plain( $this->object ) ) >= 10;
	}

	/**
	 * Whether this post's fields must be redacted from AT Protocol records.
	 *
	 * @return bool
	 */
	private function is_redacted(): bool {
		return $this->is_post_redacted( $this->object );
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
			$hook         = $this->truncate_to_budget( $excerpt, self::BLUESKY_MAX_GRAPHEMES, false );
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
	 * Triggers when ALL of:
	 *   - The post has no usable `post_excerpt` (so the hook IS the
	 *     body, not a separate curated string).
	 *   - The body fits entirely in the 280-char hook budget (so the
	 *     hook is the *whole* body, not a truncated prefix — without
	 *     this check, a 285-char body produces a hook-truncated
	 *     `[ first 280 chars, cta ]` default whose collapse would
	 *     silently drop the trailing 5 chars from bsky output without
	 *     the reader having any in-text affordance to know there's
	 *     more).
	 *   - The default is the 2-entry fallback (so chunk_source ended
	 *     up below the 10-char floor and the body chunk was dropped).
	 *
	 * In that exact shape, the CTA reply is purely redundant — the
	 * entire post body is already in the hook above it. Callers can
	 * collapse to a single record with the body text and a link-card
	 * embed instead.
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
		if ( \mb_strlen( $excerpt ) >= 10 ) {
			return false;
		}

		// Confirm the hook IS the whole body, not a truncated prefix.
		// 280 mirrors `compute_default_teaser_thread()`'s body-as-hook
		// budget, which `truncate_to_budget()` measures in graphemes; for a
		// body at or below that length the hook equals the body verbatim and
		// `chunk_source` is empty.
		return grapheme_length( $this->render_post_content_plain( $this->object ) ) <= 280;
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

		// Skip DNS-resolving facet extraction in projection mode (see $projecting).
		$facets = $this->projecting
			? array()
			: Facet::extract( $text, $this->mentions_enabled(), $this->blocked_mention_handles() );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		if ( null !== $embed ) {
			$record['embed'] = $embed;
		}

		if ( $is_root && ! $this->is_redacted() ) {
			$tags = $this->collect_tags( $this->object );
			if ( ! empty( $tags ) ) {
				$record['tags'] = $tags;
			}
		}

		if ( $this->is_redacted() ) {
			return $record;
		}

		$context = \wp_parse_args(
			$context,
			array(
				'strategy'        => 'teaser-thread',
				'thread_index'    => 0,
				'is_thread_reply' => ! $is_root,
				'is_custom_text'  => false,
			)
		);

		/** This filter is documented in Post::transform() above. */
		$filtered = \apply_filters( 'atmosphere_transform_bsky_post', $record, $this->object, $context );

		if ( ! \is_array( $filtered ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_transform_bsky_post must return an array; falling back to the unfiltered record.', 'atmosphere' ),
				'1.0.0'
			);
			return $record;
		}

		return $filtered;
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
		$text     = $this->build_text();
		$redacted = $this->is_redacted();
		$embed    = $this->build_embed();

		if ( ! $redacted ) {
			$embed = $this->apply_post_embed_filter( $embed, 'link-card' );
		}

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->to_iso8601( $this->object->post_date_gmt ),
			'langs'     => $this->get_langs(),
		);

		// Skip DNS-resolving facet extraction in projection mode (see $projecting).
		$facets = $this->projecting
			? array()
			: Facet::extract( $text, $this->mentions_enabled(), $this->blocked_mention_handles() );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		// `apply_post_embed_filter()` guarantees `$embed` is either null
		// or a well-formed array with a `$type` key, so this matches
		// the `null !== $embed` check in `record_for_thread_entry()`.
		if ( null !== $embed ) {
			$record['embed'] = $embed;
		}

		if ( ! $redacted ) {
			$tags = $this->collect_tags( $this->object );
			if ( ! empty( $tags ) ) {
				$record['tags'] = $tags;
			}
		}

		if ( $redacted ) {
			return $record;
		}

		/** This filter is documented in Post::transform() above. */
		$filtered = \apply_filters(
			'atmosphere_transform_bsky_post',
			$record,
			$this->object,
			array(
				'strategy'        => 'link-card',
				'thread_index'    => 0,
				'is_thread_reply' => false,
				'is_custom_text'  => false,
			)
		);

		if ( ! \is_array( $filtered ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_transform_bsky_post must return an array; falling back to the unfiltered record.', 'atmosphere' ),
				'1.0.0'
			);
			return $record;
		}

		return $filtered;
	}
}
