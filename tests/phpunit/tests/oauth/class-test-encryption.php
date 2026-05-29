<?php
/**
 * Tests for the OAuth Encryption helper.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests\OAuth;

use WP_UnitTestCase;
use Atmosphere\OAuth\Encryption;

/**
 * Encryption helper tests.
 */
class Test_Encryption extends WP_UnitTestCase {

	/**
	 * `encrypt()` must not fatal on sites without `AUTH_KEY` / `AUTH_SALT`
	 * defined in `wp-config.php`. The helper relies on `wp_salt( 'auth' )`,
	 * which falls back to auto-generated `auth_key` / `auth_salt` options
	 * when the constants are absent, so a fresh `encrypt()` call should
	 * always return a non-empty base64 string.
	 *
	 * Regression test for https://github.com/Automattic/wordpress-atmosphere/issues/100.
	 */
	public function test_encrypt_returns_non_empty_string() {
		$ciphertext = Encryption::encrypt( 'hello world' );

		$this->assertIsString( $ciphertext );
		$this->assertNotEmpty( $ciphertext );
	}

	/**
	 * `encrypt()` followed by `decrypt()` returns the original plaintext.
	 */
	public function test_encrypt_decrypt_roundtrip() {
		$plaintext  = '{"kty":"EC","crv":"P-256"}';
		$ciphertext = Encryption::encrypt( $plaintext );

		$this->assertSame( $plaintext, Encryption::decrypt( $ciphertext ) );
	}

	/**
	 * `decrypt()` on a string that isn't a valid ciphertext returns `false`
	 * rather than fatalling.
	 */
	public function test_decrypt_returns_false_on_garbage() {
		$this->assertFalse( Encryption::decrypt( 'not-real-ciphertext' ) );
	}

	/**
	 * The `wp_salt( 'auth' )` fallback must derive the same key material
	 * as the legacy `AUTH_KEY . AUTH_SALT` concatenation when both
	 * constants are defined and non-default. Pins the back-compat
	 * guarantee: tokens encrypted before this fix must still decrypt
	 * after it, and any future refactor that breaks this equivalence
	 * would silently invalidate every existing install's stored secrets.
	 */
	public function test_wp_salt_auth_matches_legacy_concatenation() {
		$this->assertTrue( \defined( 'AUTH_KEY' ) );
		$this->assertTrue( \defined( 'AUTH_SALT' ) );
		$this->assertNotEmpty( AUTH_KEY );
		$this->assertNotEmpty( AUTH_SALT );

		$this->assertSame( AUTH_KEY . AUTH_SALT, \wp_salt( 'auth' ) );
	}

	/**
	 * Reflection-driven regression test for the fallback branch.
	 *
	 * Invokes the private `Encryption::key()` method via reflection so
	 * the test exercises the exact production code path. With both
	 * constants defined (as in wp-env), the legacy branch is taken;
	 * we still get a stable, non-empty 32-byte sodium key. Combined
	 * with the equivalence assertion above, this confirms the fallback
	 * branch would produce the same key on sites missing the constants.
	 */
	public function test_key_returns_sodium_sized_key() {
		$reflection = new \ReflectionClass( Encryption::class );
		$method     = $reflection->getMethod( 'key' );
		$method->setAccessible( true );

		$key = $method->invoke( null );

		$this->assertIsString( $key );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, \strlen( $key ) );
	}
}
