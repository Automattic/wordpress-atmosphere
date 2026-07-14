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
	 * Reflection-driven regression test for `Encryption::key()`.
	 *
	 * Invokes the private method directly so the test exercises the
	 * production code path. The previous implementation referenced
	 * `AUTH_KEY` and `AUTH_SALT` unconditionally and fataled on sites
	 * that don't define them; this assertion would have caught that.
	 *
	 * Back-compat with existing encrypted data is guaranteed by the
	 * production conditional preferring the legacy concatenation when
	 * both constants are defined and non-empty, not by `wp_salt()`
	 * equivalence — `wp_salt()` deliberately ignores placeholder values
	 * like `'put your unique phrase here'` and falls back to options,
	 * so a direct equivalence assertion would be wrong in those envs.
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
