<?php
/**
 * Tests for the DPoP class.
 *
 * Verifies ES256 JWT signing produces valid, verifiable tokens
 * using PHP's native OpenSSL extension.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\OAuth\DPoP;

/**
 * DPoP tests.
 */
class Test_DPoP extends WP_UnitTestCase {

	/**
	 * Test that generate_key() produces a valid P-256 JWK.
	 */
	public function test_generate_key_produces_valid_jwk() {
		$jwk = DPoP::generate_key();

		$this->assertNotWPError( $jwk, 'Healthy OpenSSL builds must return a JWK, not an error.' );
		$this->assertIsArray( $jwk );
		$this->assertSame( 'EC', $jwk['kty'] );
		$this->assertSame( 'P-256', $jwk['crv'] );
		$this->assertArrayHasKey( 'x', $jwk );
		$this->assertArrayHasKey( 'y', $jwk );
		$this->assertArrayHasKey( 'd', $jwk );
	}

	/**
	 * Test round-trip: generate key, create proof, verify signature.
	 */
	public function test_create_proof_produces_verifiable_jwt() {
		$jwk = DPoP::generate_key();

		$proof = DPoP::create_proof(
			$jwk,
			'POST',
			'https://pds.example.com/xrpc/com.atproto.repo.applyWrites',
			'test-nonce'
		);

		$this->assertIsString( $proof );

		// Parse compact JWS: header.payload.signature.
		$parts = \explode( '.', $proof );
		$this->assertCount( 3, $parts, 'JWT must have three dot-separated parts.' );

		// Decode and verify header.
		$header = \json_decode( $this->base64url_decode( $parts[0] ), true );
		$this->assertSame( 'ES256', $header['alg'] );
		$this->assertSame( 'dpop+jwt', $header['typ'] );
		$this->assertArrayHasKey( 'jwk', $header );
		$this->assertArrayNotHasKey( 'd', $header['jwk'], 'Public JWK must not contain private key.' );

		// Decode and verify payload.
		$payload = \json_decode( $this->base64url_decode( $parts[1] ), true );
		$this->assertSame( 'POST', $payload['htm'] );
		$this->assertSame( 'https://pds.example.com/xrpc/com.atproto.repo.applyWrites', $payload['htu'] );
		$this->assertSame( 'test-nonce', $payload['nonce'] );
		$this->assertArrayHasKey( 'jti', $payload );
		$this->assertArrayHasKey( 'iat', $payload );
		$this->assertArrayHasKey( 'exp', $payload );
		$this->assertSame( $payload['iat'] + 60, $payload['exp'] );

		// Verify the ES256 signature with OpenSSL.
		$signing_input = $parts[0] . '.' . $parts[1];
		$raw_signature = $this->base64url_decode( $parts[2] );

		// Convert raw R‖S back to DER for OpenSSL verification.
		$der_signature = $this->raw_to_der( $raw_signature );

		// Reconstruct public key PEM from the JWK in the header.
		$pub_pem = $this->jwk_to_public_pem( $header['jwk'] );

		$result = \openssl_verify( $signing_input, $der_signature, $pub_pem, OPENSSL_ALGO_SHA256 );
		$this->assertSame( 1, $result, 'OpenSSL signature verification must succeed.' );
	}

