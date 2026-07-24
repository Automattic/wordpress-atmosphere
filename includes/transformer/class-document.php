<?php
/**
 * Transforms a WordPress post into a site.standard.document record.
 *
 * Documents carry full structured metadata: title, path, description,
 * cover image, plain-text content, and tags.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Content_Parser\Content_Parser;
use Atmosphere\Content_Parser\Registry;
use function Atmosphere\build_at_uri;
use function Atmosphere\get_did;
use function Atmosphere\sanitize_text;
use function Atmosphere\truncate_graphemes;

/**
 * Standard.site document transformer.
 */
class Document extends Base {

	/**
	 * Post meta key for the document TID.
	 *
	 * @var string
	 */
	public const META_TID = '_atmosphere_doc_tid';

	/**
	 * Post meta key for the DID that minted the document TID.
	 *
	 * Companion to `META_TID` so cleanup paths can detect when the
	 * record was written under a different connected account. See
	 * `\Atmosphere\Transformer\Post::META_DID` for the matching key on
	 * the bsky side and the rationale.
	 *
	 * @var string
	 */
	public const META_DID = '_atmosphere_doc_did';

	/**
	 * Post meta key for the document AT-URI.
	 *
	 * @var string
	 */
	public const META_URI = '_atmosphere_doc_uri';

	/**
	 * Post meta key for the document CID.
	 *
	 * @var string
	 */
	public const META_CID = '_atmosphere_doc_cid';

	/**
	 * Whether the current transform is a read-only preview projection.
	 *
	 * Set for the duration of {@see self::get_preview_records()}. In
	 * projection mode the cover image comes from the cached blob ref
	 * only ({@see Post::cached_image_blob()}) — an uncached image is
	 * omitted rather than uploaded, so a preview GET never writes to
	 * the PDS or to attachment meta. Mirrors `Post::$projecting`.
	 *
	 * @var bool
	 */
	private bool $projecting = false;

	/**
	 * {@inheritDoc}
	 *
	 * Projects in read-only mode ({@see self::$projecting}) so no blobs
	 * are uploaded and no meta is written by a preview request.
	 */
	public function get_preview_records(): array {
		$this->projecting = true;

		try {
			return array( $this->transform() );
		} finally {
			$this->projecting = false;
		}
	}

