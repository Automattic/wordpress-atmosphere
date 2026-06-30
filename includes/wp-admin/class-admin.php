<?php
/**
 * Admin settings page and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Atmosphere;
use Atmosphere\Handle;
use Atmosphere\OAuth\Client;
use Atmosphere\Publisher;
use function Atmosphere\get_connection;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\has_identity;
use function Atmosphere\needs_reauth;

/**
 * Admin class.
 */
class Admin {

	/**
	 * Boot admin hooks.
	 *
	 * Settings API option registration and Settings page UI assembly
	 * live in dedicated `Atmosphere\Options` and
	 * `Atmosphere\WP_Admin\Settings_Fields` classes (mirroring the
	 * ActivityPub plugin's layout); both self-register their hooks
	 * from `Atmosphere::init()`. Admin only wires the admin-only
	 * surfaces here: menu page, OAuth callback handler, asset enqueue,
	 * reauth notice, and admin-post handlers.
	 */
	public static function register(): void {
		\add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		\add_action( 'admin_init', array( self::class, 'handle_oauth_callback' ) );
		\add_action( 'admin_init', array( self::class, 'maybe_set_domain_handle' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		\add_action( 'admin_notices', array( self::class, 'maybe_render_reauth_notice' ) );
		\add_action( 'load-settings_page_atmosphere', array( self::class, 'maybe_warn_missing_post_types' ) );

		\add_action( 'admin_post_atmosphere_disconnect', array( self::class, 'handle_disconnect' ) );
	}

	/**
	 * Register the settings page under Settings.
	 */
	public static function add_menu(): void {
		$hook = \add_options_page(
			\__( 'ATmosphere', 'atmosphere' ),
			\__( 'ATmosphere', 'atmosphere' ),
			'manage_options',
			'atmosphere',
			array( self::class, 'render_page' )
		);

		if ( ! $hook ) {
			return;
		}

		/*
		 * Self-heal the well-known rewrite rules whenever an
		 * administrator loads our settings page — the surface where this
		 * bug surfaces, and so the right place to silently fix it.
		 * Hooked on `load-{suffix}` so it runs before the page renders
		 * and only on our page, not on every admin request. See
		 * {@see Atmosphere::maybe_flush_wellknown_rewrites()} for detail.
		 */
		\add_action( "load-{$hook}", array( Atmosphere::class, 'maybe_flush_wellknown_rewrites' ) );
	}

	/**
	 * Enqueue admin CSS/JS on our settings page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_atmosphere' !== $hook_suffix ) {
			return;
		}

		\wp_enqueue_style(
			'atmosphere-admin',
			ATMOSPHERE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ATMOSPHERE_VERSION
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		include ATMOSPHERE_PLUGIN_DIR . 'templates/settings-page.php';
	}

	/**
	 * Handle the OAuth callback (code + state in query string).
	 */
	public static function handle_oauth_callback(): void {
		$page = \filter_input( INPUT_GET, 'page' );
		if ( 'atmosphere' !== $page ) {
			return;
		}

		$code  = \filter_input( INPUT_GET, 'code' );
		$state = \filter_input( INPUT_GET, 'state' );

		if ( null === $code || null === $state ) {
			return;
		}

		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = Client::handle_callback(
			\sanitize_text_field( $code ),
			\sanitize_text_field( $state )
		);

		if ( \is_wp_error( $result ) ) {
			\add_settings_error( 'atmosphere', 'callback_failed', $result->get_error_message() );
			return;
		}

		// Auto-create publication on first connect.
		Publisher::sync_publication();

		\add_settings_error(
			'atmosphere',
			'connected',
			\__( 'Successfully connected to AT Protocol.', 'atmosphere' ),
			'success'
		);
		\set_transient( 'settings_errors', \get_settings_errors(), 30 );

		\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere&connected=1' ) );
		exit;
	}

	/**
	 * Trigger `Handle::set_handle()` when the settings form is
	 * submitted with the "Use my domain as my Bluesky handle" button.
	 *
	 * The button renders inside the WP Settings form and carries
	 * `name="atmosphere_set_domain_handle" value="1"`. When clicked,
	 * the form POSTs to `options.php` like any other settings save.
	 * Routing the trigger through a dedicated `admin-post.php?action=…`
	 * endpoint instead collides with `settings_fields()`'s hidden
	 * `<input name="action" value="update">` field — POST wins in
	 * `$_REQUEST['action']` and the click ends up dispatched to
	 * `admin_post_update`. Detecting the field here on `admin_init`,
	 * before options.php runs, keeps the action inside the same
	 * form-submit lifecycle without conflicting concerns.
	 *
	 * Bails silently if the trigger field is absent (normal Save
	 * Changes path) or if the request fails any of the standard
	 * settings-form guards (capability, option group, nonce). On
	 * success `Handle::set_handle()` posts its own settings notice;
	 * options.php's own redirect surfaces the notice on the next
	 * pageview without us having to intercept the redirect here.
	 */
	public static function maybe_set_domain_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; nonce is verified below before any side effect.
		if ( empty( $_POST['atmosphere_set_domain_handle'] ) ) {
			return;
		}

		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same as above; nonce verified on the next line.
		$option_page = isset( $_POST['option_page'] ) ? \sanitize_key( \wp_unslash( $_POST['option_page'] ) ) : '';
		if ( 'atmosphere' !== $option_page ) {
			return;
		}

		\check_admin_referer( 'atmosphere-options' );

		Handle::set_handle();
	}

	/**
	 * Handle the "Disconnect" action.
	 */
	public static function handle_disconnect(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Unauthorized.', 'atmosphere' ) );
		}

		\check_admin_referer( 'atmosphere_disconnect', 'atmosphere_nonce' );

		Client::disconnect();

		\add_settings_error(
			'atmosphere',
			'disconnected',
			\__( 'Disconnected from AT Protocol.', 'atmosphere' ),
			'info'
		);
		\set_transient( 'settings_errors', \get_settings_errors(), 30 );

		\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere' ) );
		exit;
	}

