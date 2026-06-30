<?php
/**
 * Tests for `Admin::maybe_warn_missing_post_types()`.
 *
 * Auto-publish defaults on, so a user who unticks every Post types
 * checkbox ends up with publishing "enabled" yet nothing eligible to
 * publish, a silent dead end (see issue #173). The settings page must
 * surface a warning in that state and stay quiet otherwise.
 *
 * The warning is registered as a settings error so WordPress renders it
 * at the top of the Settings page via `options-head.php`'s
 * `settings_errors()` call. These tests invoke the registration method
 * directly and inspect `get_settings_errors()`.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\WP_Admin\Admin;
use WP_UnitTestCase;

/**
 * Missing-post-types warning tests.
 */
class Test_Missing_Post_Types_Notice extends WP_UnitTestCase {

	/**
	 * Start each test with a clean settings-error queue.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Reset request state, options, and the settings-error queue.
	 */
	public function tear_down(): void {
		\wp_set_current_user( 0 );
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_auto_publish' );
		\delete_option( 'atmosphere_support_post_types' );
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		parent::tear_down();
	}

	/**
	 * Become an administrator (grants `manage_options`).
	 */
	private function become_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );
	}

	/**
	 * Seed the identity row so the publishing section (and its Post
	 * types field) is considered on screen.
	 */
	private function seed_identity(): void {
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			true
		);
	}

	/**
	 * Run the registration and return the codes of any settings errors
	 * registered under the `atmosphere` slug.
	 *
	 * @return string[]
	 */
	private function registered_codes(): array {
		Admin::maybe_warn_missing_post_types();

		return \array_column(
			\array_filter(
				\get_settings_errors(),
				static fn ( $error ) => 'atmosphere' === $error['setting']
			),
			'code'
		);
	}

	/**
	 * Auto-publish on with no post types selected: the warning must fire.
	 */
	public function test_warns_when_auto_publish_on_and_no_post_types(): void {
		$this->become_admin();
		$this->seed_identity();
		\update_option( 'atmosphere_auto_publish', '1' );
		\update_option( 'atmosphere_support_post_types', array() );

		$this->assertContains( 'no_post_types', $this->registered_codes() );
	}

	/**
	 * At least one post type selected: no warning, even with auto-publish on.
	 */
	public function test_no_warning_when_post_types_selected(): void {
		$this->become_admin();
		$this->seed_identity();
		\update_option( 'atmosphere_auto_publish', '1' );
		\update_option( 'atmosphere_support_post_types', array( 'post' ) );

		$this->assertNotContains( 'no_post_types', $this->registered_codes() );
	}

	/**
	 * Auto-publish off: an empty post-type list is harmless, so stay quiet.
	 */
	public function test_no_warning_when_auto_publish_disabled(): void {
		$this->become_admin();
		$this->seed_identity();
		\update_option( 'atmosphere_auto_publish', '' );
		\update_option( 'atmosphere_support_post_types', array() );

		$this->assertNotContains( 'no_post_types', $this->registered_codes() );
	}

	/**
	 * No identity on file: the Post types field is not on screen, so the
	 * warning would dangle. It must not fire.
	 */
	public function test_no_warning_without_identity(): void {
		$this->become_admin();
		// No identity row.
		\update_option( 'atmosphere_auto_publish', '1' );
		\update_option( 'atmosphere_support_post_types', array() );

		$this->assertNotContains( 'no_post_types', $this->registered_codes() );
	}

	/**
	 * A native `add_post_type_support()` opt-in keeps the effective list
	 * non-empty even when the option is empty, so nothing is actually
	 * blocked: stay quiet.
	 */
	public function test_no_warning_when_post_type_enabled_via_native_support(): void {
		$this->become_admin();
		$this->seed_identity();
		\update_option( 'atmosphere_auto_publish', '1' );
		\update_option( 'atmosphere_support_post_types', array() );
		\add_post_type_support( 'page', 'atmosphere' );

		$codes = $this->registered_codes();

		\remove_post_type_support( 'page', 'atmosphere' );

		$this->assertNotContains( 'no_post_types', $codes );
	}

	/**
	 * Fresh install: neither option is stored, so both fall back to their
	 * registered defaults: auto-publish on, post types `['post']`. The
	 * default list is non-empty, so the most common path stays quiet.
	 */
	public function test_no_warning_on_fresh_install_defaults(): void {
		$this->become_admin();
		$this->seed_identity();
		// No atmosphere_auto_publish or atmosphere_support_post_types rows.

		$this->assertNotContains( 'no_post_types', $this->registered_codes() );
	}

	/**
	 * Capability gate: a user without `manage_options` gets no warning.
	 */
	public function test_no_warning_without_manage_options_cap(): void {
		$this->seed_identity();
		\update_option( 'atmosphere_auto_publish', '1' );
		\update_option( 'atmosphere_support_post_types', array() );

		$this->assertNotContains( 'no_post_types', $this->registered_codes() );
	}
}
