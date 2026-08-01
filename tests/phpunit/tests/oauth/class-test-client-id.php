<?php
/**
 * Tests for `Client::client_id()`.
 *
 * AT Protocol requires the client_id to be an https URL. `rest_url()`
 * inherits its scheme from the request context, so on a site behind a
 * TLS-terminating proxy a CLI/cron request produces an http client_id
 * that the auth server rejects as "Invalid client ID". `client_id()`
 * must force https regardless of context.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests\OAuth;

use WP_UnitTestCase;
use Atmosphere\OAuth\Client;

/**
 * `Client::client_id()` scheme tests.
 */
class Test_Client_Id extends WP_UnitTestCase {

	/**
	 * Remove the rest_url override between tests.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'rest_url' );
		parent::tear_down();
	}

	/**
	 * An http `rest_url()` (a CLI/cron request behind a TLS-terminating
	 * proxy) is forced to https for the client_id.
	 */
	public function test_client_id_forces_https_on_http_rest_url() {
		\add_filter(
			'rest_url',
			static fn() => 'http://proxied.example/wp-json/atmosphere/v1/client-metadata'
		);

		$this->assertSame(
			'https://proxied.example/wp-json/atmosphere/v1/client-metadata',
			Client::client_id()
		);
	}

	/**
	 * An https `rest_url()` is passed through unchanged.
	 */
	public function test_client_id_keeps_https_rest_url() {
		\add_filter(
			'rest_url',
			static fn() => 'https://proxied.example/wp-json/atmosphere/v1/client-metadata'
		);

		$this->assertSame(
			'https://proxied.example/wp-json/atmosphere/v1/client-metadata',
			Client::client_id()
		);
	}
}
