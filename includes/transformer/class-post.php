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
	 * is constructed. Subsequent publishes of the same post
	 * (`update_post()` flow) do not inject — by then
	 * `Document::META_URI` / `Document::META_CID` are populated and
	 * {@see self::build_embed()} reads the ref from meta instead.
	 *
	 * @var array{$type: string, uri: string, cid: string}|null
	 */
	private ?array $document_strong_ref = null;

	/**
	 * Inject the document strongRef the embed builder should advertise
	 * in `associatedRefs` on the initial publish.
	 *
	 * See {@see self::$document_strong_ref} for the why. Passing an
	 * empty array or a malformed shape (missing `uri` / `cid`) clears
	 * the injection and the embed builder falls back to reading from
	 * `Document::META_*`.
	 *
	 * @param array $ref StrongRef to advertise (keys: optional `$type`, required `uri` and `cid`).
	 */
	public function set_document_strong_ref( array $ref ): void {
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
	 * Transform the post.
	 *
	 * @return array app.bsky.feed.post record.
	 */
	public function transform(): array {
		$redacted = $this->is_redacted();

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
		$is_short = true;
		if ( ! $redacted ) {
			$is_short = \wp_validate_boolean(
				\apply_filters(
					'atmosphere_is_short_form_post',
					$this->is_short_form( $this->object ),
					$this->object
				)
			);
		}

		$text  = $redacted ? '' : ( $is_short ? $this->build_short_form_text() : '' );
		$embed = null;

		if ( ! $redacted ) {
			if ( $is_short ) {
				$embed = $this->build_images_embed();
				if ( '' === $text && null === $embed ) {
					$text  = $this->build_text();
					$embed = $this->build_embed();
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

		$facets = Facet::extract( $text );
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
		 * @param array    $record Bsky post record.
		 * @param \WP_Post $post   WordPress post.
		 * @param array    $context Additional composition context.
		 */
		$filtered = \apply_filters(
			'atmosphere_transform_bsky_post',
			$record,
			$this->object,
			array(
				'strategy'        => $is_short ? 'short-form' : 'link-card',
				'thread_index'    => 0,
				'is_thread_reply' => false,
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
		if ( $this->is_redacted() ) {
			return '';
		}

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
			$blob = self::upload_image_blob( $attachment_id );
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
		$content = (string) $this->object->post_content;

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
		if ( $thumb_id ) {
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
		 *   - On the *initial* publish, the Publisher precomputes the
		 *     document's CID locally via DAG-CBOR and injects via
		 *     `set_document_strong_ref()`. Without this, the document
		 *     ref could only be added after the atomic write returned
		 *     — and Bluesky's AppView ignores subsequent
		 *     `applyWrites#update` for the purposes of indexing
		 *     `source` / `associatedProfiles` enrichment.
		 *   - On an *update* publish, the injection is absent but
		 *     `Document::META_*` are already populated by the
		 *     previous publish's `store_document_meta()`, so reading
		 *     from meta produces an equivalent ref.
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
		} else {
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
	 * Upload an image attachment and return the blob reference.
	 *
	 * Used for any image that needs to land on the PDS — featured-image
	 * thumbnails for link cards, publication icons, and (downstream)
	 * native `app.bsky.embed.images` attachments. Blob refs are cached
	 * in `_atmosphere_blob_ref` postmeta so a re-publish of the same
	 * attachment skips the upload.
	 *
	 * If the original file exceeds AT Protocol's 1 MB blob cap, falls
	 * back to the `large` intermediate size; returns null if even the
	 * fallback is too large or unreadable.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Blob reference or null.
	 */
	public static function upload_image_blob( int $attachment_id ): ?array {
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
		if ( $this->is_redacted() ) {
			return '';
		}

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
							$this->apply_post_embed_filter( $this->build_embed(), 'teaser-thread' )
						),
					);
				}

				$texts   = $this->build_teaser_thread( $default_texts );
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
		// budget; for a body at or below that length the hook
		// equals the body verbatim and `chunk_source` is empty.
		return \mb_strlen( $this->render_post_content_plain( $this->object ) ) <= 280;
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

		$facets = Facet::extract( $text );
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
