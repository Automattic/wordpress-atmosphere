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
	 * Pattern keys the persisted `rewrite_rules` option must contain.
	 *
	 * Lifted from {@see Atmosphere::WELLKNOWN_REWRITE_PATTERNS}; the
	 * constant is private so the tests carry their own copy to assert
	 * against. Keeping these in lockstep is part of what the assertions
	 * here actually verify — if the production constant gains a new
	 * pattern, these tests fail until the new pattern is added.
	 *
	 * @var string[]
	 */
	private const WELLKNOWN_PATTERNS = array(
		'^\.well-known/atproto-did$',
		'^\.well-known/site\.standard\.publication$',
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

		$rules = \get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		foreach ( self::WELLKNOWN_PATTERNS as $pattern ) {
			$this->assertArrayHasKey( $pattern, $rules );
		}
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

		$rules = \get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		foreach ( self::WELLKNOWN_PATTERNS as $pattern ) {
			$this->assertArrayHasKey( $pattern, $rules );
		}
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

		$rules = \get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		foreach ( self::WELLKNOWN_PATTERNS as $pattern ) {
			$this->assertArrayHasKey( $pattern, $rules );
		}
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

		$rules = \get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		foreach ( self::WELLKNOWN_PATTERNS as $pattern ) {
			$this->assertArrayHasKey( $pattern, $rules );
		}
	}
}
