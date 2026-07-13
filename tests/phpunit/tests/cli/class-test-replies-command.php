<?php
/**
 * Tests for the reaction recovery WP-CLI command.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group cli
 */

namespace Atmosphere\Tests\Cli;

use Atmosphere\Cli\Replies_Command;
use Atmosphere\Reaction_Sync;
use Atmosphere\Transformer\Post as BskyPost;

/**
 * Reaction recovery command tests.
 */
class Test_Replies_Command extends \WP_UnitTestCase {

	/**
	 * Reset the capturing CLI facade and connection state.
	 */
	public function set_up(): void {
		parent::set_up();

		\WP_CLI::reset();
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( '_atmosphere_reaction_sync_lock' );
	}

	/**
	 * Clean up connection state.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_sync_replies' );
		\delete_option( 'comment_moderation' );
		\delete_option( '_atmosphere_reaction_sync_lock' );
		\remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * A non-numeric post ID must fail before any network or database work.
	 */
	public function test_backfill_rejects_non_numeric_post_id() {
		try {
			( new Replies_Command() )->backfill( array( 'not-a-post' ), array() );
			$this->fail( 'Expected WP_CLI_Halt for a non-numeric post ID.' );
		} catch ( \WP_CLI_Halt $error ) {
			$this->assertStringContainsString( 'valid numeric WordPress post ID', $error->getMessage() );
		}
	}

	/**
	 * A valid local post still requires a live AT Protocol connection.
	 */
	public function test_backfill_requires_connection() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		try {
			( new Replies_Command() )->backfill( array( (string) $post_id ), array() );
			$this->fail( 'Expected WP_CLI_Halt when disconnected.' );
		} catch ( \WP_CLI_Halt $error ) {
			$this->assertStringContainsString( 'Not connected to AT Protocol', $error->getMessage() );
		}
	}

	/**
	 * An explicit recovery command should explain when reply sync is disabled.
	 */
	public function test_backfill_reports_disabled_reply_sync() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:me' ), false );
		\update_option( 'atmosphere_connection', array( 'access_token' => 'test-token' ), false );
		\update_option( 'atmosphere_sync_replies', '0', false );

		try {
			( new Replies_Command() )->backfill( array( (string) $post_id ), array() );
			$this->fail( 'Expected WP_CLI_Halt when reply sync is disabled.' );
		} catch ( \WP_CLI_Halt $error ) {
			$this->assertStringContainsString( 'Reply syncing is disabled', $error->getMessage() );
		}
	}

	/**
	 * The command reports imported, existing, skipped, and moderated replies
	 * separately so operators can tell whether recovery actually succeeded.
	 */
	public function test_backfill_reports_detailed_result_counts() {
		$post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$root_uri     = 'at://did:plc:me/app.bsky.feed.post/root-cli';
		$existing_uri = 'at://did:plc:alice/app.bsky.feed.post/existing-cli';
		$import_uri   = 'at://did:plc:alice/app.bsky.feed.post/import-cli';
		$skipped_uri  = 'at://did:plc:alice/app.bsky.feed.post/skipped-cli';

		\update_post_meta( $post_id, BskyPost::META_URI, $root_uri );
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:me' ), false );
		\update_option( 'atmosphere_connection', array( 'access_token' => 'test-token' ), false );
		\update_option( 'comment_moderation', '1', false );
		\set_transient(
			'atmosphere_profile_' . \md5( 'did:plc:alice' ),
			array(
				'name'   => 'Alice',
				'handle' => 'alice.test',
			),
			\HOUR_IN_SECONDS
		);

		$existing_id = \wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Already here.',
				'comment_approved' => 1,
			)
		);
		\update_comment_meta( $existing_id, Reaction_Sync::META_SOURCE_ID, $existing_uri );

		$reply = static function ( string $uri, string $text ) use ( $root_uri ): array {
			return array(
				'$type' => 'app.bsky.feed.defs#threadViewPost',
				'post'  => array(
					'uri'    => $uri,
					'cid'    => 'bafy' . \md5( $uri ),
					'author' => array(
						'did'    => 'did:plc:alice',
						'handle' => 'alice.test',
					),
					'record' => array(
						'text'      => $text,
						'createdAt' => '2026-07-10T12:00:00.000Z',
						'reply'     => array(
							'root'   => array( 'uri' => $root_uri ),
							'parent' => array( 'uri' => $root_uri ),
						),
					),
				),
			);
		};

		$http = static function ( $response, $args, $url ) use ( $root_uri, $existing_uri, $import_uri, $skipped_uri, $reply ) {
			if ( false === \strpos( $url, 'public.api.bsky.app/xrpc/app.bsky.feed.getPostThread' ) ) {
				return $response;
			}

			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
				'body'     => (string) \wp_json_encode(
					array(
						'thread' => array(
							'$type'   => 'app.bsky.feed.defs#threadViewPost',
							'post'    => array( 'uri' => $root_uri ),
							'replies' => array(
								$reply( $existing_uri, 'Existing.' ),
								$reply( $import_uri, 'Import me.' ),
								$reply( $skipped_uri, '' ),
							),
						),
					)
				),
			);
		};

		\add_filter( 'pre_http_request', $http, 10, 3 );
		( new Replies_Command() )->backfill( array( (string) $post_id ), array() );

		$messages = \WP_CLI::messages( 'success' );

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'Visible replies: 3', $messages[0] );
		$this->assertStringContainsString( 'imported: 1; already present: 1; skipped: 1', $messages[0] );
		$this->assertStringContainsString( 'imported but not publicly approved: 1', $messages[0] );
	}
}
