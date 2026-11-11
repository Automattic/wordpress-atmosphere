<?php
/**
 * Shared JWT claim decoding for OAuth tests.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests;

/**
 * Decodes compact JWTs without verifying them.
 */
trait JWT_Claims {

	/**
	 * Decode the claims of a compact JWT.
	 *
	 * @param string $jwt Compact JWT.
	 * @return array
	 */
	private function jwt_payload( string $jwt ): array {
		$parts = \explode( '.', $jwt );
		$this->assertCount( 3, $parts, 'Client assertion must be a compact JWT.' );

		$payload = \base64_decode( \strtr( $parts[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertIsString( $payload, 'JWT payload must be base64url.' );

		return (array) \json_decode( $payload, true );
	}
}
