<?php
/**
 * Main plugin initialization and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;
use Atmosphere\Transformer\Comment;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Publication;
use Atmosphere\Transformer\TID;
use Atmosphere\Integrations\Load;
use Atmosphere\WP_Admin\Admin;

/**
 * Atmosphere main class.
 */
class Atmosphere {

	/**
	 * Comment meta key tracking how many times publish has been
	 * deferred waiting for a parent comment to publish first.
	 *
	 * @var string
	 */
	private const META_PUBLISH_ATTEMPTS = '_atmosphere_publish_attempts';

	/**
	 * Maximum re-schedule hops for a child comment waiting on a
	 * not-yet-published parent. After this many deferrals the child
	 * publishes as a top-level reply on the post (current fallback
	 * behavior) so a stuck parent does not block it forever.
	 *
	 * @var int
	 */
	private const PARENT_DEFER_MAX_ATTEMPTS = 3;

	/**
	 * Seconds between parent-pending re-schedule hops.
	 *
	 * @var int
	 */
	private const PARENT_DEFER_DELAY_SECONDS = 30;

	/**
	 * Wire up all hooks.
	 */
	public function init(): void {
		/*
		 * Admin and Backfill self-register on init. This runs before
		 * admin_init, rest_api_init, and wp_ajax_* so sub-hooks those
		 * callbacks add are wired up in time, and it also ensures
		 * REST/AJAX endpoints are available on non-admin requests.
		 */
		\add_action( 'init', array( Admin::class, 'register' ), 5 );
		\add_action( 'init', array( Backfill::class, 'register' ), 5 );

		// REST route (always active for client-metadata).
		\add_action( 'rest_api_init', array( Admin::class, 'register_rest_routes' ) );

		// Frontend verification headers.
		\add_action( 'wp_head', array( $this, 'output_document_link' ) );

		// Well-known endpoints.
		\add_action( 'init', array( $this, 'register_wellknown_rewrite' ) );
		\add_action( 'template_redirect', array( $this, 'serve_wellknown_atproto_did' ) );
		\add_action( 'template_redirect', array( $this, 'serve_wellknown_publication' ) );

		// Plugin integrations.
		Load::init();

		// JSON preview for AT Protocol records.
		\add_action( 'template_redirect', array( $this, 'preview' ) );

		// Post lifecycle hooks.
		\add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );

		// Catch permanent deletes (bypassing trash or emptying trash).
		\add_action( 'before_delete_post', array( $this, 'on_before_delete' ) );

		// Comment lifecycle hooks.
		\add_action( 'transition_comment_status', array( $this, 'on_comment_status_change' ), 10, 3 );
		\add_action( 'comment_post', array( $this, 'on_comment_insert' ), 10, 2 );
		\add_action( 'edit_comment', array( $this, 'on_comment_edit' ) );
		\add_action( 'delete_comment', array( $this, 'on_comment_before_delete' ) );

		// Auto-sync publication when site identity changes.
		\add_action( 'update_option_blogname', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'update_option_blogdescription', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'update_option_site_icon', array( $this, 'schedule_publication_sync' ) );

		// Token refresh cron.
		\add_action( 'atmosphere_refresh_token', array( $this, 'cron_refresh_token' ) );

		if ( ! \wp_next_scheduled( 'atmosphere_refresh_token' ) && is_connected() ) {
			\wp_schedule_event( \time(), 'twicedaily', 'atmosphere_refresh_token' );
		}

		// Async action hooks (called by WP-Cron).
		self::register_async_hooks();

		// Reaction sync cron + display hooks.
		\add_action( 'atmosphere_sync_reactions', array( Reaction_Sync::class, 'sync' ) );
		Reaction_Sync::register();

