<?php
/**
 * Transforms a WordPress post into an app.bsky.feed.post record.
 *
 * The post text combines title + excerpt + permalink, truncated to
 * 300 graphemes.  An external embed card is attached with the
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
		 * plugin's Post::get_type() logic: untitled posts OR posts with any
		 * non-empty post_format are short-form.
		 *
		 * @param bool     $is_short Whether the post should be treated as short-form.
		 * @param \WP_Post $post     The post being transformed.
		 */
		$is_short = \apply_filters(
			'atmosphere_is_short_form_post',
			$this->is_short_form( $this->object ),
			$this->object
		);

		if ( $is_short ) {
			$text  = $this->build_short_form_text();
			$embed = null;
		} else {
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
	 * Compose the post text: title + excerpt + permalink within 300 graphemes.
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
	 * graphemes; a composer UI is expected to enforce the cap before
	 * publish.
	 *
	 * @return string
	 */
	private function build_short_form_text(): string {
		return truncate_text( $this->render_post_content_plain( $this->object ), 300 );
	}
}
