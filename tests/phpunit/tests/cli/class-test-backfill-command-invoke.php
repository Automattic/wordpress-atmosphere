<?php
/**
 * Tests for the `wp atmosphere backfill` command's outer flow.
 *
 * Complements {@see Test_Backfill_Command} (which covers the static
 * `parse_ids()` parser) by driving `__invoke()` end to end through the
 * captured WP-CLI facade stub. Covers argument validation, exit codes,
 * the publish-vs-update routing under `--force`, the half-synced no-op
 * skip, dry-run accounting, and the mid-run not-connected abort.
 *
 * Publishing is mocked at the `atmosphere_pre_apply_writes` boundary so
 * no network calls are made; the command's own logic is what's under
 * test, not the Publisher's PDS mechanics.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group cli
 */

namespace Atmosphere\Tests\Cli;

use Atmosphere\Cli\Backfill_Command;
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\TID;

/**
 * Backfill command __invoke() tests.
 */
class Test_Backfill_Command_Invoke extends \WP_UnitTestCase {

	/**
	 * Set up a connected state and reset the captured WP-CLI log.
	 */
	public function set_up(): void {
		parent::set_up();

		\WP_CLI::reset();

		\update_option(
			'atmosphere_connection',
			array(
				'access_token' => Encryption::encrypt( 'test-token' ),
				'did'          => 'did:plc:test123',
				'pds_endpoint' => 'https://pds.example.com',
				'dpop_jwk'     => Encryption::encrypt( (string) \wp_json_encode( DPoP::generate_key() ) ),
				'expires_at'   => \time() + HOUR_IN_SECONDS,
			)
		);
		\update_option( 'atmosphere_did', 'did:plc:test123' );

		\add_filter( 'atmosphere_syncable_post_types', array( $this, 'force_post_support' ) );
	}

	/**
	 * Tear down options and filters registered for the test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_did' );

		\remove_all_filters( 'atmosphere_pre_apply_writes' );
		\remove_filter( 'atmosphere_syncable_post_types', array( $this, 'force_post_support' ) );

		parent::tear_down();
	}

	/**
	 * Force `post` to be a supported type regardless of options.
	 *
	 * @return string[]
	 */
	public function force_post_support(): array {
		return array( 'post' );
	}

