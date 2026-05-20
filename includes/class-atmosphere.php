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
use Atmosphere\Integrations\Load;
use Atmosphere\WP_Admin\Admin;

/**
 * Atmosphere main class.
 */
class Atmosphere {

	/**
	 * Allowed values for the long-form composition strategy filter and
	 * the matching `atmosphere_long_form_composition` option.
	 */
	public const LONG_FORM_STRATEGIES = array( 'link-card', 'truncate-link', 'teaser-thread' );

	/**
	 * Comment meta key tracking how many times publish has been
	 * deferred waiting for a parent comment to publish first.
	 *
	 * @var string
	 */
	private const META_PUBLISH_ATTEMPTS = '_atmosphere_publish_attempts';

	/**
	 * Post meta marker set when remote records were removed because a
	 * previously public post left public visibility.
	 *
	 * @var string
	 */
	private const META_VISIBILITY_CLEANUP = '_atmosphere_visibility_cleanup';

	/**
	 * Option marking that the historical visibility cleanup migration ran.
	 *
	 * @var string
	 */
	private const OPTION_VISIBILITY_CLEANUP_MIGRATED = 'atmosphere_visibility_cleanup_migrated';

	/**
	 * Option storing the highest post ID processed by the historical
	 * visibility-cleanup migration. Used for keyset (ID > last_seen)
	 * pagination so concurrent deletes don't shift the cursor.
	 *
	 * @var string
	 */
	private const OPTION_VISIBILITY_CLEANUP_LAST_ID = 'atmosphere_visibility_cleanup_last_id';

	/**
	 * Post IDs currently being handled by on_status_change().
	 *
	 * @var array<int,bool>
	 */
	private static array $publishing_post_ids = array();

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

		/*
		 * Seed the long-form composition strategy from the user's
		 * setting. Priority 1 so any downstream filter at the default
		 * priority can still override it per post.
		 */
		\add_filter( 'atmosphere_long_form_composition', array( self::class, 'seed_long_form_composition' ), 1 );

		// REST route (always active for client-metadata).
		\add_action( 'rest_api_init', array( Admin::class, 'register_rest_routes' ) );

		// Frontend verification headers.
		\add_action( 'wp_head', array( $this, 'output_document_link' ) );
		\add_action( 'wp_head', array( $this, 'output_publication_link' ) );

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

		/*
		 * Historical visibility-cleanup migration is queued (not run)
		 * on admin_init by a `manage_options`-capable user, and the
		 * actual batched walk runs in a single-event cron handler.
		 * Splitting the trigger from the work keeps subscribers from
		 * driving the full `posts_per_page => -1` walk on their first
		 * /wp-admin/* hit, and the cron context decouples the long
		 * walk from any specific admin pageload's timeout budget.
		 */
		\add_action( 'admin_init', array( $this, 'maybe_queue_historical_visibility_cleanup' ) );
		\add_action( 'atmosphere_run_historical_visibility_cleanup', array( $this, 'run_historical_visibility_cleanup' ) );

		// Catch permanent deletes (bypassing trash or emptying trash).
		\add_action( 'before_delete_post', array( $this, 'on_before_delete' ) );

		// Comment lifecycle hooks.
		\add_action( 'transition_comment_status', array( $this, 'on_comment_status_change' ), 10, 3 );
		\add_action( 'comment_post', array( $this, 'on_comment_insert' ), 10, 2 );
		\add_action( 'edit_comment', array( $this, 'on_comment_edit' ) );
		\add_action( 'delete_comment', array( $this, 'on_comment_before_delete' ) );

		/*
		 * Auto-sync the publication record whenever something the record
		 * derives from changes. The record bakes in WordPress's site
		 * identity (name, description, icon, home URL) and the active
		 * theme's primary colours; keeping it in lockstep with those
		 * sources avoids a stale publication on the PDS until the next
		 * unrelated event happens to re-sync.
		 *
		 * Triggers cover both surfaces a site administrator can edit
		 * theme colours from: classic-theme Customizer saves
		 * (`customize_save_after`) and block-theme Site Editor saves
		 * (the `wp_global_styles` post update).
		 */
		\add_action( 'update_option_blogname', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'update_option_blogdescription', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'update_option_site_icon', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'update_option_home', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'update_option_siteurl', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'switch_theme', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'save_post_wp_global_styles', array( $this, 'schedule_publication_sync' ) );
		\add_action( 'customize_save_after', array( $this, 'schedule_publication_sync' ) );

