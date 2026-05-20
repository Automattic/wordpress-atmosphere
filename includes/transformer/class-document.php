<?php
/**
 * Transforms a WordPress post into a site.standard.document record.
 *
 * Documents carry full structured metadata: title, path, description,
 * cover image, plain-text content, tags, and a cross-reference to
 * the corresponding Bluesky post.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Content_Parser\Content_Parser;
use function Atmosphere\build_at_uri;
use function Atmosphere\get_did;
use function Atmosphere\sanitize_text;

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

			// Cover image.
			$thumb_id = \get_post_thumbnail_id( $this->object );
			if ( $thumb_id ) {
				$blob = Post::upload_thumbnail( $thumb_id );
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

			// Bluesky cross-reference (populated after initial publish).
			$bsky_uri = \get_post_meta( $this->object->ID, Post::META_URI, true );
			$bsky_cid = \get_post_meta( $this->object->ID, Post::META_CID, true );
			if ( $bsky_uri && $bsky_cid ) {
				$record['bskyPostRef'] = array(
					'uri' => $bsky_uri,
					'cid' => $bsky_cid,
				);
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
	 * {@inheritDoc}
	 */
	public function get_collection(): string {
		return 'site.standard.document';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_rkey(): string {
		/*
		 * Refresh DID provenance on every call so reconnect-to-a-
		 * different-account flows update the recorded origin. See the
		 * full rationale on `\Atmosphere\Transformer\Post::get_rkey()`.
		 *
		 * Compare before writing so `wp_head`-time callers don't issue
		 * a DB write on every pageload.
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
	 * Get parsed content for the document's content union field.
	 *
	 * @return array|null Parsed content object or null.
	 */
	private function get_content(): ?array {
		if ( empty( \trim( $this->object->post_content ) ) ) {
			return null;
		}

		/**
		 * Filters the content parser used for site.standard.document records.
		 *
		 * Return a Content_Parser instance to provide a parser.
		 * Return null to disable the content field entirely.
		 *
		 * @param Content_Parser|null $parser The content parser. Default: null.
		 * @param \WP_Post            $post   The WordPress post.
		 */
		$parser = \apply_filters( 'atmosphere_content_parser', null, $this->object );

		if ( ! $parser instanceof Content_Parser ) {
			return null;
		}

		$content = $parser->parse( $this->object->post_content, $this->object );

		/**
		 * Filters the parsed content object before adding to the document record.
		 *
		 * @param array          $content The parsed content object.
		 * @param \WP_Post       $post    The WordPress post.
		 * @param Content_Parser $parser  The parser that produced the content.
		 */
		return \apply_filters( 'atmosphere_document_content', $content, $this->object, $parser );
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
