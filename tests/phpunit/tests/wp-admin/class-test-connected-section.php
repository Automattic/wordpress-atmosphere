<?php
/**
 * Tests for the connected state of the settings page.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Encryption;
use Atmosphere\WP_Admin\Settings_Fields;

/**
 * Connected-section rendering tests.
 */
class Test_Connected_Section extends \WP_UnitTestCase {

	/**
	 * Remove the seeded connection.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_connection' );
		parent::tear_down();
	}

	/**
	 * Seed a live connection and render the section.
	 *
	 * @param string $client_id Stored client ID; empty for a legacy session.
	 * @return string
	 */
	private function render( string $client_id ): string {
		\update_option(
			'atmosphere_identity',
			array(
				'did'    => 'did:plc:test',
				'handle' => 'example.com',
			),
			false
		);
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'access_token' => Encryption::encrypt( 'access-token' ),
				'needs_reauth' => false,
				'client_id'    => $client_id,
			),
			false
		);

		\ob_start();
		Settings_Fields::render_connected_section();

		return (string) \ob_get_clean();
	}

	/**
	 * A legacy session is told to reconnect once.
	 */
	public function test_legacy_session_is_asked_to_reconnect_once() {
		$this->assertStringContainsString( 'expires every two weeks', $this->render( '' ) );
	}

	/**
	 * A confidential session gets no such hint.
	 */
	public function test_confidential_session_shows_no_hint() {
		$this->assertStringNotContainsString( 'expires every two weeks', $this->render( Client::client_id() ) );
	}
}
