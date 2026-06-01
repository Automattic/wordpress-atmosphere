<?php
/**
 * Tests for the well-known rewrite self-heal helper.
 *
 * Covers the {@see Atmosphere::maybe_flush_wellknown_rewrites()} behaviour
 * and the call-site wiring that fires it when an administrator triggers
 * a domain-handle change.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wellknown
 */

namespace Atmosphere\Tests;

use Atmosphere\Atmosphere;
use Atmosphere\Handle;
use WP_UnitTestCase;

/**
 * Well-known rewrite self-heal tests.
 */
class Test_Wellknown_Rewrite extends WP_UnitTestCase {

	/**
	 * Pattern => query-target map the persisted `rewrite_rules` option
	 * must contain after a heal.
	 *
	 * Lifted from {@see Atmosphere::WELLKNOWN_REWRITE_PATTERNS}; the
	 * constant is private so the tests carry their own copy to assert
	 * against. Keeping these in lockstep is part of what the assertions
	 * here actually verify — if the production constant gains, renames,
	 * or re-targets a pattern, these tests fail until the copy matches.
	 * Asserting the target (not just key presence) catches a regression
	 * where the rule resolves to the wrong query var, which would
	 * silently break verification even with the key present.
	 *
	 * @var array<string, string>
	 */
	private const WELLKNOWN_PATTERNS = array(
		'^\.well-known/atproto-did$'                 => 'index.php?atmosphere_wellknown=atproto-did',
		'^\.well-known/site\.standard\.publication$' => 'index.php?atmosphere_wellknown=publication',
	);

	/**
	 * Closures registered during a test that must be removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $tracked_filters = array();

	/**
	 * Ensure the rules are registered in the in-memory `$wp_rewrite`
	 * before each test so a real flush produces an array that contains
	 * our patterns.
	 *
	 * `plugins_loaded` already wires `register_wellknown_rewrite` onto
	 * `init`, so the rules should be present after bootstrap, but the
	 * test framework can reset state between tests on some hosts —
	 * re-registering is cheap and idempotent.
	 *
	 * Also primes a permalink structure: with no structure set,
	 * `WP_Rewrite::rewrite_rules()` early-returns an empty array
	 * regardless of how many `add_rewrite_rule()` calls happened, so
	 * `flush_rewrite_rules()` writes nothing useful and our patterns
	 * never land in the persisted option.
	 */
	public function set_up(): void {
		parent::set_up();

		/*
		 * Force both the persisted option and the in-memory state to a
		 * known permalink structure. `set_permalink_structure()` is a
		 * no-op when the in-memory value already matches, which leaves
		 * a previous test's transactional rollback (which restores the
		 * empty `permalink_structure` option) reflected by the next
		 * `init()` read. Writing the option first and then calling
		 * `init()` makes the prime independent of test ordering.
		 */
		\update_option( 'permalink_structure', '/%postname%/' );

		global $wp_rewrite;
		$wp_rewrite->init();

		( new Atmosphere() )->register_wellknown_rewrite();
	}

