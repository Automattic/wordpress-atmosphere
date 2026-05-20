<?php
/**
 * Integration tests for the in-form domain-handle trigger.
 *
 * Covers the gates on `Admin::maybe_set_domain_handle()` (presence of
 * the button's `name` field, capability, option-group, nonce) and
 * confirms that `Handle::set_handle()` runs only when every gate
 * passes. Handle service itself has unit coverage in
 * {@see Test_Handle}; this file pins the front-door contract between
 * the settings-form submission and the XRPC call.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group handle
 */

namespace Atmosphere\Tests;

use Atmosphere\Handle;
use Atmosphere\WP_Admin\Admin;
use WP_UnitTestCase;
use WPDieException;

/**
 * Domain-handle trigger tests.
 */
class Test_Admin_Handle extends WP_UnitTestCase {

	/**
	 * Filters registered during a test that must be removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $tracked_filters = array();

	/**
	 * Reset request superglobals and current user.
	 */
	public function tear_down(): void {
		foreach ( $this->tracked_filters as $entry ) {
			\remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		$this->tracked_filters = array();

		$_REQUEST = array();
		$_POST    = array();
		$_GET     = array();

		\wp_set_current_user( 0 );
		\delete_option( 'atmosphere_connection' );
		\delete_option( Handle::OPTION_PREVIOUS_HANDLE );

		parent::tear_down();
	}

	/**
	 * Register a filter and remember it for tearDown removal.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function add_filter_tracked( string $hook, callable $callback, int $priority = 10 ): void {
		\add_filter( $hook, $callback, $priority );
		$this->tracked_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Become an administrator — grants `manage_options`.
	 */
	private function become_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );
	}

	/**
	 * Set up an eligible state where {@see Handle::set_handle()} would
	 * reach the {@see Handle::FILTER_PRE_UPDATE} short-circuit if
	 * called: root-install URLs and a connected, non-matching handle.
	 */
	private function make_handle_call_observable(): void {
		$home = static fn() => 'https://example.com';
		$this->add_filter_tracked( 'home_url', $home );
		$this->add_filter_tracked( 'site_url', $home );

		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.bsky.social',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
	}

	/**
	 * Install a spy on `Handle::FILTER_PRE_UPDATE` and return a
	 * reference to its call counter.
	 *
	 * @param int $counter Call-count reference holder.
	 */
	private function spy_on_handle_update( int &$counter ): void {
		$counter = 0;
		$spy     = static function ( $value ) use ( &$counter ) {
			++$counter;
			return $value;
		};
		$this->add_filter_tracked( Handle::FILTER_PRE_UPDATE, $spy );
	}

	/**
	 * Build a settings-form POST that carries the trigger button +
	 * the standard `atmosphere-options` settings nonce.
	 *
	 * @return string The created nonce (for assertion convenience).
	 */
	private function arrange_valid_form_post(): string {
		$nonce = \wp_create_nonce( 'atmosphere-options' );

		$_POST['atmosphere_set_domain_handle'] = '1';
		$_POST['option_page']                  = 'atmosphere';
		$_POST['_wpnonce']                     = $nonce;
		$_REQUEST['_wpnonce']                  = $nonce;
		$_REQUEST['option_page']               = 'atmosphere';

		return $nonce;
	}

	/**
	 * The trigger handler must NOT die when the current user lacks
	 * `manage_options`. The button posts through the regular settings
	 * form, and the rest of options.php still has work to do for the
	 * other registered settings; bailing silently is the contract.
	 */
	public function test_bails_silently_without_manage_options_cap(): void {
		\wp_set_current_user( 0 );
		$this->make_handle_call_observable();
		$this->arrange_valid_form_post();
		$called = 0;
		$this->spy_on_handle_update( $called );

		Admin::maybe_set_domain_handle();

		$this->assertSame( 0, $called, 'Handle::set_handle() must not run without manage_options.' );
	}

	/**
	 * Without the trigger field in the POST (Save Changes path or any
	 * unrelated admin pageview) the handler must return without
	 * touching the Handle service.
	 */
	public function test_bails_silently_without_trigger_field(): void {
		$this->become_admin();
		$this->make_handle_call_observable();
		$called = 0;
		$this->spy_on_handle_update( $called );

		// Note: $_POST['atmosphere_set_domain_handle'] is intentionally absent.
		Admin::maybe_set_domain_handle();

		$this->assertSame( 0, $called, 'Handle::set_handle() must not run on a non-trigger admin_init pass.' );
	}

	/**
	 * The handler must reject submissions whose `option_page` is not
	 * the atmosphere group — that field is the only reliable signal
	 * that the POST came from our settings form and not another
	 * options.php submission that happens to carry a matching name.
	 */
	public function test_bails_silently_when_option_page_does_not_match(): void {
		$this->become_admin();
		$this->make_handle_call_observable();
		$this->arrange_valid_form_post();
		$_POST['option_page']    = 'something-else';
		$_REQUEST['option_page'] = 'something-else';
		$called                  = 0;
		$this->spy_on_handle_update( $called );

		Admin::maybe_set_domain_handle();

		$this->assertSame( 0, $called, 'Handle::set_handle() must not run when option_page does not match.' );
	}

	/**
	 * With the trigger field + cap + matching option_page but a
	 * missing nonce, `check_admin_referer` must wp_die — guaranteeing
	 * the side-effect cannot execute on a forged POST.
	 */
	public function test_dies_on_missing_nonce(): void {
		$this->become_admin();
		$this->make_handle_call_observable();
		$_POST['atmosphere_set_domain_handle'] = '1';
		$_POST['option_page']                  = 'atmosphere';
		$_REQUEST['option_page']               = 'atmosphere';
		$called                                = 0;
		$this->spy_on_handle_update( $called );

		$this->expectException( WPDieException::class );

		try {
			Admin::maybe_set_domain_handle();
		} finally {
			$this->assertSame( 0, $called, 'Handle::set_handle() must not run on a nonce-failing POST.' );
		}
	}

	/**
	 * Happy path: trigger field + cap + option_page + valid nonce →
	 * `Handle::set_handle()` runs and the FILTER_PRE_UPDATE spy
	 * observes exactly one short-circuit invocation.
	 */
	public function test_invokes_handle_set_when_all_gates_pass(): void {
		$this->become_admin();
		$this->make_handle_call_observable();
		$this->arrange_valid_form_post();
		$called = 0;
		$this->spy_on_handle_update( $called );

		Admin::maybe_set_domain_handle();

		$this->assertSame( 1, $called, 'Handle::set_handle() must run when all gates pass.' );
	}
}
