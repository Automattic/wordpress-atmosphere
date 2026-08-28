<?php
/**
 * Tests for the Site Health integration.
 *
 * The connection test must map each connection state to the right
 * severity and copy — in particular, a key-change failure must guide
 * the user toward the dedicated encryption key constant so the next
 * salt rotation doesn't break the connection again.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Encryption;
use Atmosphere\WP_Admin\Health_Check;

/**
 * Health check tests.
 */
class Test_Health_Check extends \WP_UnitTestCase {

	/**
	 * Clean options after each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( Client::DISCONNECTED_OPTION );

		parent::tear_down();
	}

	/**
	 * Seed identity + a live connection.
	 *
	 * @param array $overrides Connection fields to override.
	 */
	private function seed_connection( array $overrides = array() ): void {
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			true
		);

		\update_option(
			'atmosphere_connection',
			\array_merge(
				array(
					'did'          => 'did:plc:test',
					'handle'       => 'example.com',
					'pds_endpoint' => 'https://pds.example.com',
					'access_token' => Encryption::encrypt( 'access-token' ),
					'needs_reauth' => false,
				),
				$overrides
			),
			false
		);
	}

	/**
	 * The test is registered as a direct (non-async) Site Health test.
	 */
	public function test_registers_direct_test() {
		$tests = Health_Check::add_tests(
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		$this->assertArrayHasKey( 'atmosphere_test_connection', $tests['direct'] );
		$this->assertArrayHasKey( 'atmosphere_test_threadgate_scope', $tests['direct'] );
	}

	/**
	 * A live connection reports "good".
	 */
	public function test_connected_site_is_good() {
		$this->seed_connection();

		$result = Health_Check::test_connection();

		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * A never-connected site is a recommendation, not a critical issue.
	 */
	public function test_never_connected_site_is_recommended() {
		$result = Health_Check::test_connection();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'not connected', $result['label'] );
	}

	/**
	 * An operator-initiated disconnect is a chosen state — recommended,
	 * with copy that doesn't claim a failure.
	 */
	public function test_operator_disconnect_is_recommended() {
		$this->seed_connection();
		\delete_option( 'atmosphere_connection' );
		\update_option( Client::DISCONNECTED_OPTION, \time(), false );

		$result = Health_Check::test_connection();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'disconnected', $result['label'] );
	}

	/**
	 * A key-change reauth is critical and guides the user to the
	 * dedicated encryption key constant (the constant is not defined in
	 * the test environment, so the "add it" branch renders).
	 */
	public function test_key_changed_is_critical_and_recommends_the_constant() {
		$this->seed_connection(
			array(
				'access_token'  => '',
				'needs_reauth'  => true,
				'reauth_reason' => 'key_changed',
			)
		);

		$result = Health_Check::test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'security keys have changed', $result['description'] );
		$this->assertStringContainsString( 'ATMOSPHERE_ENCRYPTION_KEY', $result['description'] );
		$this->assertStringContainsString( 'define(', $result['description'] );
		$this->assertStringContainsString( 'options-general.php?page=atmosphere', $result['actions'] );

		/*
		 * The snippet must contain a freshly generated key, not a
		 * static placeholder — two renders differ only in the key.
		 */
		$this->assertNotSame( $result['description'], Health_Check::test_connection()['description'] );
	}

	/**
	 * A generic decrypt failure is critical but must not blame the
	 * security keys (the fingerprint matched — the data is corrupt).
	 */
	public function test_decrypt_failed_is_critical_without_key_guidance() {
		$this->seed_connection(
			array(
				'access_token'  => '',
				'needs_reauth'  => true,
				'reauth_reason' => 'decrypt_failed',
			)
		);

		$result = Health_Check::test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringNotContainsString( 'ATMOSPHERE_ENCRYPTION_KEY', $result['description'] );
		$this->assertStringContainsString( 'no longer read', $result['description'] );
	}

	/**
	 * A legacy needs_reauth row without a reason marker falls back to
	 * the session-expired copy.
	 */
	public function test_expired_session_is_critical_with_expiry_copy() {
		$this->seed_connection(
			array(
				'access_token' => '',
				'needs_reauth' => true,
			)
		);

		$result = Health_Check::test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'session has expired', $result['description'] );
	}

	/**
	 * The debug panel reports the connection status and the encryption
	 * key source (the constant is undefined in the test environment, so
	 * the source is the WordPress security keys).
	 */
	public function test_debug_information_reports_status_and_key_source() {
		$this->seed_connection(
			array(
				'access_token'  => '',
				'needs_reauth'  => true,
				'reauth_reason' => 'key_changed',
			)
		);

		$info = Health_Check::debug_information( array() );

		$this->assertArrayHasKey( 'atmosphere', $info );

		$fields = $info['atmosphere']['fields'];
		$this->assertStringContainsString( 'security keys changed', $fields['connection_status']['value'] );
		$this->assertSame( 'example.com', $fields['handle']['value'] );
		$this->assertStringContainsString( 'AUTH_KEY', $fields['encryption_key']['value'] );
	}

	/**
	 * On a healthy connection the debug panel reports "Connected" plus
	 * the publishing configuration (auto-publish state, post types).
	 */
	public function test_debug_information_reports_connected_state_and_publishing_config() {
		$this->seed_connection();
		\update_option( 'atmosphere_support_post_types', array( 'post' ) );

		$info = Health_Check::debug_information( array() );

		$fields = $info['atmosphere']['fields'];
		$this->assertSame( 'Connected', $fields['connection_status']['value'] );
		$this->assertSame( 'Enabled', $fields['auto_publish']['value'] );
		$this->assertStringContainsString( 'post', $fields['post_types']['value'] );

		\delete_option( 'atmosphere_support_post_types' );
	}

	/**
	 * A connection granted before the threadgate scope existed is
	 * flagged as recommended, with a reconnect link. Posts still publish,
	 * so it must not be critical.
	 */
	public function test_threadgate_scope_recommends_reconnect_when_missing() {
		$this->seed_connection( array( 'scope' => 'atproto repo:app.bsky.feed.post' ) );

		$result = Health_Check::test_threadgate_scope();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'reconnect', $result['label'] );
		$this->assertStringContainsString( 'options-general.php?page=atmosphere', $result['actions'] );
	}

	/**
	 * A grant that includes the scope passes.
	 */
	public function test_threadgate_scope_passes_when_granted() {
		$this->seed_connection( array( 'scope' => Client::scopes() ) );

		$this->assertSame( 'good', Health_Check::test_threadgate_scope()['status'] );
	}

	/**
	 * An unknown grant is not reported as a problem.
	 */
	public function test_threadgate_scope_passes_when_unknown() {
		$this->seed_connection();

		$this->assertSame( 'good', Health_Check::test_threadgate_scope()['status'] );
	}
}