	/**
	 * Render a global admin notice when the OAuth session needs reauth.
	 *
	 * Surfaced on every admin screen (gated on `manage_options`) because
	 * the publish, comment, and update paths silently no-op until the
	 * user reconnects — without a visible nudge, an expired refresh
	 * token can sit unnoticed for days. The notice is dismissible per
	 * page-load only so the user is reminded again on their next visit.
	 *
	 * Swaps copy when the disconnect was operator-initiated (the user
	 * clicked Disconnect) so the message does not falsely claim "your
	 * session has expired" for a state the user just chose.
	 */
	public static function maybe_render_reauth_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! needs_reauth() ) {
			return;
		}

		$settings_url = \admin_url( 'options-general.php?page=atmosphere' );

		/*
		 * Treat the explicit-disconnect marker as authoritative only
		 * when the connection row is genuinely empty. `Client::disconnect()`
		 * deletes `atmosphere_connection` before any other admin request
		 * can land, so a missing connection alongside the marker is a
		 * true operator-initiated disconnect. After a refresh failure,
		 * the connection row stays put (with `needs_reauth = true` and
		 * an emptied access_token) — if a stale marker from an earlier
		 * disconnect survived (e.g. `handle_callback()`'s `delete_option`
		 * silently failed at a cache layer), the connection's presence
		 * outs the marker as stale and the gate falls through to the
		 * "session expired" copy, which is the accurate framing for the
		 * actual failure mode.
		 */
		$disconnected = \get_option( Client::DISCONNECTED_OPTION, false ) && empty( get_connection() );

		if ( $disconnected ) {
			$heading = \__( 'ATmosphere: disconnected', 'atmosphere' );
			/* translators: %s: URL to the ATmosphere settings page. */
			$message = \__( 'ATmosphere is disconnected from AT Protocol. New posts and comments will not publish until you <a href="%s">reconnect on the settings page</a>. Your publishing preferences and verification headers stay in place in the meantime.', 'atmosphere' );
		} else {
			$heading = \__( 'ATmosphere: reconnection required', 'atmosphere' );
			/* translators: %s: URL to the ATmosphere settings page. */
			$message = \__( 'Your AT Protocol session has expired. New posts and comments will not publish until you <a href="%s">reconnect on the settings page</a>. Your publishing preferences and verification headers stay in place in the meantime.', 'atmosphere' );
		}

		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php echo \esc_html( $heading ); ?></strong>
			</p>
			<p>
				<?php
				echo \wp_kses(
					\sprintf( $message, \esc_url( $settings_url ) ),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Warn when auto-publish is on but no post type will ever publish.
	 *
	 * Auto-publish defaults on and the Post types list is easy to miss, so
	 * a user can end up "publishing" with everything unticked and nothing
	 * eligible to send (issue #173). Registered as a settings error so it
	 * surfaces at the top of the page via `options-head.php`.
	 *
	 * Gated on `has_identity()` to match the publishing section's own
	 * visibility, and on the effective `get_supported_post_types()` list so
	 * a native `add_post_type_support()` opt-in does not trip a false alarm.
	 */
	public static function maybe_warn_missing_post_types(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! has_identity() ) {
			return;
		}

		if ( '1' !== \get_option( 'atmosphere_auto_publish', '1' ) ) {
			return;
		}

		if ( ! empty( get_supported_post_types() ) ) {
			return;
		}

		\add_settings_error(
			'atmosphere',
			'no_post_types',
			\__( 'Auto-publish is on, but no post types are selected — nothing will be published. Select one or more post types under “Post types” below to start publishing.', 'atmosphere' ),
			'warning'
		);
	}
}
