<?php
/**
 * Tests for the reply-restriction scope gate.
 *
 * Reply restrictions need an OAuth scope that connections made before
 * this release never asked for. The gate has to get three states right:
 * a scope that is known and missing (reconnect needed), a scope that is
 * known and present (fine), and a scope that is simply unknown because
 * the connection predates scope storage. The last one must NOT read as
 * missing, or every existing site loses the feature until its next
 * token refresh.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests;

use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Encryption;
use Atmosphere\WP_Admin\Admin;
use function Atmosphere\connection_scopes;
use function Atmosphere\threadgate_needs_reconnect;

/**
 * Scope gate tests.
 */
class Test_Threadgate_Scope extends \WP_UnitTestCase {

	/**
	 * Clean options and screen state after each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\wp_set_current_user( 0 );
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	/**
	 * Seed identity plus a live connection.
	 *
	 * @param array $overrides Connection fields to override; pass
	 *                         `'scope' => null` to leave it unset.
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

		$connection = \array_merge(
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
				'access_token' => Encryption::encrypt( 'access-token' ),
				'needs_reauth' => false,
			),
			$overrides
		);

		if ( \array_key_exists( 'scope', $connection ) && null === $connection['scope'] ) {
			unset( $connection['scope'] );
		}

		\update_option( 'atmosphere_connection', $connection, false );
	}

	/**
	 * Every scope the plugin requests, as a granted-scope string.
	 *
	 * @return string
	 */
	private function full_scope(): string {
		return Client::scopes();
	}

	/**
	 * The full set with the threadgate scope taken out: what a connection
	 * made before this release would have been granted.
	 *
	 * @return string
	 */
	private function legacy_scope(): string {
		return \implode(
			' ',
			\array_diff( \explode( ' ', $this->full_scope() ), array( Client::THREADGATE_SCOPE ) )
		);
	}

	/**
	 * The requested scope list carries the threadgate scope.
	 */
	public function test_client_requests_the_threadgate_scope() {
		$this->assertContains( Client::THREADGATE_SCOPE, \explode( ' ', Client::scopes() ) );
	}

	/**
	 * No stored scope means "unknown", never an empty grant.
	 */
	public function test_connection_scopes_is_null_when_unknown() {
		$this->seed_connection( array( 'scope' => null ) );
		$this->assertNull( connection_scopes() );

		$this->seed_connection( array( 'scope' => '' ) );
		$this->assertNull( connection_scopes(), 'An empty string is also unknown.' );
	}

	/**
	 * A stored scope splits into tokens.
	 */
	public function test_connection_scopes_reads_the_stored_grant() {
		$this->seed_connection( array( 'scope' => 'atproto repo:app.bsky.feed.post' ) );

		$this->assertSame( array( 'atproto', 'repo:app.bsky.feed.post' ), connection_scopes() );
	}

	/**
	 * Not connected: nothing to reconnect.
	 */
	public function test_no_reconnect_needed_when_not_connected() {
		$this->assertFalse( threadgate_needs_reconnect() );
	}

	/**
	 * Unknown scope is allowed through, so pre-existing sites keep the
	 * feature until a refresh tells us otherwise.
	 */
	public function test_no_reconnect_needed_when_scope_is_unknown() {
		$this->seed_connection( array( 'scope' => null ) );

		$this->assertFalse( threadgate_needs_reconnect() );
	}

	/**
	 * A grant that includes the scope needs nothing.
	 */
	public function test_no_reconnect_needed_when_scope_is_granted() {
		$this->seed_connection( array( 'scope' => $this->full_scope() ) );

		$this->assertFalse( threadgate_needs_reconnect() );
	}

	/**
	 * A grant that is known and lacks the scope is the one case that
	 * needs a reconnect.
	 */
	public function test_reconnect_needed_when_scope_is_known_and_missing() {
		$this->seed_connection( array( 'scope' => $this->legacy_scope() ) );

		$this->assertTrue( threadgate_needs_reconnect() );
	}

	/**
	 * Capture the settings-page notice.
	 *
	 * @param string $screen_id Screen to pretend to be on.
	 * @return string
	 */
	private function render_notice( string $screen_id ): string {
		\set_current_screen( $screen_id );

		\ob_start();
		Admin::maybe_render_threadgate_scope_notice();

		return (string) \ob_get_clean();
	}

	/**
	 * The notice appears on the settings page for an admin when a
	 * reconnect is needed.
	 */
	public function test_notice_renders_on_settings_page_when_needed() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->seed_connection( array( 'scope' => $this->legacy_scope() ) );

		$this->assertStringContainsString( 'Reply restrictions need a reconnect', $this->render_notice( 'settings_page_atmosphere' ) );
	}

	/**
	 * Nothing is broken site-wide, so the notice stays off other screens.
	 */
	public function test_notice_stays_off_other_screens() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->seed_connection( array( 'scope' => $this->legacy_scope() ) );

		$this->assertSame( '', $this->render_notice( 'dashboard' ) );
	}

	/**
	 * A connection that already has the scope gets no notice.
	 */
	public function test_notice_is_silent_when_scope_is_granted() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->seed_connection( array( 'scope' => $this->full_scope() ) );

		$this->assertSame( '', $this->render_notice( 'settings_page_atmosphere' ) );
	}

	/**
	 * Only someone who can reconnect is told to.
	 */
	public function test_notice_is_hidden_from_non_admins() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->seed_connection( array( 'scope' => $this->legacy_scope() ) );

		$this->assertSame( '', $this->render_notice( 'settings_page_atmosphere' ) );
	}
}
