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
use Atmosphere\Content_Parser\Markpub;
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
		$record = array(
			'$type'       => 'site.standard.document',
			'title'       => sanitize_text( \get_the_title( $this->object ) ),
			'publishedAt' => $this->to_iso8601( $this->object->post_date_gmt ),
		);

		// Publication reference (required by spec).
		$pub_tid = \get_option( 'atmosphere_publication_tid' );
		if ( $pub_tid ) {
			$record['site'] = build_at_uri( get_did(), 'site.standard.publication', $pub_tid );
		} else {
			// Fall back to site URL for standalone documents.
			$record['site'] = \untrailingslashit( \get_home_url() );
		}

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

		/**
		 * Filters the site.standard.document record before publishing.
		 *
		 * @param array    $record Document record.
		 * @param \WP_Post $post   WordPress post.
		 */
		return \apply_filters( 'atmosphere_transform_document', $record, $this->object );
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
		 * Return a Content_Parser instance to override the default parser.
		 * Return null to disable the content field entirely.
		 *
		 * @param Content_Parser|null $parser The content parser. Default: Markpub.
		 * @param \WP_Post            $post   The WordPress post.
		 */
		$parser = \apply_filters( 'atmosphere_content_parser', new Markpub(), $this->object );

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
	 * @return string
	 */
	private function get_text_content(): string {
		$content = \apply_filters( 'the_content', $this->object->post_content );
		$content = \wp_strip_all_tags( $content );
		$content = \html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		return \trim( \preg_replace( '/\s+/', ' ', $content ) );
	}
}