		// Token refresh cron.
		\add_action( 'atmosphere_refresh_token', array( $this, 'cron_refresh_token' ) );

		if ( ! \wp_next_scheduled( 'atmosphere_refresh_token' ) && is_connected() ) {
			\wp_schedule_event( \time(), 'twicedaily', 'atmosphere_refresh_token' );
		}

		/*
		 * Async refresh-token revocation, scheduled by
		 * `Client::disconnect()`. The callback is registered for every
		 * request so the worker fires correctly when WP-Cron picks up
		 * the queued event even though the local connection is gone.
		 */
		\add_action(
			'atmosphere_revoke_refresh_token',
			array( Client::class, 'revoke_refresh_token' ),
			10,
			4
		);

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
	 *
	 * Gated on `has_identity()` rather than `is_connected()` so the
	 * verification link survives a temporary OAuth refresh failure —
	 * the document AT-URI is computed from the DID, which is stable
	 * across session expiry and `needs_reauth` states.
	 */
	public function output_document_link(): void {
		if ( ! has_identity() || ! \is_singular() ) {
			return;
		}

		$post = \get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! is_post_publishable( $post ) ) {
			return;
		}

		/*
		 * Route the TID lookup through `Document::get_rkey()` so the
		 * lazy mint here writes `META_DID` alongside `META_TID`. The
		 * inlined fallback that used to live here would have left the
		 * row in a "TID set, no DID" state, which the mismatch guard
		 * in `Publisher::delete_post()` treats as "DID unknown, fall
		 * through to `get_did()`" — re-opening the wrong-repo-delete
		 * bypass after a reconnect-to-different-account.
		 */
		$doc_tid = ( new Document( $post ) )->get_rkey();

		$uri = build_at_uri( get_did(), 'site.standard.document', $doc_tid );