	/**
	 * Transform the post into a document record.
	 *
	 * @return array site.standard.document record.
	 */
	public function transform(): array {
		$redacted = $this->is_post_redacted( $this->object );

		/*
		 * Redacted records are defense-in-depth output for authorized
		 * previews/direct callers. Publisher rejects or deletes
		 * non-publishable posts before this placeholder reaches the PDS.
		 */
		$record = array(
			'$type' => 'site.standard.document',
			'title' => $redacted ? '' : sanitize_text( \get_the_title( $this->object ) ),
		);

		if ( ! $redacted ) {
			$record['publishedAt'] = $this->to_iso8601( $this->object->post_date_gmt );
		}

		// Publication reference (required by spec).
		$pub_tid = \get_option( 'atmosphere_publication_tid' );
		if ( $pub_tid ) {
			$record['site'] = build_at_uri( get_did(), 'site.standard.publication', $pub_tid );
		} else {
			// Fall back to site URL for standalone documents.
			$record['site'] = \untrailingslashit( \get_home_url() );
		}

		if ( ! $redacted ) {
			// Relative path.
			$permalink = \get_permalink( $this->object );
			$relative  = \wp_make_link_relative( $permalink );
			if ( $relative ) {
				$record['path'] = $relative;
			}

			// Description.
			$excerpt = $this->get_excerpt( $this->object, 55 );
			if ( ! empty( $excerpt ) ) {
				$record['description'] = $excerpt;
			}

			// Cover image. Projections reuse the cached blob ref (or omit
			// the image) instead of uploading — see self::$projecting.
			$thumb_id = \get_post_thumbnail_id( $this->object );
			if ( $thumb_id ) {
				$blob = $this->projecting
					? Post::cached_image_blob( $thumb_id )
					: Post::upload_thumbnail( $thumb_id );
				if ( $blob ) {
					$record['coverImage'] = $blob;
				}
			}

			// Full text content.
			$text_content = $this->get_text_content();
			if ( ! empty( $text_content ) ) {
				$record['textContent'] = $text_content;
			}

			// Parsed rich content (open union).
			$content = $this->get_content();
			if ( ! empty( $content ) ) {
				$record['content'] = $content;
			}

			// Tags.
			$tags = $this->collect_tags( $this->object );
			if ( ! empty( $tags ) ) {
				$record['tags'] = $tags;
			}

			/**
			 * Filters the site.standard.document links union.
			 *
			 * Return an array with a non-empty string `$type` field to add
			 * the `links` field. Return null or an empty array to omit it.
			 *
			 * @since 2.0.0
			 *
			 * @param array|null $links Links union object, or null to omit.
			 * @param \WP_Post   $post  WordPress post.
			 */
			$links = self::validate_open_union(
				\apply_filters( 'atmosphere_document_links', null, $this->object ),
				__METHOD__,
				\__( 'atmosphere_document_links must return an array with a non-empty string $type field; omitting the links field.', 'atmosphere' )
			);
			if ( null !== $links ) {
				$record['links'] = $links;
			}

			/**
			 * Filters the site.standard.document self-labels object.
			 *
			 * Return a com.atproto.label.defs#selfLabels object to add
			 * content-warning labels. Return null or an empty array to omit it.
			 *
			 * @since 2.0.0
			 *
			 * @param array|null $labels Self-labels object, or null to omit.
			 * @param \WP_Post   $post   WordPress post.
			 */
			$labels = self::validate_self_labels(
				\apply_filters( 'atmosphere_document_labels', null, $this->object ),
				__METHOD__
			);
			if ( null !== $labels ) {
				$record['labels'] = $labels;
			}

			/**
			 * Filters the site.standard.document contributor list.
			 *
			 * Return an array of contributor objects. Each contributor must
			 * include a DID; optional role and displayName values are
			 * sanitized and capped to the lexicon's 100-grapheme limit.
			 * Return null or an empty array to omit the field.
			 *
			 * @since 2.0.0
			 *
			 * @param array|null $contributors Contributor list, or null to omit.
			 * @param \WP_Post   $post         WordPress post.
			 */
			$contributors = self::sanitize_contributors(
				\apply_filters( 'atmosphere_document_contributors', null, $this->object )
			);
			if ( null !== $contributors ) {
				$record['contributors'] = $contributors;
			}

			// Updated timestamp.
			if ( $this->object->post_modified_gmt !== $this->object->post_date_gmt ) {
				$record['updatedAt'] = $this->to_iso8601( $this->object->post_modified_gmt );
			}
		}

		if ( $redacted ) {
			return $record;
		}

		/**
		 * Filters the site.standard.document record before publishing.
		 *
		 * Filters that return a non-array fall back to the pre-filter
		 * record.
		 *
		 * @param array    $record Document record.
		 * @param \WP_Post $post   WordPress post.
		 */
		$filtered = \apply_filters( 'atmosphere_transform_document', $record, $this->object );

		if ( ! \is_array( $filtered ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_transform_document must return an array; falling back to the unfiltered record.', 'atmosphere' ),
				'1.0.0'
			);
			return $record;
		}

		return $filtered;
	}