	/**
	 * Detach any tracked filters and drop options touched here.
	 */
	public function tear_down(): void {
		foreach ( $this->tracked_filters as $entry ) {
			\remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		$this->tracked_filters = array();

		\delete_option( 'rewrite_rules' );
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );

		\update_option( 'permalink_structure', '' );

		global $wp_rewrite;
		$wp_rewrite->init();

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
	 * Assert the persisted rules contain every well-known pattern
	 * resolving to its expected query target.
	 *
	 * @param mixed $rules The `rewrite_rules` option value after a heal.
	 */
	private function assertWellknownPatternsResolved( $rules ): void {
		$this->assertIsArray( $rules );
		foreach ( self::WELLKNOWN_PATTERNS as $pattern => $target ) {
			$this->assertArrayHasKey( $pattern, $rules );
			$this->assertSame( $target, $rules[ $pattern ] );
		}
	}

	/**
	 * No-op when both well-known patterns are already in the option.
	 *
	 * Asserts byte-identical state after the call so even a flush that
	 * happened to produce the same result would fail this test — what
	 * we are verifying is that no flush ran at all.
	 */
	public function test_no_flush_when_both_patterns_present(): void {
		$original = array(
			'^\.well-known/atproto-did$'                 => 'index.php?atmosphere_wellknown=atproto-did',
			'^\.well-known/site\.standard\.publication$' => 'index.php?atmosphere_wellknown=publication',
			'some/other/rule'                            => 'index.php?other=1',
		);
		\update_option( 'rewrite_rules', $original );

		Atmosphere::maybe_flush_wellknown_rewrites();

		$this->assertSame( $original, \get_option( 'rewrite_rules' ) );
	}

	/**
	 * Flushes when the `rewrite_rules` option is entirely absent —
	 * the install-never-activated case (FOSSE bundle, mu-plugin load,
	 * etc.) and the rules-wiped-after-activation case.
	 */
	public function test_flushes_when_option_is_missing(): void {
		\delete_option( 'rewrite_rules' );

		Atmosphere::maybe_flush_wellknown_rewrites();

		$this->assertWellknownPatternsResolved( \get_option( 'rewrite_rules' ) );
	}

	/**
	 * Flushes when one of the patterns is present but the other is
	 * missing — catches the hook-ordering case where another plugin
	 * flushed mid-init after only some of our rules had registered.
	 */
	public function test_flushes_when_patterns_partially_missing(): void {
		\update_option(
			'rewrite_rules',
			array(
				'^\.well-known/atproto-did$' => 'index.php?atmosphere_wellknown=atproto-did',
				'some/other/rule'            => 'index.php?other=1',
			)
		);

		Atmosphere::maybe_flush_wellknown_rewrites();

		$this->assertWellknownPatternsResolved( \get_option( 'rewrite_rules' ) );
	}

	/**
	 * Flushes when a well-known pattern resolves to the wrong target.
	 */
	public function test_flushes_when_target_is_wrong(): void {
		$pattern = \array_key_first( self::WELLKNOWN_PATTERNS );

		\update_option(
			'rewrite_rules',
			array(
				$pattern => 'index.php?atmosphere_wellknown=wrong',
			)
		);

		Atmosphere::maybe_flush_wellknown_rewrites();

		$this->assertWellknownPatternsResolved( \get_option( 'rewrite_rules' ) );
	}

	/**
	 * Second consecutive call is a no-op once the patterns are present.
	 *
	 * This is the loop-prevention property: the helper fires from
	 * surfaces that can recur (every settings pageview), so a healthy
	 * site must not re-flush each time. The first call heals; the second
	 * must leave the persisted array byte-identical.
	 */
	public function test_second_call_is_a_noop_once_healed(): void {
		\delete_option( 'rewrite_rules' );

		Atmosphere::maybe_flush_wellknown_rewrites();
		$healed = \get_option( 'rewrite_rules' );
		$this->assertWellknownPatternsResolved( $healed );

		Atmosphere::maybe_flush_wellknown_rewrites();

		$this->assertSame( $healed, \get_option( 'rewrite_rules' ) );
	}

	/**
	 * Defensive: flush when the option holds a non-array value.
	 *
	 * Some caches or hosts can stash an empty string or other scalar
	 * here. The helper must treat that as "rules missing" rather than
	 * silently passing the check.
	 */
	public function test_flushes_when_option_value_is_not_array(): void {
		\update_option( 'rewrite_rules', '' );

		Atmosphere::maybe_flush_wellknown_rewrites();

		$this->assertWellknownPatternsResolved( \get_option( 'rewrite_rules' ) );
	}

	/**
	 * No flush on a plain-permalink install.
	 *
	 * Without a permalink structure WordPress keeps `rewrite_rules`
	 * empty and routes everything through the query string, so our
	 * patterns can never be persisted and the well-known endpoints
	 * cannot resolve via rewrite anyway. The helper must bail rather
	 * than read the always-empty array as "patterns missing" and burn
	 * an `update_option` write on every call (the re-flush loop a code
	 * review flagged). Asserts the option is untouched.
	 */
	public function test_no_flush_on_plain_permalinks(): void {
		\update_option( 'permalink_structure', '' );

		global $wp_rewrite;
		$wp_rewrite->init();

		$sentinel = array( 'some/other/rule' => 'index.php?other=1' );
		\update_option( 'rewrite_rules', $sentinel );

		Atmosphere::maybe_flush_wellknown_rewrites();

		$this->assertSame( $sentinel, \get_option( 'rewrite_rules' ) );
	}

	/**
	 * `Admin::add_menu()` wires the self-heal onto the settings page
	 * load hook. A cheap assertion guards against the wiring being
	 * removed, renamed, or mis-targeted — the OAuth and `set_handle`
	 * sites have behavioural coverage, this one would otherwise have
	 * none.
	 *
	 * The exact `load-{suffix}` hook name depends on how far the admin
	 * menu is bootstrapped (`settings_page_atmosphere` in a real admin
	 * request, `admin_page_atmosphere` in the bare test harness), so the
	 * assertion scans `$wp_filter` for whichever `load-*` hook the call
	 * registered our callback on rather than hard-coding the suffix.
	 */
	public function test_add_menu_wires_self_heal_on_settings_page_load(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		\Atmosphere\WP_Admin\Admin::add_menu();

		global $wp_filter;
		$callback  = array( Atmosphere::class, 'maybe_flush_wellknown_rewrites' );
		$load_hook = '';
		foreach ( \array_keys( $wp_filter ) as $hook_name ) {
			if ( \str_starts_with( $hook_name, 'load-' ) && false !== \has_action( $hook_name, $callback ) ) {
				$load_hook = $hook_name;
				break;
			}
		}

		$this->assertNotSame(
			'',
			$load_hook,
			'add_menu() should wire maybe_flush_wellknown_rewrites onto a settings-page load hook.'
		);

		\remove_action( $load_hook, $callback );
	}

	/**
	 * `Handle::set_handle()` self-heals the persisted rewrite rules
	 * before the PDS round-trip. This is the exact failure surface
	 * the original bug report ("External handle did not resolve to
	 * DID") was caught on, so the wiring here is load-bearing.
	 */
	public function test_set_handle_flushes_wellknown_rewrites_before_xrpc(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

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

		// Short-circuit the actual PDS call.
		$short_circuit = static fn() => true;
		$this->add_filter_tracked( Handle::FILTER_PRE_UPDATE, $short_circuit );

		// Wipe the persisted rules so the helper has something to fix.
		\delete_option( 'rewrite_rules' );

		$result = Handle::set_handle();

		$this->assertTrue( $result );
		$this->assertWellknownPatternsResolved( \get_option( 'rewrite_rules' ) );
	}
}