	/**
	 * Register a success short-circuit for `applyWrites`.
	 *
	 * Synthesizes one result per write so `publish_post()`/`update_post()`
	 * complete without a network call.
	 *
	 * @return void
	 */
	private function mock_apply_writes_success(): void {
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short_circuit, array $writes ) {
				$results = array();

				foreach ( $writes as $write ) {
					$type = $write['$type'] ?? '';

					if ( 'com.atproto.repo.applyWrites#delete' === $type ) {
						$results[] = array();
						continue;
					}

					$collection = $write['collection'] ?? 'app.bsky.feed.post';
					$rkey       = $write['rkey'] ?? '';

					$results[] = array(
						'uri' => "at://did:plc:test123/{$collection}/{$rkey}",
						'cid' => 'bafyreib' . \substr( \md5( (string) $rkey ), 0, 20 ),
					);
				}

				return array( 'results' => $results );
			},
			10,
			2
		);
	}

	/**
	 * Register an error short-circuit for `applyWrites`.
	 *
	 * @param string $code Error code to return.
	 * @return void
	 */
	private function mock_apply_writes_error( string $code ): void {
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function () use ( $code ) {
				return new \WP_Error( $code, 'Simulated apply_writes failure.' );
			},
			10,
			2
		);
	}

	/**
	 * Invoke the command with the given flag arguments.
	 *
	 * @param array $assoc_args Flag arguments.
	 * @return void
	 */
	private function run_command( array $assoc_args ): void {
		( new Backfill_Command() )->__invoke( array(), $assoc_args );
	}

	/**
	 * A bare `--ids` (boolean true) must abort before any publish — it
	 * would otherwise stringify to "1" and target post 1.
	 */
	public function test_bare_ids_flag_aborts() {
		try {
			$this->run_command( array( 'ids' => true ) );
			$this->fail( 'Expected WP_CLI_Halt for a valueless --ids.' );
		} catch ( \WP_CLI_Halt $e ) {
			$this->assertStringContainsString( '--ids requires a value', $e->getMessage() );
		}
	}

	/**
	 * Data provider of `--limit` values the validator must reject.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function data_invalid_limits(): array {
		return array(
			'decimal'     => array( '5.5' ),
			'exponent'    => array( '1e3' ),
			'negative'    => array( '-1' ),
			'non-numeric' => array( 'abc' ),
			'bare flag'   => array( true ),
		);
	}

	/**
	 * `--limit` rejects decimals, exponents, negatives, and bare flags.
	 *
	 * @dataProvider data_invalid_limits
	 *
	 * @param mixed $value Raw --limit value.
	 */
	public function test_invalid_limit_aborts( $value ) {
		try {
			$this->run_command( array( 'limit' => $value ) );
			$this->fail( 'Expected WP_CLI_Halt for an invalid --limit.' );
		} catch ( \WP_CLI_Halt $e ) {
			$this->assertStringContainsString( 'Invalid --limit', $e->getMessage() );
		}
	}

	/**
	 * `--limit=0` is accepted as "no cap" and the run proceeds.
	 */
	public function test_limit_zero_is_no_cap() {
		$this->run_command( array( 'limit' => '0' ) );

		$this->assertContains(
			'No posts to backfill.',
			\WP_CLI::messages( 'success' )
		);
	}

	/**
	 * An unsupported `--post-type` aborts with a clear message.
	 */
	public function test_unsupported_post_type_aborts() {
		try {
			$this->run_command( array( 'post-type' => 'not_a_real_type' ) );
			$this->fail( 'Expected WP_CLI_Halt for an unsupported --post-type.' );
		} catch ( \WP_CLI_Halt $e ) {
			$this->assertStringContainsString( 'not configured to sync', $e->getMessage() );
		}
	}

	/**
	 * Non-digit `--ids` tokens abort before any publish runs.
	 */
	public function test_rejected_ids_tokens_abort() {
		try {
			$this->run_command( array( 'ids' => '1,abc' ) );
			$this->fail( 'Expected WP_CLI_Halt for rejected --ids tokens.' );
		} catch ( \WP_CLI_Halt $e ) {
			$this->assertStringContainsString( 'Invalid post ID tokens', $e->getMessage() );
		}
	}

	/**
	 * `--original-time` reserves a historical rkey for a first-time
	 * publish: the stored bsky TID decodes to the post's original date.
	 */
	public function test_original_time_mints_historical_rkey() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_content'  => 'Body.',
				'post_date'     => '2018-02-03 04:05:00',
				'post_date_gmt' => '2018-02-03 04:05:00',
			)
		);
		$this->mock_apply_writes_success();

		$this->run_command(
			array(
				'ids'           => (string) $post_id,
				'original-time' => true,
			)
		);

		$expected = \strtotime( '2018-02-03 04:05:00' ) * 1_000_000 + ( $post_id % 1_000_000 );
		$this->assertSame( $expected, TID::decode( \get_post_meta( $post_id, Post::META_TID, true ) ) );
	}

	/**
	 * `--original-time` prints a one-line note that it only affects
	 * first-time publishes.
	 */
	public function test_original_time_notes_first_time_only() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->mock_apply_writes_success();

		$this->run_command(
			array(
				'ids'           => (string) $post_id,
				'original-time' => true,
			)
		);

		$logs = \implode( "\n", \WP_CLI::messages( 'log' ) );
		$this->assertStringContainsString( 'original time only affects', \strtolower( $logs ) );
	}

	/**
	 * `--post-type` together with `--ids` warns that it is ignored.
	 */
	public function test_post_type_ignored_with_ids_warns() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->mock_apply_writes_success();

		$this->run_command(
			array(
				'ids'       => (string) $post_id,
				'post-type' => 'post',
			)
		);

		$warnings = \implode( "\n", \WP_CLI::messages( 'warning' ) );
		$this->assertStringContainsString( '--post-type is ignored', $warnings );
	}

	/**
	 * With nothing unsynced, the command succeeds with "No posts".
	 */
	public function test_no_posts_to_backfill() {
		$this->run_command( array() );

		$this->assertContains(
			'No posts to backfill.',
			\WP_CLI::messages( 'success' )
		);
	}

	/**
	 * A non-dry-run aborts up front when the plugin is not connected,
	 * before grinding through the publish loop.
	 */
	public function test_not_connected_aborts_before_publishing() {
		\delete_option( 'atmosphere_connection' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		try {
			$this->run_command( array() );
			$this->fail( 'Expected WP_CLI_Halt when not connected.' );
		} catch ( \WP_CLI_Halt $e ) {
			$this->assertStringContainsString( 'Not connected to AT Protocol', $e->getMessage() );
		}
	}

	/**
	 * Dry-run reports the would-publish count and writes no sync meta.
	 */
	public function test_dry_run_reports_without_publishing() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->run_command( array( 'dry-run' => true ) );

		$successes = \implode( "\n", \WP_CLI::messages( 'success' ) );
		$this->assertStringContainsString( 'Would publish 1 of 1', $successes );
		$this->assertStringContainsString( 'Dry run', $successes );
		$this->assertEmpty( \get_post_meta( $post_id, Document::META_URI, true ) );
	}

	/**
	 * An already-synced post is skipped without `--force`.
	 */
	public function test_already_synced_skipped_without_force() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-tid' );

		$this->run_command( array( 'ids' => (string) $post_id ) );

		$warnings = \implode( "\n", \WP_CLI::messages( 'warning' ) );
		$this->assertStringContainsString( 'already synced', $warnings );

		$successes = \implode( "\n", \WP_CLI::messages( 'success' ) );
		$this->assertStringContainsString( 'Synced 0 of 1', $successes );
		$this->assertStringContainsString( '1 skipped', $successes );
	}

	/**
	 * `--force` on a half-synced post (document URI present, no Bluesky
	 * publication history) routes to `update_post()`, which no-ops. The
	 * command must report a skip, not a misleading "Updated" success.
	 */
	public function test_force_noop_on_half_synced_post_is_skipped() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-tid' );

		$this->run_command(
			array(
				'ids'   => (string) $post_id,
				'force' => true,
			)
		);

		$warnings = \implode( "\n", \WP_CLI::messages( 'warning' ) );
		$this->assertStringContainsString( 'no Bluesky publication to update', $warnings );

		$successes = \implode( "\n", \WP_CLI::messages( 'success' ) );
		$this->assertStringContainsString( '1 skipped', $successes );
	}

	/**
	 * A fresh, unsynced post publishes: the count reflects it and the
	 * document URI meta is written.
	 */
	public function test_successful_publish_counts_and_writes_meta() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Backfill me',
				'post_content' => 'Short post.',
			)
		);
		$this->mock_apply_writes_success();

		$this->run_command( array( 'ids' => (string) $post_id ) );

		$successes = \implode( "\n", \WP_CLI::messages( 'success' ) );
		$this->assertStringContainsString( 'Synced 1 of 1', $successes );
		$this->assertNotEmpty( \get_post_meta( $post_id, Document::META_URI, true ) );
	}

	/**
	 * A publish failure sets a non-zero exit (WP_CLI::error halts) and the
	 * summary reports the error count.
	 */
	public function test_publish_error_sets_nonzero_exit() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->mock_apply_writes_error( 'boom' );

		try {
			$this->run_command( array() );
			$this->fail( 'Expected WP_CLI_Halt when a publish errored.' );
		} catch ( \WP_CLI_Halt $e ) {
			$this->assertStringContainsString( '1 errors', $e->getMessage() );
		}
	}

	/**
	 * A mid-run loss of connection aborts the remaining queue rather than
	 * re-erroring on every post.
	 */
	public function test_not_connected_midrun_aborts_remaining() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->mock_apply_writes_error( 'atmosphere_not_connected' );

		try {
			$this->run_command( array() );
			$this->fail( 'Expected WP_CLI_Halt after the abort.' );
		} catch ( \WP_CLI_Halt $e ) {
			$warnings = \implode( "\n", \WP_CLI::messages( 'warning' ) );
			$this->assertStringContainsString( 'aborting the remaining posts', $warnings );

			// Only the first post should have been attempted.
			$failures = 0;
			foreach ( \WP_CLI::messages( 'warning' ) as $message ) {
				if ( false !== \strpos( $message, 'Failed to publish' ) ) {
					++$failures;
				}
			}
			$this->assertSame( 1, $failures );
		}
	}
}