	/**
	 * Sanitize a document contributor list.
	 *
	 * @param mixed $contributors Filter return value.
	 * @return array|null Contributor list, or null when omitted/invalid.
	 */
	private static function sanitize_contributors( $contributors ): ?array {
		if ( null === $contributors || array() === $contributors ) {
			return null;
		}

		if ( ! \is_array( $contributors ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_document_contributors must return an array of contributor objects; omitting the contributors field.', 'atmosphere' ),
				'unreleased'
			);
			return null;
		}

		$sanitized = array();
		foreach ( $contributors as $contributor ) {
			if ( ! \is_array( $contributor ) || empty( $contributor['did'] ) || ! \is_string( $contributor['did'] ) || ! \str_starts_with( $contributor['did'], 'did:' ) ) {
				\_doing_it_wrong(
					__METHOD__,
					\esc_html__( 'Document contributors must include a non-empty DID string; omitting the contributors field.', 'atmosphere' ),
					'unreleased'
				);
				return null;
			}

			$item = array( 'did' => $contributor['did'] );

			if ( isset( $contributor['role'] ) && \is_string( $contributor['role'] ) ) {
				$role = truncate_graphemes( sanitize_text( $contributor['role'] ), 100 );
				if ( '' !== $role ) {
					$item['role'] = $role;
				}
			}

			if ( isset( $contributor['displayName'] ) && \is_string( $contributor['displayName'] ) ) {
				$display_name = truncate_graphemes( sanitize_text( $contributor['displayName'] ), 100 );
				if ( '' !== $display_name ) {
					$item['displayName'] = $display_name;
				}
			}

			$sanitized[] = $item;
		}

		return array() === $sanitized ? null : $sanitized;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_collection(): string {
		return 'site.standard.document';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_rkey(): string {
		$post_id = $this->object->ID;

		return $this->reserve_rkey_with_provenance(
			fn ( string $key ) => \get_post_meta( $post_id, $key, true ),
			fn ( string $key, string $value ) => \update_post_meta( $post_id, $key, $value )
		);
	}

	/**
	 * Get parsed content for the document's content union field.
	 *
	 * @return array|null Parsed content object or null.
	 */
	private function get_content(): ?array {
		if ( empty( \trim( $this->object->post_content ) ) ) {
			return null;
		}

		$parser = $this->select_parser();

		if ( ! $parser instanceof Content_Parser ) {
			return null;
		}

		$content = $parser->parse( $this->object->post_content, $this->object );

		if ( null === $content ) {
			return null;
		}

		$content = $this->validate_content( $content, $parser, false );
		if ( null === $content ) {
			return null;
		}

		/**
		 * Filters the parsed content object before adding to the document record.
		 *
		 * @param array          $content The parsed content object.
		 * @param \WP_Post       $post    The WordPress post.
		 * @param Content_Parser $parser  The parser that produced the content.
		 */
		$filtered = \apply_filters( 'atmosphere_document_content', $content, $this->object, $parser );

		if ( ! \is_array( $filtered ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_document_content must return an array; falling back to the parser output.', 'atmosphere' ),
				'unreleased'
			);
			return $content;
		}

		return $this->validate_content( $filtered, $parser, true ) ?? $content;
	}

	/**
	 * Validate a parser-produced content object.
	 *
	 * @param array          $content            The parsed content object.
	 * @param Content_Parser $parser             Parser that produced the content.
	 * @param bool           $fallback_to_parser Whether invalid content falls back to parser output.
	 * @return array|null Valid content object, or null when invalid.
	 */
	private function validate_content( array $content, Content_Parser $parser, bool $fallback_to_parser ): ?array {
		$type = $content['$type'] ?? null;

		if ( ! \is_string( $type ) || '' === $type ) {
			\_doing_it_wrong(
				__METHOD__,
				$fallback_to_parser
					? \esc_html__( 'Content parsers must return a non-empty $type field; falling back to the parser output.', 'atmosphere' )
					: \esc_html__( 'Content parsers must return a non-empty $type field; omitting the content field.', 'atmosphere' ),
				'unreleased'
			);
			return null;
		}

		if ( $type !== $parser->get_type() ) {
			\_doing_it_wrong(
				__METHOD__,
				$fallback_to_parser
					? \esc_html__( 'Content parsers must return a $type field matching get_type(); falling back to the parser output.', 'atmosphere' )
					: \esc_html__( 'Content parsers must return a $type field matching get_type(); omitting the content field.', 'atmosphere' ),
				'unreleased'
			);
			return null;
		}

		return $content;
	}

	/**
	 * Select the content parser for this document.
	 *
	 * The registry chooses one parser based on the Content format setting
	 * and parser priority. The deprecated `atmosphere_content_parser`
	 * filter is still honored when callbacks are registered: a returned
	 * Content_Parser wins, while null preserves the old "omit content"
	 * behavior for integrations that intentionally suppress the field.
	 *
	 * @return Content_Parser|null
	 */
	private function select_parser(): ?Content_Parser {
		if ( false === \has_filter( 'atmosphere_content_parser' ) ) {
			return Registry::select( $this->object );
		}

		/**
		 * Filters the content parser used for site.standard.document records.
		 *
		 * @deprecated 1.2.0 Register parsers with {@see \Atmosphere\Content_Parser\Registry::register()} instead.
		 *
		 * @param Content_Parser|null $parser The content parser. Default: null.
		 * @param \WP_Post            $post   The WordPress post.
		 */
		$legacy = \apply_filters( 'atmosphere_content_parser', null, $this->object );

		\_deprecated_hook(
			'atmosphere_content_parser',
			'1.2.0',
			'\Atmosphere\Content_Parser\Registry::register()'
		);

		if ( $legacy instanceof Content_Parser ) {
			return $legacy;
		}

		return null;
	}

	/**
	 * Render post content to plain text.
	 *
	 * Delegates to Transformer\Base::render_post_content_plain() so
	 * the short-form Bluesky post path and the document textContent
	 * field agree on plain-text rendering.
	 *
	 * @return string
	 */
	private function get_text_content(): string {
		return $this->render_post_content_plain( $this->object );
	}
}
