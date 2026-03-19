<?php
/**
 * Rich-text facet extraction for AT Protocol posts.
 *
 * Facets annotate byte ranges in a plain-text string with semantic
 * features such as links, @-mentions, and #hashtags.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use function Atmosphere\get_connection;

/**
 * Extracts facets from plain text.
 */
class Facet {

	/**
	 * Extract all facet types from a piece of text.
	 *
	 * @param string $text Plain text.
	 * @return array Sorted array of facet objects.
	 */
	public static function extract( string $text ): array {
		$facets = \array_merge(
			self::links( $text ),
			self::mentions( $text ),
			self::hashtags( $text )
		);

		\usort(
			$facets,
			static fn( $a, $b ) => $a['index']['byteStart'] <=> $b['index']['byteStart']
		);

		return $facets;
	}

	/**
	 * Build facets for known URLs occurring in the text.
	 *
	 * @param string   $text Plain text.
	 * @param string[] $urls URLs to look for.
	 * @return array Facet array.
	 */
	public static function for_urls( string $text, array $urls ): array {
		$facets = array();

		foreach ( $urls as $url ) {
			$pos = \strpos( $text, $url );

			if ( false === $pos ) {
				continue;
			}

			$byte_start = \strlen( \mb_substr( $text, 0, $pos ) );

			$facets[] = array(
				'index'    => array(
					'byteStart' => $byte_start,
					'byteEnd'   => $byte_start + \strlen( $url ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $url,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Find URLs in text and return link facets.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	private static function links( string $text ): array {
		$facets  = array();
		$pattern = '#\bhttps?://[^\s<>\[\]"\']+#iu';

		if ( ! \preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $match ) {
			$url        = \rtrim( $match[0], '.,;:!?)' );
			$byte_start = $match[1];

			$facets[] = array(
				'index'    => array(
					'byteStart' => $byte_start,
					'byteEnd'   => $byte_start + \strlen( $url ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $url,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Find @handle mentions and return mention facets.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	private static function mentions( string $text ): array {
		$facets  = array();
		$pattern = '/@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+)/u';

		if ( ! \preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $i => $match ) {
			$full   = $match[0];
			$handle = $matches[1][ $i ][0];
			$start  = $match[1];

			$did = self::resolve_mention( $handle );

			$facets[] = array(
				'index'    => array(
					'byteStart' => $start,
					'byteEnd'   => $start + \strlen( $full ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#mention',
						'did'   => $did,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Find #hashtags and return tag facets.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	private static function hashtags( string $text ): array {
		$facets  = array();
		$pattern = '/#([a-zA-Z][a-zA-Z0-9_]{0,63})/u';

		if ( ! \preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $i => $match ) {
			$full  = $match[0];
			$tag   = $matches[1][ $i ][0];
			$start = $match[1];

			$facets[] = array(
				'index'    => array(
					'byteStart' => $start,
					'byteEnd'   => $start + \strlen( $full ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#tag',
						'tag'   => $tag,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Resolve a handle to a DID for mention facets.
	 *
	 * Falls back to did:web if DNS resolution fails.
	 *
	 * @param string $handle AT Protocol handle.
	 * @return string DID string.
	 */
	private static function resolve_mention( string $handle ): string {
		$conn = get_connection();
		if ( ! empty( $conn['handle'] ) && \strtolower( $handle ) === \strtolower( $conn['handle'] ) ) {
			return $conn['did'];
		}

		$records = @\dns_get_record( '_atproto.' . $handle, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( \is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['txt'] ) && \str_starts_with( $record['txt'], 'did=' ) ) {
					return \substr( $record['txt'], 4 );
				}
			}
		}

		return 'did:web:' . $handle;
	}
}
