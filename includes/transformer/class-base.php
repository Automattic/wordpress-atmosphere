<?php
/**
 * Abstract base for AT Protocol record transformers.
 *
 * Each concrete transformer converts a WordPress object (post, site)
 * into a specific AT Protocol lexicon record.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use function Atmosphere\build_at_uri;
use function Atmosphere\get_did;
use function Atmosphere\sanitize_text;
use function Atmosphere\to_iso8601;

/**
 * Abstract base transformer.
 */
abstract class Base {

	/**
	 * The WordPress object being transformed.
	 *
	 * @var mixed
	 */
	protected mixed $object;

	/**
	 * Constructor.
	 *
	 * @param mixed $object WordPress object.
	 */
	public function __construct( mixed $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		$this->object = $object;
	}

	/**
	 * Produce the AT Protocol record array.
	 *
	 * @return array
	 */
	abstract public function transform(): array;

	/**
	 * Collection NSID this record belongs to.
	 *
	 * @return string
	 */
	abstract public function get_collection(): string;

	/**
	 * Record key (TID).
	 *
	 * @return string
	 */
	abstract public function get_rkey(): string;

	/**
	 * Build the full AT-URI for this record.
	 *
	 * @return string
	 */
	public function get_uri(): string {
		return build_at_uri(
			get_did(),
			$this->get_collection(),
			$this->get_rkey()
		);
	}

	/**
	 * WordPress locale as BCP-47 language tag array.
	 *
	 * @return string[]
	 */
	protected function get_langs(): array {
		return array( \substr( \get_locale(), 0, 2 ) );
	}

	/**
	 * Convert a GMT datetime string to ISO 8601.
	 *
	 * @param string $datetime GMT datetime.
	 * @return string
	 */
	protected function to_iso8601( string $datetime ): string {
		return to_iso8601( $datetime );
	}

	/**
	 * Collect tags from post taxonomies (max 8, no "uncategorized").
	 *
	 * @param \WP_Post $post Post object.
	 * @return string[]
	 */
	protected function collect_tags( \WP_Post $post ): array {
		$tags = array();

		$post_tags = \get_the_tags( $post->ID );
		if ( $post_tags ) {
			foreach ( $post_tags as $t ) {
				$tags[] = $t->name;
			}
		}

		$categories = \get_the_category( $post->ID );
		if ( $categories ) {
			foreach ( $categories as $cat ) {
				if ( 'uncategorized' !== $cat->slug ) {
					$tags[] = $cat->name;
				}
			}
		}

		return \array_slice( \array_unique( $tags ), 0, 8 );
	}

	/**
	 * Get a short plain-text excerpt for a post.
	 *
	 * @param \WP_Post $post      Post object.
	 * @param int      $word_limit Words to keep.
	 * @return string
	 */
	protected function get_excerpt( \WP_Post $post, int $word_limit = 30 ): string {
		if ( ! empty( $post->post_excerpt ) ) {
			return sanitize_text( $post->post_excerpt );
		}

		return \wp_trim_words( sanitize_text( $post->post_content ), $word_limit, '...' );
	}

	/**
	 * Render a post's content to plain text.
	 *
	 * Runs the_content filter, strips tags, decodes entities, and
	 * collapses whitespace. Shared by short-form Bluesky post
	 * composition and the document record's textContent field.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	protected function render_post_content_plain( \WP_Post $post ): string {
		$content = \apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.

		return sanitize_text( $content );
	}
}
