<?php
/**
 * Tests for the admin notice shown when the login cannot be renewed.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use WP_UnitTestCase;
use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Encryption;
use Atmosphere\WP_Admin\Admin;

/**
 * Renewal-blocker notice tests.
 */
class Test_Renewal_Blocker_Notice extends WP_UnitTestCase {

	/**
	 * Reset request state and clean options after each test.
	 */
	public function tear_down(): void {
		\wp_set_current_user( 0 );
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( Client::REFRESH_STATUS_OPTION );

		parent::tear_down();
	}

	/**
	 * Seed a live confidential session.
	 */
	private function seed_connection(): void {
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
				'client_id'    => Client::client_id(),
			),
			false
		);
	}

	/**
	 * Record a renewal failure newer than any success.
	 *
	 * @param string $error Error code the recorder stored.
	 */
	private function record_failure( string $error ): void {
		\update_option(
			Client::REFRESH_STATUS_OPTION,
			array(
				'last_error'   => $error,
				'last_failure' => \time(),
			),
			false
		);
	}

	/**
	 * Render the notice and capture the HTML it printed.
	 *
	 * @param string $role User role to render as.
	 * @return string
	 */
	private function capture_notice( string $role ): string {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		\ob_start();
		Admin::maybe_render_renewal_blocker_notice();

		return (string) \ob_get_clean();
	}

	/**
	 * A rejected client registration is announced site-wide, with a link
	 * to Site Health for an administrator.
	 */
	public function test_rejected_client_configuration_renders_with_site_health_link() {
		$this->seed_connection();
		$this->record_failure( 'invalid_client_metadata' );

		$html = $this->capture_notice( 'administrator' );

		$this->assertStringContainsString( 'cannot be renewed', $html );
		$this->assertStringContainsString( 'rejected', $html );
		$this->assertStringContainsString( 'site-health.php', $html );
	}

	/**
	 * An unreadable signing key names its own fix.
	 */
	public function test_unreadable_signing_key_renders_the_reconnect_advice() {
		$this->seed_connection();
		$this->record_failure( 'atmosphere_client_authentication_key' );

		$html = $this->capture_notice( 'administrator' );

		$this->assertStringContainsString( 'Disconnect and connect again', $html );
		$this->assertStringContainsString( 'site-health.php', $html );
	}

	/**
	 * An author sees the notice but is sent to an administrator.
	 */
	public function test_author_is_told_to_ask_an_administrator() {
		$this->seed_connection();
		$this->record_failure( 'invalid_client' );

		$html = $this->capture_notice( 'author' );

		$this->assertStringContainsString( 'Ask an administrator', $html );
		$this->assertStringNotContainsString( 'site-health.php', $html );
	}

	/**
	 * A subscriber has nothing to do with sharing and sees nothing.
	 */
	public function test_hidden_from_a_subscriber() {
		$this->seed_connection();
		$this->record_failure( 'invalid_client' );

		$this->assertSame( '', $this->capture_notice( 'subscriber' ) );
	}

	/**
	 * A transport failure clears on its own and gets no notice; neither
	 * does a healthy session.
	 */
	public function test_silent_for_transient_failures_and_healthy_sessions() {
		$this->seed_connection();
		$this->record_failure( 'http_503' );
		$this->assertSame( '', $this->capture_notice( 'administrator' ) );

		\delete_option( Client::REFRESH_STATUS_OPTION );
		$this->assertSame( '', $this->capture_notice( 'administrator' ) );
	}

	/**
	 * A failure older than the last successful renewal is history, not a
	 * current problem.
	 */
	public function test_silent_once_a_later_renewal_succeeded() {
		$this->seed_connection();
		\update_option(
			Client::REFRESH_STATUS_OPTION,
			array(
				'last_error'   => 'invalid_client',
				'last_failure' => \time() - HOUR_IN_SECONDS,
				'last_success' => \time(),
			),
			false
		);

		$this->assertSame( '', $this->capture_notice( 'administrator' ) );
	}
}
