<?php
/**
 * Tests for `Client::redirect_uri()` filter-return validation.
 *
 * A third-party filter that returns a non-admin URL would otherwise
 * redirect the OAuth authorization code (and the user) off-site —
 * a token-leak primitive. The filter return is validated and falls
 * back to the default admin URL on anything suspicious.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests\OAuth;

use WP_UnitTestCase;
use Atmosphere\OAuth\Client;

/**
 * `atmosphere_oauth_redirect_uri` filter validation tests.
 */
class Test_Redirect_Uri_Filter extends WP_UnitTestCase {

	/**
	 * Remove any filters that tests in this class added.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_oauth_redirect_uri' );
		parent::tear_down();
	}

	/**
	 * Default behaviour: no filter → admin URL.
	 */
	public function test_default_redirect_uri_is_admin_url() {
		$uri = Client::redirect_uri();

		$this->assertStringStartsWith( \admin_url( '' ), $uri );
		$this->assertStringContainsString( 'page=atmosphere', $uri );
	}

	/**
	 * A filter that points at an external host is rejected; the
	 * default admin URL wins.
	 */
	public function test_external_url_filter_is_rejected() {
		\add_filter(
			'atmosphere_oauth_redirect_uri',
			static fn() => 'https://evil.example.com/oauth/callback'
		);

		$uri = Client::redirect_uri();

		$this->assertStringStartsWith( \admin_url( '' ), $uri );
		$this->assertStringNotContainsString( 'evil.example.com', $uri );
	}

	/**
	 * A filter that returns a string starting with this site's admin
	 * URL is accepted — that's the intended extension point for
	 * wrapper plugins.
	 */
	public function test_in_site_admin_filter_is_accepted() {
		$override = \admin_url( 'admin.php?page=my-wrapper-plugin' );

		\add_filter(
			'atmosphere_oauth_redirect_uri',
			static fn() => $override
		);

		$this->assertSame( $override, Client::redirect_uri() );
	}

	/**
	 * Non-string returns (null, false, array, object) fall back to
	 * the default. This prevents type coercion bugs from breaking
	 * the OAuth flow and prevents an attacker-controlled non-string
	 * from leaking through.
	 */
	public function test_non_string_filter_returns_fall_back() {
		\add_filter( 'atmosphere_oauth_redirect_uri', static fn() => null );
		$this->assertStringStartsWith( \admin_url( '' ), Client::redirect_uri() );

		\remove_all_filters( 'atmosphere_oauth_redirect_uri' );

		\add_filter( 'atmosphere_oauth_redirect_uri', static fn() => array( 'not', 'a', 'url' ) );
		$this->assertStringStartsWith( \admin_url( '' ), Client::redirect_uri() );

		\remove_all_filters( 'atmosphere_oauth_redirect_uri' );

		\add_filter( 'atmosphere_oauth_redirect_uri', static fn() => false );
		$this->assertStringStartsWith( \admin_url( '' ), Client::redirect_uri() );
	}

	/**
	 * Empty-string return falls back to the default — a filter that
	 * neutered itself shouldn't break OAuth.
	 */
	public function test_empty_string_filter_falls_back() {
		\add_filter( 'atmosphere_oauth_redirect_uri', static fn() => '' );

		$this->assertStringStartsWith( \admin_url( '' ), Client::redirect_uri() );
	}

	/**
	 * A `javascript:` / `data:` URI does not start with `admin_url()`
	 * and is rejected.
	 */
	public function test_exotic_scheme_filter_is_rejected() {
		\add_filter(
			'atmosphere_oauth_redirect_uri',
			static fn() => 'javascript:alert(1)'
		);

		$uri = Client::redirect_uri();

		$this->assertStringStartsWith( \admin_url( '' ), $uri );
		$this->assertStringNotContainsString( 'javascript', $uri );
	}
}