	/**
	 * Test that create_proof includes ath claim when access token is provided.
	 */
	public function test_create_proof_includes_ath_claim() {
		$jwk = DPoP::generate_key();

		$proof = DPoP::create_proof(
			$jwk,
			'GET',
			'https://pds.example.com/xrpc/com.atproto.repo.getRecord',
			'nonce-123',
			'my-access-token'
		);

		$parts   = \explode( '.', $proof );
		$payload = \json_decode( $this->base64url_decode( $parts[1] ), true );

		$this->assertArrayHasKey( 'ath', $payload );

		// ath must be base64url(SHA-256(access_token)).
		$expected_ath = \rtrim( \strtr( \base64_encode( \hash( 'sha256', 'my-access-token', true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( $expected_ath, $payload['ath'] );
	}

	/**
	 * Test that create_proof returns false for malformed JWK.
	 */
	public function test_create_proof_returns_false_for_malformed_jwk() {
		$result = DPoP::create_proof(
			array(
				'kty' => 'EC',
				'crv' => 'P-256',
			),
			'POST',
			'https://pds.example.com/xrpc/test',
			'nonce'
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test that create_proof() returns false when its try block throws.
	 *
	 * Omitting the nonce argument routes create_proof() through the
	 * Nonce_Storage transient lookup inside its try block — the only
	 * injectable throw site — so the short-circuit filter below forces
	 * the catch path to run.
	 */
	public function test_create_proof_returns_false_when_nonce_lookup_throws() {
		$jwk = DPoP::generate_key();
		$url = 'https://pds.example.com/xrpc/test';

		\add_filter(
			'pre_transient_atmo_dpop_nonce_' . \md5( \Atmosphere\get_did() . '|' . $url ),
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		// Swallow the E_USER_NOTICE wp_trigger_error() raises under WP_DEBUG.
		\set_error_handler( '__return_true', E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			$result = DPoP::create_proof( $jwk, 'POST', $url );
		} finally {
			\restore_error_handler();
		}

		$this->assertFalse( $result );
	}

	/**
	 * Test that the logged throwable summary never includes the message.
	 *
	 * The create_proof() catch block runs with the private-key JWK in
	 * scope, so the line it logs must carry only the exception class and
	 * throw site — never the exception message, which is not guaranteed
	 * to be free of key material.
	 */
	public function test_throwable_log_description_omits_message() {
		$marker    = 'd=base64url-private-key-material';
		$exception = new \RuntimeException( $marker );

		$method      = new \ReflectionMethod( DPoP::class, 'describe_throwable' );
		$description = $method->invoke( null, $exception );

		$this->assertStringContainsString( 'RuntimeException', $description );
		$this->assertStringContainsString( \basename( __FILE__ ) . ':', $description );
		$this->assertStringNotContainsString(
			$marker,
			$description,
			'Exception messages must never reach the log from the DPoP signing path.'
		);
	}

	/**
	 * Base64url decode helper.
	 *
	 * @param string $data Base64url-encoded string.
	 * @return string Decoded bytes.
	 */
	private function base64url_decode( string $data ): string {
		return \base64_decode( \strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Convert raw R‖S signature to DER format for OpenSSL verification.
	 *
	 * @param string $raw Raw signature (64 bytes for P-256).
	 * @return string DER-encoded signature.
	 */
	private function raw_to_der( string $raw ): string {
		$half = \intdiv( \strlen( $raw ), 2 );
		$r    = \ltrim( \substr( $raw, 0, $half ), "\x00" );
		$s    = \ltrim( \substr( $raw, $half ), "\x00" );

		// Add leading zero if high bit is set (ASN.1 signed integer).
		if ( \ord( $r[0] ) >= 0x80 ) {
			$r = "\x00" . $r;
		}
		if ( \ord( $s[0] ) >= 0x80 ) {
			$s = "\x00" . $s;
		}

		$r_der = "\x02" . \chr( \strlen( $r ) ) . $r;
		$s_der = "\x02" . \chr( \strlen( $s ) ) . $s;

		$sequence = $r_der . $s_der;

		return "\x30" . \chr( \strlen( $sequence ) ) . $sequence;
	}

	/**
	 * Convert a public JWK to PEM format for OpenSSL verification.
	 *
	 * @param array $jwk Public JWK with x and y coordinates.
	 * @return string PEM-encoded public key.
	 */
	private function jwk_to_public_pem( array $jwk ): string {
		$x = \base64_decode( \strtr( $jwk['x'], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$y = \base64_decode( \strtr( $jwk['y'], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$x = \str_pad( $x, 32, "\0", STR_PAD_LEFT );
		$y = \str_pad( $y, 32, "\0", STR_PAD_LEFT );

		// Uncompressed point: 0x04 || x || y.
		$point = "\x04" . $x . $y;

		/*
		 * SubjectPublicKeyInfo ::= SEQUENCE {
		 *   algorithm  AlgorithmIdentifier (EC + prime256v1),
		 *   subjectPublicKey BIT STRING
		 * }
		 */
		// AlgorithmIdentifier: OID ecPublicKey (1.2.840.10045.2.1) + OID prime256v1 (1.2.840.10045.3.1.7).
		$algorithm = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

		// BIT STRING wrapping the uncompressed point.
		$bit_string = "\x03" . \chr( \strlen( $point ) + 1 ) . "\x00" . $point;

		// SEQUENCE wrapper.
		$der = $algorithm . $bit_string;
		$der = "\x30" . \chr( \strlen( $der ) ) . $der;

		return "-----BEGIN PUBLIC KEY-----\n"
			. \chunk_split( \base64_encode( $der ), 64, "\n" ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			. '-----END PUBLIC KEY-----';
	}
}
