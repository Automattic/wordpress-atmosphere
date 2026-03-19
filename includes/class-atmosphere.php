<?php
/**
 * Main plugin initialization and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Publication;
use Atmosphere\Transformer\TID;
use Atmosphere\WP_Admin\Admin;

/**
 * Atmosphere main class.
 */
class Atmosphere {

	/**
	 * Wire up all hooks.
	 */
	public function init(): void {
		// Admin.
		if ( \is_admin() ) {
			Admin::register();
			Backfill::register();
		}

		// REST route (always active for client-metadata).
		\add_action( 'rest_api_init', array( Admin::class, 'register_rest_routes' ) );

		// Frontend verification headers.
		\add_action( 'wp_head', array( $this, 'output_document_link' ) );

		// Well-known publication endpoint.
		\add_action( 'init', array( $this, 'register_wellknown_rewrite' ) );
		\add_action( 'template_redirect', array( $this, 'serve_wellknown_publication' ) );

		// Post lifecycle hooks.
		\add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );

		// Token refresh cron.
		\add_action( 'atmosphere_refresh_token', array( $this, 'cron_refresh_token' ) );

		if ( ! \wp_next_scheduled( 'atmosphere_refresh_token' ) && is_connected() ) {
			\wp_schedule_event( \time(), 'twicedaily', 'atmosphere_refresh_token' );
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
	 * Register the rewrite rule for /.well-known/site.standard.publication.
	 */
	public function register_wellknown_rewrite(): void {
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

		if ( ! \get_option( 'atmosphere_auto_publish', '1' ) ) {
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
			// Unpublish / trash.
			\wp_schedule_single_event( \time(), 'atmosphere_delete_post', array( $post->ID ) );
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
					Publisher::publish( $post );
				}
			}
		);

		\add_action(
			'atmosphere_update_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( $post && 'publish' === $post->post_status ) {
					Publisher::update( $post );
				}
			}
		);

		\add_action(
			'atmosphere_delete_post',
			static function ( int $post_id ): void {
				$post = \get_post( $post_id );
				if ( $post ) {
					Publisher::delete( $post );
				}
			}
		);
	}
}

// Register async hooks outside the class so they're available to WP-Cron.
Atmosphere::register_async_hooks();
