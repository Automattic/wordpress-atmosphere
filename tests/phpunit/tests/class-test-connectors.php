<?php
/**
 * Tests for the WordPress 7.0 Connectors API integration.
 *
 * Covers the connector registration payload and the hydration data handed to
 * the card script module across the connection states the card renders
 * (disconnected, connected, needs-reauth).
 *
 * @package Atmosphere
 * @group atmosphere
 * @group connectors
 */

namespace Atmosphere\Tests;

use Atmosphere\Connectors;
use WP_UnitTestCase;

/**
 * Connectors integration tests.
 *
 * @coversDefaultClass \Atmosphere\Connectors
 */
class Test_Connectors extends WP_UnitTestCase {

	/**
	 * Reset connection state between tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		parent::tear_down();
	}

	/**
	 * Put the site into a connected state.
	 */
	private function connect(): void {
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.example.com',
				'pds_endpoint' => 'https://pds.example.com',
				'access_token' => 'live-token',
			)
		);
	}

	/**
	 * A registry stub that records the id and args it was handed.
	 *
	 * @return object Registry double exposing `->id` and `->args`.
	 */
	private function registry_spy() {
		return new class() {
			/**
			 * Captured connector id.
			 *
			 * @var string
			 */
			public $id = '';

			/**
			 * Captured connector args.
			 *
			 * @var array
			 */
			public $args = array();

			/**
			 * Record a registration call.
			 *
			 * @param string $id   Connector id.
			 * @param array  $args Connector args.
			 */
			public function register( $id, $args ): void {
				$this->id   = $id;
				$this->args = $args;
			}
		};
	}

	/**
	 * `register()` hands the registry an `atmosphere` connector with the
	 * `none` auth method — the contract that keeps core from rendering its
	 * API-key form and lets our script module own the card.
	 *
	 * @covers ::register
	 */
	public function test_register_declares_none_auth_connector() {
		$registry = $this->registry_spy();

		Connectors::register( $registry );

		$this->assertSame( 'atmosphere', $registry->id );
		$this->assertSame( 'none', $registry->args['authentication']['method'] );
		$this->assertSame( 'cloud_service', $registry->args['type'] );
		$this->assertArrayHasKey( 'name', $registry->args );
		$this->assertArrayHasKey( 'description', $registry->args );
		$this->assertStringContainsString( 'atmosphere.svg', $registry->args['logo_url'] );
		$this->assertStringEndsWith( '/atmosphere.php', $registry->args['plugin']['file'] );
	}

	/**
	 * On a never-connected site the payload reports the disconnected state
	 * and carries no handle or profile URL, but still ships the REST wiring
	 * the card needs to start a connection.
	 *
	 * @covers ::get_connector_data
	 */
	public function test_get_connector_data_disconnected() {
		$data = Connectors::get_connector_data( array() );

		$this->assertFalse( $data['isConnected'] );
		$this->assertFalse( $data['needsReauth'] );
		$this->assertSame( '', $data['handle'] );
		$this->assertSame( '', $data['profileUrl'] );
		$this->assertStringContainsString( 'admin/connection/authorize', $data['authorizePath'] );
		$this->assertStringContainsString( 'admin/connection/disconnect', $data['disconnectPath'] );
		$this->assertNotEmpty( $data['restNonce'] );
	}

	/**
	 * When connected the payload reports it, echoes the handle, and builds a
	 * profile URL through `appview_url()`.
	 *
	 * @covers ::get_connector_data
	 */
	public function test_get_connector_data_connected() {
		$this->connect();

		$data = Connectors::get_connector_data( array() );

		$this->assertTrue( $data['isConnected'] );
		$this->assertFalse( $data['needsReauth'] );
		$this->assertSame( 'alice.example.com', $data['handle'] );
		$this->assertStringContainsString( 'did:plc:test123', $data['profileUrl'] );
	}

	/**
	 * A flagged-for-reauth connection reports `needsReauth` (and not
	 * `isConnected`) so the card can prompt a reconnect.
	 *
	 * @covers ::get_connector_data
	 */
	public function test_get_connector_data_needs_reauth() {
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.example.com',
				'access_token' => '',
				'needs_reauth' => true,
			)
		);

		$data = Connectors::get_connector_data( array() );

		$this->assertFalse( $data['isConnected'] );
		$this->assertTrue( $data['needsReauth'] );
	}

	/**
	 * The hydration payload merges into, rather than replaces, whatever core
	 * already put on the module data.
	 *
	 * @covers ::get_connector_data
	 */
	public function test_get_connector_data_preserves_existing_keys() {
		$data = Connectors::get_connector_data( array( 'existing' => 'kept' ) );

		$this->assertSame( 'kept', $data['existing'] );
		$this->assertArrayHasKey( 'isConnected', $data );
	}

	/**
	 * The payload ships a default handle-typeahead endpoint for the card,
	 * defaulting to Bluesky's official public appview.
	 *
	 * @covers ::get_connector_data
	 */
	public function test_get_connector_data_includes_typeahead_url() {
		$data = Connectors::get_connector_data( array() );

		$this->assertArrayHasKey( 'typeaheadUrl', $data );
		$this->assertStringContainsString( 'searchActorsTypeahead', $data['typeaheadUrl'] );
		$this->assertStringContainsString( 'public.api.bsky.app', $data['typeaheadUrl'] );
	}

	/**
	 * The hydration payload carries no return target: the card only ever runs on
	 * the Connectors screen, so the server sends the flow back there on its own.
	 * The card supplies only the handle.
	 *
	 * @covers ::get_connector_data
	 */
	public function test_get_connector_data_has_no_return_target() {
		\set_current_screen( 'options-connectors' );

		$data = Connectors::get_connector_data( array() );

		$this->assertArrayNotHasKey( 'returnScreen', $data );
		$this->assertArrayNotHasKey( 'returnTo', $data );

		\set_current_screen( 'front' );
	}

	/**
	 * The card enqueues on stock core's `options-connectors.php` hook suffix.
	 *
	 * @covers ::is_connectors_screen
	 */
	public function test_is_connectors_screen_matches_core_page() {
		$this->assertTrue( Connectors::is_connectors_screen( 'options-connectors.php' ) );
	}

	/**
	 * The card also enqueues on a site running the Gutenberg plugin, where the
	 * screen is a Settings submenu — the regression this fix addresses, where the
	 * exact-match check silently bailed.
	 *
	 * @covers ::is_connectors_screen
	 */
	public function test_is_connectors_screen_matches_gutenberg_submenu() {
		$this->assertTrue( Connectors::is_connectors_screen( 'settings_page_options-connectors-wp-admin' ) );
	}

	/**
	 * The check stays scoped: unrelated admin screens never match, so the card
	 * never enqueues where it shouldn't.
	 *
	 * @covers ::is_connectors_screen
	 */
	public function test_is_connectors_screen_rejects_other_screens() {
		$this->assertFalse( Connectors::is_connectors_screen( 'options-general.php' ) );
		$this->assertFalse( Connectors::is_connectors_screen( 'settings_page_atmosphere' ) );
		$this->assertFalse( Connectors::is_connectors_screen( '' ) );
	}

	/**
	 * With no Gutenberg submenu present, the OAuth return destination falls back to
	 * core's top-level Connectors page.
	 *
	 * @covers ::screen_url
	 */
	public function test_screen_url_defaults_to_core_page() {
		$this->assertSame( \admin_url( 'options-connectors.php' ), Connectors::screen_url() );
	}

	/**
	 * On a site running the Gutenberg plugin, where the screen is a Settings submenu,
	 * the return destination resolves to that submenu URL — server-side, from the
	 * registered admin menu, never from request input.
	 *
	 * @covers ::screen_url
	 */
	public function test_screen_url_prefers_gutenberg_submenu() {
		global $submenu;
		$saved = $submenu;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture: simulate the Gutenberg plugin's Connectors submenu, restored below.
		$submenu = array(
			'options-general.php' => array(
				array( 'Settings', 'manage_options', 'options-general.php' ),
				array( 'Connectors', 'manage_options', 'options-connectors-wp-admin' ),
			),
		);

		$this->assertSame(
			\admin_url( 'options-general.php?page=options-connectors-wp-admin' ),
			Connectors::screen_url()
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the real admin menu.
		$submenu = $saved;
	}

	/**
	 * Core's own `options-connectors.php` submenu entries are not a Gutenberg
	 * submenu, so the top-level page URL is still used when only they are present.
	 *
	 * @covers ::screen_url
	 */
	public function test_screen_url_ignores_core_screen_submenu_entries() {
		global $submenu;
		$saved = $submenu;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture: simulate core's own submenu entries, restored below.
		$submenu = array(
			'options-connectors.php' => array(
				array( 'Connectors', 'manage_options', 'options-connectors.php' ),
			),
		);

		$this->assertSame( \admin_url( 'options-connectors.php' ), Connectors::screen_url() );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the real admin menu.
		$submenu = $saved;
	}
}
