<?php
/**
 * Helper functions for ATmosphere.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Parse an AT-URI into components.
 *
 * @param string $uri AT-URI (at://did/collection/rkey).
 * @return array{did: string, collection: string, rkey: string}|false
 */
function parse_at_uri( string $uri ): array|false {
	if ( ! \str_starts_with( $uri, 'at://' ) ) {
		return false;
	}

	$segments = \explode( '/', \substr( $uri, 5 ) );

	if ( \count( $segments ) < 3 ) {
		return false;
	}

	return array(
		'did'        => $segments[0],
		'collection' => $segments[1],
		'rkey'       => $segments[2],
	);
}

/**
 * Build an AT-URI from components.
 *
 * @param string $did        DID.
 * @param string $collection Collection NSID.
 * @param string $rkey       Record key.
 * @return string
 */
function build_at_uri( string $did, string $collection, string $rkey ): string {
	return "at://{$did}/{$collection}/{$rkey}";
}

/**
 * Strip HTML, decode entities, normalise whitespace.
 *
 * @param string $text Raw text.
 * @return string Clean text.
 */
function sanitize_text( string $text ): string {
	$text = \wp_strip_all_tags( $text );
	$text = \html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = \preg_replace( '/\s+/', ' ', $text );

	return \trim( $text );
}

/**
 * Truncate text to a grapheme limit, breaking at word boundaries.
 *
 * @param string $text   Text to truncate.
 * @param int    $limit  Maximum graphemes.
 * @param string $marker Ellipsis marker.
 * @return string
 */
function truncate_text( string $text, int $limit = 300, string $marker = '...' ): string {
	if ( \mb_strlen( $text ) <= $limit ) {
		return $text;
	}

	$cut       = \mb_substr( $text, 0, $limit - \mb_strlen( $marker ) );
	$last_word = \mb_strrpos( $cut, ' ' );

	if ( $last_word && $last_word > $limit * 0.8 ) {
		$cut = \mb_substr( $cut, 0, $last_word );
	}

	return $cut . $marker;
}

/**
 * Convert a WordPress GMT datetime to ISO 8601.
 *
 * @param string $datetime GMT datetime string.
 * @return string
 */
function to_iso8601( string $datetime ): string {
	return \gmdate( 'Y-m-d\TH:i:s.000\Z', \strtotime( $datetime ) );
}

/**
 * Get the stored connection data.
 *
 * @return array
 */
function get_connection(): array {
	return \get_option( 'atmosphere_connection', array() );
}

/**
 * Check whether the plugin is connected to a PDS.
 *
 * @return bool
 */
function is_connected(): bool {
	$conn = get_connection();
	return ! empty( $conn['access_token'] ) && ! empty( $conn['did'] );
}

/**
 * Get the connected DID.
 *
 * @return string
 */
function get_did(): string {
	return get_connection()['did'] ?? '';
}

/**
 * Get the connected PDS endpoint.
 *
 * @return string
 */
function get_pds_endpoint(): string {
	return get_connection()['pds_endpoint'] ?? '';
}