		\printf(
			'<link rel="site.standard.document" href="%s" />' . "\n",
			\esc_attr( $uri )
		);
	}

	/**
	 * Output `<link rel="site.standard.publication">` on the URLs that
	 * map to the publication record's `url` field.
	 *
	 * Emitted on:
	 *
	 * - Singular publishable posts, so a resolver landing on an article
	 *   URL can find the parent publication directly without first
	 *   fetching the document record.
	 * - The WordPress front page, since the publication record's `url`
	 *   field is `home_url('/')`. Lets a resolver verify the page <->
	 *   publication binding by matching AT-URIs, sparing the
	 *   `.well-known/site.standard.publication` round-trip.
	 *
	 * Gated on `has_identity()` (not `is_connected()`) so the
	 * verification link survives transient OAuth refresh failures, in
	 * lockstep with {@see Atmosphere::output_document_link()}.
	 */
	public function output_publication_link(): void {
		if ( ! has_identity() ) {
			return;
		}

		$pub_tid = \get_option( Publication::OPTION_TID );

		if ( ! $pub_tid ) {
			return;
		}

		if ( ! self::is_publication_url() ) {
			return;
		}

		$uri = build_at_uri( get_did(), 'site.standard.publication', $pub_tid );

		\printf(
			'<link rel="site.standard.publication" href="%s" />' . "\n",
			\esc_attr( $uri )
		);
	}

	/**
	 * Whether the current request URL maps to the publication record's
	 * `url` field — i.e. a URL where the `<link rel="site.standard.publication">`
	 * tag belongs.
	 *
	 * - The WordPress front page always qualifies, regardless of
	 *   whether it shows posts or a static page (a static page set
	 *   as front is both `is_front_page()` AND `is_singular('page')`;
	 *   checking the front-page condition first is what keeps the
	 *   tag emitting in that configuration).
	 * - A publishable singular post qualifies because its document
	 *   record carries a reference back to the publication.
	 */
	private static function is_publication_url(): bool {
		if ( \is_front_page() ) {
			return true;
		}

		if ( ! \is_singular() ) {
			return false;
		}

		$post = \get_queried_object();

		return $post instanceof \WP_Post && is_post_publishable( $post );
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

		/*
		 * Identity gate (not connection gate): an expired OAuth session
		 * must not break domain handle verification. Bluesky's resolver
		 * re-fetches this endpoint to confirm the bidirectional link
		 * each time a profile loads, so a transient token failure
		 * otherwise propagates as "handle no longer resolves" until the
		 * site admin reconnects.
		 */
		if ( ! has_identity() ) {
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

		/*
		 * Identity gate (not connection gate): the publication AT-URI is
		 * derived from the persisted DID + publication TID, both of
		 * which outlive a transient OAuth refresh failure. Returning 404
		 * here while waiting for the user to reconnect would break
		 * standard.site's bidirectional verification each time the
		 * token rotates.
		 */
		if ( ! has_identity() ) {
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

		if ( ! is_supported_post_type( $post->post_type ) ) {
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

		$is_publishable         = is_post_publishable( $post );
		$has_records            = self::has_post_records( $post );
		$had_visibility_cleanup = self::has_visibility_cleanup_marker( $post );
		$is_new_publish         = $is_publishable && ! $has_records && ( 'publish' !== $old_status || $had_visibility_cleanup );
		$is_update              = $is_publishable && ! $is_new_publish;
		$is_cleanup             = 'publish' === $old_status && ! $is_publishable && $has_records;

		if ( ! $is_new_publish && ! $is_update && ! $is_cleanup ) {
			// Transition between two non-publish states; nothing to schedule.
			return;
		}

		/*
		 * Publish-time decisions respect current public visibility.
		 * Cleanup is different: if a previously-published post becomes
		 * non-public (draft/private/trash, password-protected, or no
		 * longer supported), remote records must be removed even though
		 * the post is no longer publishable.
		 */
		if ( isset( self::$publishing_post_ids[ $post->ID ] ) ) {
			return;
		}

		self::$publishing_post_ids[ $post->ID ] = true;

		/*
		 * Wrap in try/finally so a throwing listener on
		 * `atmosphere_publishing` (Sentry SDK, JSON_THROW_ON_ERROR in
		 * a webhook sink, etc.) can't strand the per-post guard. A
		 * stuck entry silently no-ops every subsequent transition of
		 * the same post ID in the current PHP process — especially
		 * painful for WP-CLI bulk imports where one fatal early in
		 * the run poisons every later transition of that ID.
		 */
		try {
			\do_action( 'atmosphere_publishing', $post );

			if ( $is_publishable ) {
				\wp_clear_scheduled_hook( 'atmosphere_delete_post', array( $post->ID ) );
			}

			if ( $is_new_publish ) {
				\wp_schedule_single_event( \time(), 'atmosphere_publish_post', array( $post->ID ) );
			} elseif ( $is_update ) {
				\wp_schedule_single_event( \time(), 'atmosphere_update_post', array( $post->ID ) );
			} else {
				self::mark_visibility_cleanup( $post );

				/*
				 * Genuine unpublish — use atmosphere_delete_post (not
				 * delete_records) so post meta is cleaned up on success,
				 * allowing a subsequent restore (trash → publish) to
				 * republish correctly.
				 */
				\wp_schedule_single_event( \time(), 'atmosphere_delete_post', array( $post->ID ) );
			}
		} finally {
			unset( self::$publishing_post_ids[ $post->ID ] );
		}
	}

	/**
	 * Max posts the historical migration scans per cron tick.
	 *
	 * Bounded so a site with thousands of historical Atmosphere
	 * records can't blow the cron handler's execution-time budget on
	 * a single fire. The handler reschedules itself until the walk is
	 * exhausted, then sets `OPTION_VISIBILITY_CLEANUP_MIGRATED`.
	 *
	 * @var int
	 */
	private const VISIBILITY_CLEANUP_BATCH_SIZE = 200;

	/**
	 * Queue the historical visibility-cleanup walk if needed.
	 *
	 * Runs on `admin_init`. Bails on subscriber-level users so the
	 * cron event is only ever scheduled by an actual administrator,
	 * even though the walk itself runs in cron context. Bails on
	 * any other condition that would re-schedule the same event,
	 * keeping concurrent admin pageloads from queuing duplicates.
	 */
	public function maybe_queue_historical_visibility_cleanup(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! is_connected() ) {
			return;
		}

		if ( \get_option( self::OPTION_VISIBILITY_CLEANUP_MIGRATED ) ) {
			return;
		}

		if ( \wp_next_scheduled( 'atmosphere_run_historical_visibility_cleanup' ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), 'atmosphere_run_historical_visibility_cleanup' );
	}

	/**
	 * Cron handler: walk a single batch of historical posts and queue
	 * cleanup for those that lost public visibility.
	 *
	 * Uses keyset (ID > last_seen) paging rather than `offset` — once
	 * a post's records are deleted, the migration's `meta_query` no
	 * longer matches it, so offset-paged windows would skip ahead by
	 * roughly the number of completed deletes per batch. Keyset
	 * paging is stable against in-flight deletes.
	 *
	 * Reschedules itself for the next batch BEFORE processing so a
	 * mid-batch fatal (OOM, listener fatal) still leaves a recovery
	 * breadcrumb — the next cron tick picks up at the persisted
	 * cursor. An empty batch terminates the walk and sets the
	 * one-shot migration option.
	 */
	public function run_historical_visibility_cleanup(): void {
		if ( \get_option( self::OPTION_VISIBILITY_CLEANUP_MIGRATED ) ) {
			return;
		}

		$last_id = (int) \get_option( self::OPTION_VISIBILITY_CLEANUP_LAST_ID, 0 );

		global $wpdb;

		/*
		 * Raw query because WP_Query's `offset` is unstable under
		 * concurrent deletes (see method docblock). The meta-key list
		 * mirrors the OR EXISTS branches the original `meta_query`
		 * used; `DISTINCT` collapses posts that match on multiple
		 * keys (very common — most synced posts have both `bsky_tid`
		 * and `doc_tid`).
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.ID > %d
				   AND pm.meta_key IN (%s, %s, %s, %s)
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$last_id,
				Post::META_TID,
				Post::META_URI,
				Post::META_THREAD_RECORDS,
				Document::META_URI,
				self::VISIBILITY_CLEANUP_BATCH_SIZE
			)
		);
		// phpcs:enable

		if ( empty( $post_ids ) ) {
			\delete_option( self::OPTION_VISIBILITY_CLEANUP_LAST_ID );
			\update_option( self::OPTION_VISIBILITY_CLEANUP_MIGRATED, '1', false );
			return;
		}

		/*
		 * Persist the cursor BEFORE processing the batch and queue
		 * the next run BEFORE the foreach. A fatal mid-batch then
		 * leaves a recovery breadcrumb (the next cron pulls up at
		 * the cursor we've already advanced past in the DB query but
		 * not in the persisted state) rather than stranding the
		 * migration until a manage_options admin hits admin_init.
		 */
		$max_id = (int) \end( $post_ids );
		\update_option( self::OPTION_VISIBILITY_CLEANUP_LAST_ID, $max_id, false );

		\wp_schedule_single_event( \time() + 60, 'atmosphere_run_historical_visibility_cleanup' );

		foreach ( $post_ids as $post_id ) {
			$post = \get_post( (int) $post_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( is_post_publishable( $post ) || ! self::has_post_records( $post ) ) {
				continue;
			}

			self::mark_visibility_cleanup( $post );

			if ( \wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ) ) {
				continue;
			}

			\wp_schedule_single_event( \time(), 'atmosphere_delete_post', array( $post->ID ) );
		}
	}

	/**
	 * Whether the post has local metadata for remote records.
	 *
	 * Used to distinguish a cleanup-worthy post from a non-public post
	 * that never reached the PDS.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private static function has_post_records( \WP_Post $post ): bool {
		/*
		 * Drop `Document::META_TID` from the "has records" signal,
		 * keep everything else.
		 *
		 * `Document::META_TID` is the only meta key that can be
		 * written speculatively: `output_document_link()` (frontend
		 * `wp_head`) lazily mints it on the first singular pageview
		 * so the `<link rel="site.standard.document">` tag has
		 * something to point at — well before any publish to the
		 * PDS. Treating that pre-publish TID stamp as "has records"
		 * misclassifies every transition to publish as `is_update`,
		 * the cron handler refuses because no URI/CID exists to
		 * update against, and the publish silently no-ops.
		 *
		 * `Post::META_TID` is different: Publisher writes it only
		 * after a successful `applyWrites`, alongside `META_URI` /
		 * `META_CID`. It's an honest signal of an existing record
		 * and remains a positive indicator here.
		 */
		return ! empty( \get_post_meta( $post->ID, Transformer\Post::META_TID, true ) )
			|| ! empty( \get_post_meta( $post->ID, Transformer\Post::META_URI, true ) )
			|| ! empty( \get_post_meta( $post->ID, Transformer\Post::META_THREAD_RECORDS, true ) )
			|| ! empty( \get_post_meta( $post->ID, Transformer\Document::META_URI, true ) );
	}

	/**
	 * Whether this post previously had records removed for visibility.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private static function has_visibility_cleanup_marker( \WP_Post $post ): bool {
		return (bool) \get_post_meta( $post->ID, self::META_VISIBILITY_CLEANUP, true );
	}

	/**
	 * Mark a post as needing fresh publish if it becomes public again.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public static function mark_visibility_cleanup( \WP_Post $post ): void {
		\update_post_meta( $post->ID, self::META_VISIBILITY_CLEANUP, '1' );
	}

	/**
	 * Clear the visibility-cleanup marker after a successful publish/update.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function clear_visibility_cleanup_marker( \WP_Post $post ): void {
		\delete_post_meta( $post->ID, self::META_VISIBILITY_CLEANUP );
	}

	/**
	 * Schedule AT Protocol record deletion before a post is permanently deleted.
	 *
	 * Captures every Bluesky TID (post root + thread replies + outbound
	 * comment replies) and the document TID from post meta, then
	 * schedules a single async batch delete via cron. Thread-strategy
	 * posts read every TID from `Post::META_THREAD_RECORDS`; outbound
	 * comment replies come from `Publisher::collect_published_comment_tids()`.
	 *
	 * Comment TIDs must be collected here, while WP still has the
	 * comment rows: `wp_delete_post( $id, true )` fires `before_delete_post`
	 * first and only then iterates child comments, so this is the last
	 * opportunity to read them.
	 *
	 * The trash path (`Publisher::delete_post()`) already cascades
	 * comment deletes; this keeps the permanent-delete path symmetric
	 * so unpublishing or hard-deleting a post does not orphan its
	 * outbound replies on the PDS.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function on_before_delete( int $post_id ): void {
		if ( ! is_connected() ) {
			return;
		}

		$post = \get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		/*
		 * No support check here. Permanent delete is a cleanup path: if
		 * the post has Atmosphere publication metadata it was synced at
		 * some point, and the remote records must be removed even if the
		 * post type has since been removed from the supported list.
		 * Gating this on current support would orphan already-published
		 * records whenever a site narrows its configuration.
		 */
		$bsky_tids = array();

		$thread_records = \get_post_meta( $post_id, Transformer\Post::META_THREAD_RECORDS, true );
		if ( \is_array( $thread_records ) && ! empty( $thread_records ) ) {
			foreach ( $thread_records as $record ) {
				if ( ! empty( $record['tid'] ) ) {
					$bsky_tids[] = (string) $record['tid'];
				}
			}
		}

		if ( empty( $bsky_tids ) ) {
			$legacy_tid = \get_post_meta( $post_id, Transformer\Post::META_TID, true );
			if ( $legacy_tid ) {
				$bsky_tids[] = (string) $legacy_tid;
			}
		}

		$doc_tid = (string) \get_post_meta( $post_id, Transformer\Document::META_TID, true );

		$comment_tids = \array_column(
			Publisher::collect_published_comment_tids( $post_id ),
			'tid'
		);

		if ( ! empty( $bsky_tids ) || '' !== $doc_tid || ! empty( $comment_tids ) ) {
			\wp_schedule_single_event(
				\time(),
				'atmosphere_delete_records',
				array( $bsky_tids, $doc_tid, $comment_tids )
			);
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

		$post_id = (int) $comment->comment_post_ID;

		/*
		 * Drop the in-process `WP_Post` cache so a concurrent web
		 * request that just password-protected the parent is visible
		 * to this worker. Same exposure as the publisher reconcile
		 * path on installs without a persistent object cache: without
		 * this invalidation, the cron handler would publish the reply
		 * against a now-protected parent.
		 */
		\clean_post_cache( $post_id );
		$post = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! is_post_publishable( $post ) ) {
			return false;
		}

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
	 * Seed the `atmosphere_long_form_composition` filter from the option.
	 *
	 * Returns the configured strategy when valid; otherwise returns the
	 * incoming `$strategy` (so downstream filters and the `link-card`
	 * default still apply). An invalid stored value is logged at most
	 * once per hour so operators can spot config drift.
	 *
	 * @param string $strategy Strategy passed in by `apply_filters()`.
	 * @return string
	 */
	public static function seed_long_form_composition( string $strategy ): string {
		$option = (string) \get_option( 'atmosphere_long_form_composition', 'link-card' );

		if ( \in_array( $option, self::LONG_FORM_STRATEGIES, true ) ) {
			return $option;
		}

		if ( ! \get_transient( 'atmosphere_invalid_long_form_composition_logged' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				\sprintf(
					'[atmosphere] invalid `atmosphere_long_form_composition` option value %s; falling through to default',
					\wp_json_encode( $option )
				)
			);
			\set_transient( 'atmosphere_invalid_long_form_composition_logged', 1, \HOUR_IN_SECONDS );
		}

		return $strategy;
	}

	/**
	 * Register async action hooks (called by WP-Cron).
	 */
	public static function register_async_hooks(): void {
		/*
		 * Publish/update cron callbacks re-check post visibility.
		 * A user (or downstream filter) can password-protect a post,
		 * unpublish it, or disable its post type after a cron event was
		 * queued, and we must not still publish it.
		 *
		 * The delete callback uses the inverse gate: if the post has
		 * become publishable again, update the existing records instead
		 * of deleting them; otherwise clean up any existing remote records.
		 */
		\add_action(
			'atmosphere_publish_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( ! $post ) {
					return;
				}
				if ( is_post_publishable( $post ) ) {
					$result = self::has_post_records( $post )
						? Publisher::update_post( $post )
						: Publisher::publish_post( $post );
					self::log_cron_error( 'publish_post', $post_id, $result );
					if ( ! \is_wp_error( $result ) ) {
						self::clear_visibility_cleanup_marker( $post );
					}
					return;
				}
				if ( self::has_post_records( $post ) ) {
					self::mark_visibility_cleanup( $post );
					self::log_cron_error( 'delete_post', $post_id, Publisher::delete_post( $post ) );
				}
			}
		);

		\add_action(
			'atmosphere_update_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( ! $post ) {
					return;
				}
				if ( is_post_publishable( $post ) ) {
					$result = self::has_post_records( $post ) || ! self::has_visibility_cleanup_marker( $post )
						? Publisher::update_post( $post )
						: Publisher::publish_post( $post );
					self::log_cron_error( 'update_post', $post_id, $result );
					if ( ! \is_wp_error( $result ) ) {
						self::clear_visibility_cleanup_marker( $post );
					}
					return;
				}
				if ( self::has_post_records( $post ) ) {
					self::mark_visibility_cleanup( $post );
					self::log_cron_error( 'delete_post', $post_id, Publisher::delete_post( $post ) );
				}
			}
		);

		\add_action(
			'atmosphere_delete_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( $post ) {
					if ( is_post_publishable( $post ) ) {
						$result = self::has_post_records( $post ) || ! self::has_visibility_cleanup_marker( $post )
							? Publisher::update_post( $post )
							: Publisher::publish_post( $post );
						self::log_cron_error( 'delete_post_publishable_reconcile', $post_id, $result );
						if ( ! \is_wp_error( $result ) ) {
							self::clear_visibility_cleanup_marker( $post );
						}
						return;
					}
					if ( self::has_post_records( $post ) ) {
						self::mark_visibility_cleanup( $post );
						self::log_cron_error( 'delete_post', $post_id, Publisher::delete_post( $post ) );
					}
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
			static function ( $bsky_tids, string $doc_tid, $comment_tids = array() ): void {
				$comment_tids = \is_array( $comment_tids ) ? $comment_tids : array();
				$result       = Publisher::delete_post_by_tids( $bsky_tids, $doc_tid, $comment_tids );

				if ( \is_wp_error( $result ) ) {
					/*
					 * One-shot cron event with no retry: dropping this error
					 * would orphan every record in the cascade (root + thread
					 * replies + outbound comment replies + document) on the
					 * PDS with no operator-visible breadcrumb.
					 */
					\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\sprintf(
							'[atmosphere] delete_records failed (bsky=%d, doc=%s, comments=%d): %s — %s',
							\is_array( $bsky_tids ) ? \count( $bsky_tids ) : (int) ! empty( $bsky_tids ),
							$doc_tid ? 'yes' : 'no',
							\count( $comment_tids ),
							$result->get_error_code(),
							$result->get_error_message()
						)
					);
				}
			},
			10,
			3
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
				if ( ! self::parent_has_bsky_representation( $comment ) ) {
					/*
					 * Parent is local-only: anonymous WP commenter, an
					 * ineligible comment that will never publish, or a
					 * federation source other than bsky. Publishing the
					 * reply anyway would either fail at strongRef
					 * construction or fall back to a top-level reply on
					 * the post (losing the WP thread context). Skip
					 * instead, and clear the deferral counter so a
					 * future re-publish (e.g. if the parent gains a URI
					 * later) gets a fresh budget.
					 */
					\delete_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS );
					return;
				}
				\delete_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS );

				$result = Publisher::publish_comment( $comment );
				self::log_cron_error( 'publish_comment', $comment_id, $result );

				if ( ! \is_wp_error( $result ) ) {
					self::reconcile_comment_after_publish( $comment_id );
				}
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
			// Parent is ineligible (anon, rejected, etc.). No reason to
			// defer — it will never gain a bsky URI. The subsequent
			// `parent_has_bsky_representation()` check in the cron
			// handler will skip the publish entirely so we don't
			// promote a nested WP reply into a confusing top-level
			// bsky reply on the post.
			return false;
		}

		if ( ! empty( \get_comment_meta( $parent_id, Comment::META_URI, true ) ) ) {
			// Parent is already published — nothing to defer for.
			return false;
		}

		$comment_id = (int) $comment->comment_ID;
		$attempts   = (int) \get_comment_meta( $comment_id, self::META_PUBLISH_ATTEMPTS, true );

		if ( $attempts >= self::PARENT_DEFER_MAX_ATTEMPTS ) {
			// Give up on the deferral budget; clear the counter so a
			// future re-publish gets a fresh budget. The subsequent
			// `parent_has_bsky_representation()` check skips the publish
			// rather than letting `build_reply_ref()` fall back to a
			// top-level reply on the post, which would lose the WP
			// thread context.
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
	 * Whether the comment's immediate WP parent has an AT Protocol
	 * representation that the reply record can thread under.
	 *
	 * Returns true when:
	 *
	 * - The comment has no parent (top-level reply to the post). The
	 *   post itself has a bsky record; the publish path threads against
	 *   that root.
	 * - The parent comment carries {@see Comment::META_URI} — the
	 *   plugin already published the parent to the PDS.
	 * - The parent comment is marked as ingested by
	 *   {@see Reaction_Sync} (`META_PROTOCOL = atproto`) — it came in
	 *   from the bsky side, and the bsky URI / CID needed for the
	 *   reply strongRef are on the row.
	 *
	 * Otherwise the parent is local-only: an anonymous WP commenter, an
	 * un-publishable comment, or a comment from a different federation
	 * source. Publishing a reply to it would either fail at strongRef
	 * construction or — worse — silently promote the nested reply to a
	 * top-level post via the root fallback in
	 * {@see Comment::build_reply_ref()}, severing the WP thread context.
	 * The cron handler skips the publish in that case.
	 *
	 * @param \WP_Comment $comment Comment about to be published.
	 * @return bool
	 */
	private static function parent_has_bsky_representation( \WP_Comment $comment ): bool {
		$parent_id = (int) $comment->comment_parent;

		if ( $parent_id <= 0 ) {
			return true;
		}

		if ( ! empty( \get_comment_meta( $parent_id, Comment::META_URI, true ) ) ) {
			return true;
		}

		return 'atproto' === \get_comment_meta( $parent_id, Reaction_Sync::META_PROTOCOL, true );
	}

	/**
	 * Log a WP_Error returned from a comment cron Publisher call.
	 *
	 * `wp_schedule_single_event` does not retry, so a silent drop
	 * here would lose the breadcrumb operators need to diagnose
	 * auth, transport, or PDS-side failures.
	 *
	 * @param string $op        Operation name.
	 * @param int    $object_id Post or comment ID.
	 * @param mixed  $result    Publisher call result.
	 */
	public static function log_cron_error( string $op, int $object_id, $result ): void {
		if ( ! \is_wp_error( $result ) ) {
			return;
		}

		/*
		 * PDS error messages flow through `WP_Error::get_error_message()`
		 * via `API::apply_writes` and can include attacker-controlled
		 * bytes (CRLF, ANSI escapes, fake `[atmosphere]` prefixes that
		 * imitate other log lines). `error_log` does not escape them,
		 * so a misbehaving PDS could otherwise smuggle multiline noise
		 * into log-shipping pipelines that parse line prefixes.
		 */
		$message = \str_replace( array( "\r", "\n" ), ' ', $result->get_error_message() );

		\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\sprintf(
				'[atmosphere] %s %d failed: %s — %s',
				$op,
				$object_id,
				$result->get_error_code(),
				$message
			)
		);
	}

	/**
	 * Public alias for {@see Atmosphere::log_cron_error()} used by the
	 * Publisher reconcile path. Routes the cleanup-delete failure
	 * through a stable op label (`reconcile_cleanup`) so monitors do
	 * not confuse it with the original publish failure.
	 *
	 * @param int   $post_id Post ID whose reconcile cleanup failed.
	 * @param mixed $result  `WP_Error` from `Publisher::delete_post()`.
	 */
	public static function log_reconcile_cleanup_error( int $post_id, $result ): void {
		self::log_cron_error( 'reconcile_cleanup', $post_id, $result );
	}

	/**
	 * Roll back a successful publish if the comment became ineligible
	 * during the in-flight applyWrites.
	 *
	 * The race: `Comment::get_rkey()` persists META_TID before the API
	 * call, and META_URI is only written after success. Both
	 * `schedule_comment_delete` and `on_comment_before_delete` require
	 * META_URI to schedule cleanup. A moderator who deletes or
	 * unapproves the comment while applyWrites is in flight therefore
	 * leaves a live Bluesky reply with no scheduled cleanup once
	 * `store_comment_result()` finally writes META_URI.
	 *
	 * Re-checking eligibility after publish closes that race. If the
	 * comment is gone or no longer eligible, we clear the meta we just
	 * wrote and schedule the same TID-only delete event the
	 * permanent-delete path uses, so transient PDS failures retry via
	 * the standard cleanup channel rather than getting dropped here.
	 *
	 * @param int $comment_id Comment ID just published.
	 */
	private static function reconcile_comment_after_publish( int $comment_id ): void {
		/*
		 * Drop the in-process `WP_Comment` cache so a concurrent web
		 * request that just unapproved or deleted this comment is
		 * visible to the reconcile re-check. Same exposure as the
		 * publisher reconcile path on installs without a persistent
		 * object cache: without this invalidation, a moderator's
		 * mid-publish unapprove races the post-publish read and the
		 * Bluesky reply stays live with no cleanup scheduled.
		 */
		\clean_comment_cache( $comment_id );
		$fresh = \get_comment( $comment_id );

		if ( $fresh instanceof \WP_Comment && self::should_publish_comment( $fresh ) ) {
			return;
		}

		$tid = (string) \get_comment_meta( $comment_id, Comment::META_TID, true );

		\delete_comment_meta( $comment_id, Comment::META_TID );
		\delete_comment_meta( $comment_id, Comment::META_URI );
		\delete_comment_meta( $comment_id, Comment::META_CID );
		\delete_comment_meta( $comment_id, Reaction_Sync::META_SOURCE_ID );

		if ( '' === $tid ) {
			return;
		}

		$args = array( $tid );

		if ( \wp_next_scheduled( 'atmosphere_delete_comment_record', $args ) ) {
			return;
		}

		\wp_schedule_single_event( \time(), 'atmosphere_delete_comment_record', $args );
	}
}
