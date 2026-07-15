<?php
/**
 * Tests for hiding the ATmosphere settings screen via the
 * `atmosphere_show_settings_page` filter.
 *
 * A third-party plugin that drives the AT Protocol connection through the
 * Settings → Connectors screen can return false from the filter to hide
 * Settings → ATmosphere. When hidden, the plugin-row Settings shortcut turns
 * into a plain label and the reauth notice — whose only call to action links
 * to the now-hidden page — is suppressed.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\OAuth\Client;
use Atmosphere\WP_Admin\Admin;
use WP_UnitTestCase;

/**
 * Settings-page visibility filter tests.
 */
class Test_Settings_Page_Visibility extends WP_UnitTestCase {

	/**
	 * Snapshot of the admin-menu globals the menu tests mutate.
	 *
	 * @var array<string, mixed>
	 */
	private array $menu_globals = array();

	/**
	 * Load the admin menu functions and snapshot the globals they touch.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->menu_globals = array(
			'menu'              => $GLOBALS['menu'] ?? null,
			'submenu'           => $GLOBALS['submenu'] ?? null,
			'_registered_pages' => $GLOBALS['_registered_pages'] ?? null,
			'_parent_pages'     => $GLOBALS['_parent_pages'] ?? null,
		);
	}

	/**
	 * Remove any visibility filter, restore menu globals, and reset request
	 * state after each test.
	 */
	public function tear_down(): void {
		foreach ( $this->menu_globals as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}

		\remove_all_filters( 'atmosphere_show_settings_page' );
		\wp_set_current_user( 0 );
		\delete_option( 'atmosphere_identity' );
		\delete_option( Client::DISCONNECTED_OPTION );

		parent::tear_down();
	}

	/**
	 * Collect the submenu slugs registered under Settings.
	 *
	 * @return string[] Menu slugs currently under `options-general.php`.
	 */
	private function settings_submenu_slugs(): array {
		$slugs = array();
		foreach ( (array) ( $GLOBALS['submenu']['options-general.php'] ?? array() ) as $item ) {
			$slugs[] = $item[2];
		}
		return $slugs;
	}

	/**
	 * The settings screen is visible by default.
	 */
	public function test_visible_by_default(): void {
		$this->assertTrue( Admin::is_settings_page_visible() );
	}

	/**
	 * Returning false from the filter hides the settings screen.
	 */
	public function test_filter_can_hide_settings_page(): void {
		\add_filter( 'atmosphere_show_settings_page', '__return_false' );

		$this->assertFalse( Admin::is_settings_page_visible() );
	}

	/**
	 * When visible, the plugin row gets a real Settings link pointing at the
	 * settings page.
	 */
	public function test_action_links_render_settings_link_when_visible(): void {
		$links = Admin::filter_action_links( array() );

		$this->assertCount( 1, $links );
		$this->assertStringContainsString( 'options-general.php?page=atmosphere', $links[0] );
		$this->assertStringContainsString( '<a href=', $links[0] );
		$this->assertStringContainsString( 'Settings', $links[0] );
	}

	/**
	 * When hidden, the Settings link is replaced with a plain, non-linked
	 * label explaining that another plugin hid the screen.
	 */
	public function test_action_links_render_plain_label_when_hidden(): void {
		\add_filter( 'atmosphere_show_settings_page', '__return_false' );

		$links = Admin::filter_action_links( array() );

		$this->assertCount( 1, $links );
		$this->assertStringContainsString( 'Settings hidden by another plugin', $links[0] );
		$this->assertStringNotContainsString( '<a href=', $links[0] );
		$this->assertStringNotContainsString( 'options-general.php?page=atmosphere', $links[0] );
	}

	/**
	 * The shortcut is prepended, leaving existing action links (e.g.
	 * Deactivate) in place after it.
	 */
	public function test_action_links_prepend_preserves_existing_links(): void {
		$existing = array( 'deactivate' => '<a href="#">Deactivate</a>' );

		$links = Admin::filter_action_links( $existing );

		// `array_unshift` prepends the shortcut at index 0 while preserving the
		// existing string-keyed links (real action links are keyed like
		// 'deactivate'), so the shortcut lands first and the rest stay put.
		$this->assertCount( 2, $links );
		$this->assertSame( 0, \array_key_first( $links ), 'The Settings shortcut must be prepended first.' );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertStringContainsString( 'Deactivate', $links['deactivate'] );
	}

	/**
	 * The reauth notice hard-links to the settings page, so hiding the page
	 * must suppress the notice entirely — otherwise it points at a dead URL.
	 */
	public function test_reauth_notice_suppressed_when_settings_page_hidden(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		// State that would otherwise render the notice.
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			true
		);
		\update_option( Client::DISCONNECTED_OPTION, \time(), false );

		\add_filter( 'atmosphere_show_settings_page', '__return_false' );

		\ob_start();
		Admin::maybe_render_reauth_notice();
		$html = (string) \ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * Regression: hiding the screen must NOT unregister the page. The OAuth
	 * callback lands on `options-general.php?page=atmosphere`; if that page is
	 * unregistered, core's access gate `wp_die`s before `admin_init` fires and
	 * `handle_oauth_callback()` never gets to redirect to the Connectors
	 * screen. So the page hook must stay registered even while hidden.
	 */
	public function test_hidden_page_stays_registered_for_oauth_callback(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		\add_filter( 'atmosphere_show_settings_page', '__return_false' );

		Admin::add_menu();

		$hookname = \get_plugin_page_hookname( 'atmosphere', 'options-general.php' );
		$this->assertArrayHasKey(
			$hookname,
			(array) $GLOBALS['_registered_pages'],
			'Hidden settings page must remain registered so the OAuth callback URL resolves.'
		);
	}

	/**
	 * Hiding the screen removes it from the Settings menu.
	 */
	public function test_hidden_page_is_removed_from_settings_menu(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		\add_filter( 'atmosphere_show_settings_page', '__return_false' );

		Admin::add_menu();

		$this->assertNotContains( 'atmosphere', $this->settings_submenu_slugs() );
	}

	/**
	 * When visible, the screen appears in the Settings menu as usual.
	 */
	public function test_visible_page_appears_in_settings_menu(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		Admin::add_menu();

		$this->assertContains( 'atmosphere', $this->settings_submenu_slugs() );
	}

	/**
	 * A direct visit to the hidden page renders the "managed elsewhere" notice
	 * instead of the settings form.
	 */
	public function test_render_page_shows_notice_when_hidden(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		\add_filter( 'atmosphere_show_settings_page', '__return_false' );

		\ob_start();
		Admin::render_page();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'managed by another plugin', $html );
	}
}