		if ( ! \wp_next_scheduled( 'atmosphere_sync_reactions' ) && is_connected() ) {
			\wp_schedule_event( \time(), 'hourly', 'atmosphere_sync_reactions' );
		}
	}

	/**
	 * Output <link rel="site.standard.document"> on singular posts.
	 *
	 * This confirms the bidirectional link between the web page and
	 * its AT Protocol document record, as required by standard.site.
	 */
	public function output_document_link(): void {
		if ( ! is_connected() || ! \is_singular() ) {
			return;
		}

		$post = \get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! \in_array( $post->post_type, Backfill::syncable_post_types(), true ) ) {
			return;
		}

		// Use existing TID or lazily generate one.
		$doc_tid = \get_post_meta( $post->ID, Document::META_TID, true );

		if ( empty( $doc_tid ) ) {
			$doc_tid = TID::generate();
			\update_post_meta( $post->ID, Document::META_TID, $doc_tid );
		}

		$uri = build_at_uri( get_did(), 'site.standard.document', $doc_tid );

		\printf(
			'<link rel="site.standard.document" href="%s" />' . "\n",
			\esc_attr( $uri )
		);
	}

	/**
	 * Register rewrite rules for well-known endpoints.
	 */
	public function register_wellknown_rewrite(): void {
		\add_rewrite_rule(
			'^\.well-known/atproto-did$',
			'index.php?atmosphere_wellknown=atproto-did',
			'top'
		);

		\add_rewrite_rule(
			'^\.well-known/site\.standard\.publication$',
			'index.php?atmosphere_wellknown=publication',
			'top'
		);

		\add_filter(
			'query_vars',
			static function ( array $vars ): array {
				$vars[] = 'atmosphere_wellknown';
				return $vars;
			}
		);
	}

	/**
	 * Serve the /.well-known/atproto-did response.
	 *
	 * Returns the connected DID as plain text so the domain can be
	 * verified as an AT Protocol handle (domain handle verification).
	 *
	 * @see https://atproto.com/specs/handle#handle-resolution
	 */
	public function serve_wellknown_atproto_did(): void {
		if ( \get_query_var( 'atmosphere_wellknown' ) !== 'atproto-did' ) {
			return;
		}

		if ( ! is_connected() ) {
			\status_header( 404 );
			exit;
		}

		\status_header( 200 );
		\header( 'Content-Type: text/plain; charset=utf-8' );
		echo \esc_html( get_did() );
		exit;
	}

	/**
	 * Serve the /.well-known/site.standard.publication response.
	 *
	 * Returns the AT-URI of the publication record as plain text,
	 * confirming the link between this domain and the publication.
	 */
	public function serve_wellknown_publication(): void {
		if ( \get_query_var( 'atmosphere_wellknown' ) !== 'publication' ) {
			return;
		}

		if ( ! is_connected() ) {
			\status_header( 404 );
			exit;
		}

		$pub_tid = \get_option( Publication::OPTION_TID );

		if ( ! $pub_tid ) {
			\status_header( 404 );
			exit;
		}

		$uri = build_at_uri( get_did(), 'site.standard.publication', $pub_tid );

		\status_header( 200 );
		\header( 'Content-Type: text/plain; charset=utf-8' );
		echo \esc_html( $uri );
		exit;
	}

	/**
	 * Serve a JSON preview of the AT Protocol record for a post.
	 *
	 * Append ?atproto to a singular post URL to see the document
	 * record JSON. Requires the edit_posts capability.
	 */
	public function preview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['atproto'] ) || ! \is_singular() ) {
			return;
		}

		if ( ! \current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post = \get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! \in_array( $post->post_type, Backfill::syncable_post_types(), true ) ) {
			\status_header( 404 );
			exit;
		}

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		\status_header( 200 );
		\header( 'Content-Type: application/json; charset=utf-8' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		echo \wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Handle post status transitions.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 */
	public function on_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! is_connected() ) {
			return;
		}

		if ( '0' === \get_option( 'atmosphere_auto_publish', '1' ) ) {
			return;
		}

		if ( ! \in_array( $post->post_type, Backfill::syncable_post_types(), true ) ) {
			return;
		}

		// Prevent infinite loops from meta updates.
		if ( \did_action( 'atmosphere_publishing' ) ) {
			return;
		}

		\do_action( 'atmosphere_publishing' );

		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			// New publish — schedule async.
			\wp_schedule_single_event( \time(), 'atmosphere_publish_post', array( $post->ID ) );
		} elseif ( 'publish' === $new_status && 'publish' === $old_status ) {
			// Update.
			\wp_schedule_single_event( \time(), 'atmosphere_update_post', array( $post->ID ) );
		} elseif ( 'publish' === $old_status && 'publish' !== $new_status ) {
			/*
			 * Genuine unpublish — transitioning away from publish.
			 * Use atmosphere_delete_post (not delete_records) so that
			 * post meta is cleaned up on success, allowing a subsequent
			 * restore (trash → publish) to republish correctly.
			 */
			$bsky_tid = \get_post_meta( $post->ID, Transformer\Post::META_TID, true );
			$doc_tid  = \get_post_meta( $post->ID, Transformer\Document::META_TID, true );
			if ( $bsky_tid || $doc_tid ) {
				\wp_schedule_single_event( \time(), 'atmosphere_delete_post', array( $post->ID ) );
			}
		}
	}

	/**
	 * Schedule AT Protocol record deletion before a post is permanently deleted.
	 *
	 * Captures TIDs from post meta before they're lost, then schedules
	 * an async delete via cron.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function on_before_delete( int $post_id ): void {
		if ( ! is_connected() ) {
			return;
		}

		$post = \get_post( $post_id );

		if ( ! $post || ! \in_array( $post->post_type, Backfill::syncable_post_types(), true ) ) {
			return;
		}

		$bsky_tid = \get_post_meta( $post_id, Transformer\Post::META_TID, true );
		$doc_tid  = \get_post_meta( $post_id, Transformer\Document::META_TID, true );

		if ( $bsky_tid || $doc_tid ) {
			\wp_schedule_single_event( \time(), 'atmosphere_delete_records', array( $bsky_tid, $doc_tid ) );
		}
	}

	/**
	 * Handle a comment transitioning between approval states.
	 *
	 * @param string      $new_status New comment_approved value.
	 * @param string      $old_status Previous comment_approved value.
	 * @param \WP_Comment $comment    Comment object.
	 */
	public function on_comment_status_change( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( 'approved' === $new_status ) {
			$this->schedule_comment_publish( $comment );
			return;
		}

		if ( 'approved' === $old_status ) {
			$this->schedule_comment_delete( $comment );
		}
	}

	/**
	 * Handle a newly-inserted comment.
	 *
	 * Covers the case where a comment lands already-approved (trusted
	 * author), for which transition_comment_status does not fire.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved Approval status (1, 0, or 'spam').
	 */
	public function on_comment_insert( int $comment_id, int|string $comment_approved ): void {
		if ( 1 !== (int) $comment_approved ) {
			return;
		}

		$comment = \get_comment( $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			$this->schedule_comment_publish( $comment );
		}
	}

	/**
	 * Handle a comment edit by updating its bsky record.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment_edit( int $comment_id ): void {
		$comment = \get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		if ( ! self::should_publish_comment( $comment ) ) {
			return;
		}

		$hook = empty( \get_comment_meta( $comment_id, Comment::META_URI, true ) )
			? 'atmosphere_publish_comment'
			: 'atmosphere_update_comment';

		if ( ! \wp_next_scheduled( $hook, array( $comment_id ) ) ) {
			\wp_schedule_single_event( \time(), $hook, array( $comment_id ) );
		}
	}

	/**
	 * Capture a comment's TID before it is permanently deleted.
	 *
	 * Runs on delete_comment which fires before the row and meta are
	 * removed, so the TID is still reachable. META_URI is the only
	 * reliable signal that a record exists on the PDS — the TID is
	 * persisted eagerly by Comment::get_rkey() before the applyWrites
	 * call, so a TID alone matches both the normal pre-publish state
	 * and a publish that failed after TID allocation; neither should
	 * schedule a delete. The TID-only cron variant lets the async
	 * worker issue the PDS delete without re-reading state that no
	 * longer exists.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment_before_delete( int $comment_id ): void {
		if ( ! is_connected() ) {
			return;
		}

		$uri = \get_comment_meta( $comment_id, Comment::META_URI, true );

		if ( empty( $uri ) ) {
			return;
		}

		$tid = \get_comment_meta( $comment_id, Comment::META_TID, true );

		if ( empty( $tid ) ) {
			return;
		}

		$tid  = (string) $tid;
		$args = array( $tid );

		if ( \wp_next_scheduled( 'atmosphere_delete_comment_record', $args ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), 'atmosphere_delete_comment_record', $args );
	}

	/**
	 * Eligibility gate for outbound comment publishing.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return bool
	 */
	public static function should_publish_comment( \WP_Comment $comment ): bool {
		$should = self::is_comment_eligible( $comment );

		/**
		 * Filters whether a comment should be published to Bluesky.
		 *
		 * @param bool        $should  Whether to publish.
		 * @param \WP_Comment $comment Comment object.
		 */
		return (bool) \apply_filters( 'atmosphere_should_publish_comment', $should, $comment );
	}

	/**
	 * Core comment eligibility checks, pre-filter.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return bool
	 */
	private static function is_comment_eligible( \WP_Comment $comment ): bool {
		if ( ! is_connected() ) {
			return false;
		}

		if ( \in_array( (string) $comment->comment_type, array( 'trackback', 'pingback' ), true ) ) {
			return false;
		}

		if ( (int) $comment->user_id <= 0 ) {
			return false;
		}

		if ( '1' !== (string) $comment->comment_approved ) {
			return false;
		}

		if ( 'atproto' === \get_comment_meta( (int) $comment->comment_ID, Reaction_Sync::META_PROTOCOL, true ) ) {
			return false;
		}

		/*
		 * Defence in depth: Reaction_Sync writes META_PROTOCOL after
		 * wp_insert_comment, so if any caller ever fires comment_post
		 * between the insert and the meta write, the gate above would
		 * miss it. The sync always stamps its own agent string, which
		 * is set before the insert — use it as a belt-and-braces check.
		 */
		if ( 0 === \strpos( (string) $comment->comment_agent, 'ATmosphere/' ) ) {
			return false;
		}

		$post_id  = (int) $comment->comment_post_ID;
		$post_uri = \get_post_meta( $post_id, Post::META_URI, true );
		$post_cid = \get_post_meta( $post_id, Post::META_CID, true );

		// Both URI and CID are required to build a valid reply.root strongRef.
		if ( empty( $post_uri ) || empty( $post_cid ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Schedule a publish or update event for a comment.
	 *
	 * @param \WP_Comment $comment Comment object.
	 */
	private function schedule_comment_publish( \WP_Comment $comment ): void {
		if ( ! self::should_publish_comment( $comment ) ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$hook       = empty( \get_comment_meta( $comment_id, Comment::META_URI, true ) )
			? 'atmosphere_publish_comment'
			: 'atmosphere_update_comment';

		if ( \wp_next_scheduled( $hook, array( $comment_id ) ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), $hook, array( $comment_id ) );
	}

	/**
	 * Schedule a delete event when a published comment leaves approved state.
	 *
	 * @param \WP_Comment $comment Comment object.
	 */
	private function schedule_comment_delete( \WP_Comment $comment ): void {
		if ( ! is_connected() ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;

		if ( empty( \get_comment_meta( $comment_id, Comment::META_URI, true ) ) ) {
			return;
		}

		if ( \wp_next_scheduled( 'atmosphere_delete_comment', array( $comment_id ) ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), 'atmosphere_delete_comment', array( $comment_id ) );
	}

	/**
	 * Schedule an async publication sync.
	 */
	public function schedule_publication_sync(): void {
		if ( ! is_connected() ) {
			return;
		}

		if ( ! \wp_next_scheduled( 'atmosphere_sync_publication' ) ) {
			\wp_schedule_single_event( \time(), 'atmosphere_sync_publication' );
		}
	}

	/**
	 * Cron: proactively refresh the access token.
	 */
	public function cron_refresh_token(): void {
		if ( ! is_connected() ) {
			return;
		}

		Client::refresh();
	}

	/**
	 * Register async action hooks (called by WP-Cron).
	 */
	public static function register_async_hooks(): void {
		\add_action(
			'atmosphere_publish_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( $post && 'publish' === $post->post_status ) {
					Publisher::publish_post( $post );
				}
			}
		);

		\add_action(
			'atmosphere_update_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( $post && 'publish' === $post->post_status ) {
					Publisher::update_post( $post );
				}
			}
		);

		\add_action(
			'atmosphere_delete_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( $post ) {
					Publisher::delete_post( $post );
				}
			}
		);

		\add_action(
			'atmosphere_sync_publication',
			static function (): void {
				Publisher::sync_publication();
			}
		);

		\add_action(
			'atmosphere_delete_records',
			static function ( string $bsky_tid, string $doc_tid ): void {
				Publisher::delete_post_by_tids( $bsky_tid, $doc_tid );
			},
			10,
			2
		);

		/*
		 * Cron handlers re-evaluate eligibility at fire time so state
		 * changes between enqueue and execution (approve→unapprove,
		 * unapprove→re-approve, user deleted, etc.) are respected. The
		 * separate transition hooks only schedule; they cannot cancel
		 * an already-queued event, and schedule_comment_delete itself
		 * bails when META_URI is absent (which it is pre-publish), so
		 * without these guards a pre-cron unapprove would still
		 * publish, and a pre-cron re-approve would still delete.
		 *
		 * Publisher WP_Error returns are logged rather than silently
		 * dropped so a flaky PDS window or an expired refresh token
		 * leaves a breadcrumb operators can find.
		 */
		\add_action(
			'atmosphere_publish_comment',
			static function ( int $comment_id ): void {
				$comment = \get_comment( $comment_id );
				if ( ! $comment instanceof \WP_Comment ) {
					return;
				}
				if ( ! self::should_publish_comment( $comment ) ) {
					return;
				}
				if ( self::defer_when_parent_pending( $comment ) ) {
					return;
				}
				\delete_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS );

				$result = Publisher::publish_comment( $comment );
				self::log_cron_error( 'publish_comment', $comment_id, $result );
			}
		);

		\add_action(
			'atmosphere_update_comment',
			static function ( int $comment_id ): void {
				$comment = \get_comment( $comment_id );
				if ( ! $comment instanceof \WP_Comment ) {
					return;
				}
				if ( ! self::should_publish_comment( $comment ) ) {
					return;
				}

				$result = Publisher::update_comment( $comment );
				self::log_cron_error( 'update_comment', $comment_id, $result );
			}
		);

		\add_action(
			'atmosphere_delete_comment',
			static function ( int $comment_id ): void {
				$comment = \get_comment( $comment_id );
				if ( ! $comment instanceof \WP_Comment ) {
					return;
				}
				// If the comment is eligible again by the time cron
				// fires, another transition has superseded the delete.
				if ( self::should_publish_comment( $comment ) ) {
					return;
				}

				$result = Publisher::delete_comment( $comment );
				self::log_cron_error( 'delete_comment', $comment_id, $result );
			}
		);

		\add_action(
			'atmosphere_delete_comment_record',
			static function ( string $tid ): void {
				if ( '' === $tid ) {
					return;
				}

				$result = Publisher::delete_comment_by_tid( $tid );

				if ( \is_wp_error( $result ) ) {
					// Worst-case path: the WP comment row is already gone,
					// so operators need the TID to clean up the orphan
					// record manually.
					\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\sprintf(
							'[atmosphere] delete_comment_record tid=%s failed: %s — %s',
							$tid,
							$result->get_error_code(),
							$result->get_error_message()
						)
					);
				}
			},
			10,
			1
		);
	}

	/**
	 * Defer a child comment publish when its parent is eligible but
	 * has not published to the PDS yet.
	 *
	 * Comments are scheduled as independent single events with no
	 * dependency ordering: if a user approves a parent and its reply
	 * together, the child's cron event can fire first, see
	 * resolve_parent_ref() return null, and publish flat as a
	 * top-level reply on the root post. This defers the child a short
	 * interval (up to PARENT_DEFER_MAX_ATTEMPTS hops) to give the
	 * parent time to publish first. After the cap the child publishes
	 * anyway using the root fallback — a stuck parent must not block
	 * the child forever.
	 *
	 * @param \WP_Comment $comment Comment being published.
	 * @return bool True when the publish was deferred, false to proceed now.
	 */
	private static function defer_when_parent_pending( \WP_Comment $comment ): bool {
		$parent_id = (int) $comment->comment_parent;

		if ( $parent_id <= 0 ) {
			return false;
		}

		$parent = \get_comment( $parent_id );

		if ( ! $parent instanceof \WP_Comment ) {
			return false;
		}

		if ( ! self::should_publish_comment( $parent ) ) {
			// Parent is ineligible (anon, rejected, etc.); resolve_parent_ref
			// will fall back to root, which is the correct behavior.
			return false;
		}

		if ( ! empty( \get_comment_meta( $parent_id, Comment::META_URI, true ) ) ) {
			// Parent is already published — nothing to defer for.
			return false;
		}

		$comment_id = (int) $comment->comment_ID;
		$attempts   = (int) \get_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS, true );

		if ( $attempts >= self::PARENT_DEFER_MAX_ATTEMPTS ) {
			// Give up and publish with root as parent; clear the counter
			// so a future re-publish gets a fresh deferral budget.
			\delete_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS );
			return false;
		}

		\update_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS, $attempts + 1 );
		\wp_schedule_single_event(
			\time() + self::PARENT_DEFER_DELAY_SECONDS,
			'atmosphere_publish_comment',
			array( $comment_id )
		);

		return true;
	}

	/**
	 * Log a WP_Error returned from a comment cron Publisher call.
	 *
	 * `wp_schedule_single_event` does not retry, so a silent drop
	 * here would lose the breadcrumb operators need to diagnose
	 * auth, transport, or PDS-side failures.
	 *
	 * @param string $op         One of 'publish_comment' | 'update_comment' | 'delete_comment'.
	 * @param int    $comment_id Comment ID.
	 * @param mixed  $result     Publisher call result.
	 */
	private static function log_cron_error( string $op, int $comment_id, $result ): void {
		if ( ! \is_wp_error( $result ) ) {
			return;
		}

		\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\sprintf(
				'[atmosphere] %s %d failed: %s — %s',
				$op,
				$comment_id,
				$result->get_error_code(),
				$result->get_error_message()
			)
		);
	}
}
