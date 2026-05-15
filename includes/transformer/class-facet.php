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

			/*
			 * `resolve_mention()` returns an empty string when the
			 * handle fails its (defence-in-depth) syntax check. Skip
			 * the facet entirely in that case — sending an empty
			 * `did` to Bluesky would have the PDS reject the record.
			 */
			if ( '' === $did ) {
				continue;
			}

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
	 * Falls back to did:web if DNS resolution fails. Handles that
	 * don't match the AT Protocol DNS-style syntax never reach the
	 * `dns_get_record` call — without that gate, a post containing
	 * `@evil-attacker-controlled-tld.example` would trigger a server
	 * DNS lookup against an attacker-controlled domain (low-bandwidth
	 * but reliable side-channel for exfiltrating data via subdomain
	 * encoding).
	 *
	 * @param string $handle AT Protocol handle.
	 * @return string DID string, or empty string if the handle is malformed.
	 */
	private static function resolve_mention( string $handle ): string {
		$conn = get_connection();
		if ( ! empty( $conn['handle'] ) && \strtolower( $handle ) === \strtolower( $conn['handle'] ) ) {
			return $conn['did'];
		}

		if ( ! self::is_valid_handle( $handle ) ) {
			return '';
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

	/**
	 * RFC 1035-style DNS-name validation, mirroring
	 * `Resolver::is_valid_handle()`. Rejects empty strings, oversized
	 * labels, leading/trailing hyphens, single-label hosts, and any
	 * character outside `[A-Za-z0-9-]` — including percent-encoded
	 * forms.
	 *
	 * @param string $host Handle to validate.
	 * @return bool
	 */
	private static function is_valid_handle( string $host ): bool {
		if ( '' === $host || \strlen( $host ) > 253 ) {
			return false;
		}

		$label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

		return (bool) \preg_match( '/^' . $label . '(?:\.' . $label . ')+$/', $host );
	}
}
