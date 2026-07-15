<?php
/**
 * Admin settings page and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Atmosphere;
use Atmosphere\Connectors;
use Atmosphere\Handle;
use Atmosphere\OAuth\Client;
use Atmosphere\Publisher;
use function Atmosphere\get_identity;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\handle_typeahead_url;
use function Atmosphere\has_identity;
use function Atmosphere\is_auto_publish_enabled;
use function Atmosphere\is_connected;
use function Atmosphere\is_connection_only_mode;
use function Atmosphere\is_operator_disconnected;
use function Atmosphere\needs_reauth;
use function Atmosphere\reauth_reason_lead;
use function Atmosphere\settings_url;

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

		\add_filter(
			'plugin_action_links_' . \plugin_basename( ATMOSPHERE_PLUGIN_FILE ),
			array( self::class, 'filter_action_links' )
		);
	}

	/**
	 * Whether the plugin's own settings screen should be shown.
	 *
	 * A third-party plugin that drives the AT Protocol connection through the
	 * Settings → Connectors screen (and its own UI) can return false from the
	 * `atmosphere_show_settings_page` filter to hide Settings → ATmosphere.
	 * The connection layer, publishing, and the Connectors card all keep
	 * working; only this plugin's own settings surface is hidden.
	 *
	 * @return bool True to show the settings screen (default), false to hide it.
	 */
	public static function is_settings_page_visible(): bool {
		/**
		 * Filters whether the ATmosphere settings screen is shown.
		 *
		 * Return false to hide Settings → ATmosphere, for example when another
		 * plugin embeds ATmosphere as a connection layer and manages the
		 * connection from the Settings → Connectors screen and its own UI.
		 *
		 * Defaults to hidden while {@see \Atmosphere\is_connection_only_mode()}
		 * is on, so a host that flips connection-only mode gets the settings
		 * screen tucked away without a second filter. This filter is evaluated
		 * afterwards and still wins, so the screen can be forced back on.
		 *
		 * @since unreleased
		 *
		 * @param bool $visible Whether to show Settings → ATmosphere. Default true, or false in connection-only mode.
		 */
		return (bool) \apply_filters( 'atmosphere_show_settings_page', ! is_connection_only_mode() );
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
		 * When a third-party plugin hides the settings screen, drop it from the
		 * Settings menu but keep the page itself registered. The OAuth callback
		 * lands on this page's own URL (`options-general.php?page=atmosphere`);
		 * leaving the page registered keeps that URL accessible so `admin_init`
		 * can run `handle_oauth_callback()` and redirect on to the Connectors
		 * screen. An unregistered page would trip core's
		 * `user_can_access_admin_page()` gate, which `wp_die`s before
		 * `admin_init` ever fires — stranding the browser on the callback URL.
		 * `render_page()` shows a short "managed elsewhere" notice for anyone
		 * who reaches the hidden page directly.
		 */
		if ( ! self::is_settings_page_visible() ) {
			\remove_submenu_page( 'options-general.php', 'atmosphere' );
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
	 * Add a Settings shortcut to the plugin's row on the Plugins screen.
	 *
	 * When the settings screen is hidden via the `atmosphere_show_settings_page`
	 * filter, the shortcut is replaced with a plain, non-linked label so
	 * operators understand why the usual Settings link is gone. Filters cannot
	 * report which plugin hid the screen, so the copy is intentionally generic.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[] Filtered plugin action links.
	 */
	public static function filter_action_links( array $links ): array {
		if ( self::is_settings_page_visible() ) {
			$settings_link = \sprintf(
				'<a href="%s">%s</a>',
				\esc_url( \admin_url( 'options-general.php?page=atmosphere' ) ),
				\esc_html__( 'Settings', 'atmosphere' )
			);
		} else {
			$settings_link = \sprintf(
				'<span style="color:#646970;">%s</span>',
				\esc_html__( 'Settings hidden by another plugin', 'atmosphere' )
			);
		}

		\array_unshift( $links, $settings_link );

		return $links;
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

		// While hidden the page renders only a short notice (see render_page()),
		// so none of the settings assets below are needed.
		if ( ! self::is_settings_page_visible() ) {
			return;
		}

		\wp_enqueue_style(
			'atmosphere-admin',
			ATMOSPHERE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ATMOSPHERE_VERSION
		);

		// The connect handle field — and so the typeahead that enhances it —
		// only renders while disconnected.
		if ( is_connected() ) {
			return;
		}

		$asset_file = ATMOSPHERE_PLUGIN_DIR . 'build/settings-connect/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		// Depend on the wp-components stylesheet: the typeahead's loading Spinner
		// is styled by its `.components-spinner` rules, which a classic admin
		// page does not load by default. wp-components is a core-registered
		// handle, so this pulls it in wherever the stylesheet loads.
		\wp_enqueue_style(
			'atmosphere-handle-typeahead',
			ATMOSPHERE_PLUGIN_URL . 'assets/css/handle-typeahead.css',
			array( 'wp-components' ),
			ATMOSPHERE_VERSION
		);

		\wp_enqueue_script(
			'atmosphere-settings-connect',
			ATMOSPHERE_PLUGIN_URL . 'build/settings-connect/index.js',
			\array_merge(
				$asset['dependencies'] ?? array(),
				array( 'wp-element', 'wp-components', 'wp-i18n' )
			),
			$asset['version'] ?? ATMOSPHERE_VERSION,
			true
		);

		\wp_localize_script(
			'atmosphere-settings-connect',
			'atmosphereSettingsConnect',
			array(
				'typeaheadUrl' => handle_typeahead_url(),
				'handle'       => get_identity()['handle'] ?? '',
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * The page stays registered while hidden so the OAuth callback URL
		 * resolves (see add_menu()). A direct visit that is not an OAuth
		 * callback reaches here after headers are sent — too late to redirect —
		 * so show a short explanation instead of the settings form.
		 */
		if ( ! self::is_settings_page_visible() ) {
			self::render_hidden_notice();
			return;
		}

		include ATMOSPHERE_PLUGIN_DIR . 'templates/settings-page.php';
	}

	/**
	 * Render the placeholder shown when the settings screen is hidden.
	 *
	 * The screen is hidden from the menu but kept registered so the OAuth
	 * callback URL still resolves; anyone who lands on it directly sees this
	 * instead of the settings form.
	 */
	private static function render_hidden_notice(): void {
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'ATmosphere', 'atmosphere' ); ?></h1>
			<p>
				<?php
				\esc_html_e(
					'The ATmosphere settings are currently managed by another plugin on this site.',
					'atmosphere'
				);
				?>
			</p>
		</div>
		<?php
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

		/*
		 * Return the browser to wherever the flow started. A Connectors-card
		 * connect sets the `atmosphere_oauth_from_connectors` flag (see
		 * {@see \Atmosphere\Rest\Admin\Connection_Controller::authorize()}); honor
		 * it by returning to the Connectors screen, otherwise fall back to the
		 * settings page. The destination is hardcoded here — the flag is a
		 * boolean, so nothing off the wire can steer the redirect. Always consume
		 * the flag so a stale one can't leak into a later, unrelated connect.
		 */
		$from_connectors = (bool) \get_transient( 'atmosphere_oauth_from_connectors' );
		\delete_transient( 'atmosphere_oauth_from_connectors' );

		$destination = $from_connectors
			? \admin_url( Connectors::SCREEN )
			: settings_url();

		\wp_safe_redirect( \add_query_arg( 'connected', '1', $destination ) );
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

		\wp_safe_redirect( settings_url() );
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

		// The notice's only call to action links to the settings page; skip it
		// entirely when that page is hidden so we never point at a dead URL.
		if ( ! self::is_settings_page_visible() ) {
			return;
		}

		$heading = \__( 'ATmosphere: reconnection required', 'atmosphere' );

		/*
		 * The cause lead comes from `reauth_reason_lead()` (shared with
		 * the Site Health test); only the shared tail (what stops working
		 * + the reconnect link) is composed here. The disconnect gate's
		 * stale-marker rationale lives in `is_operator_disconnected()`.
		 */
		if ( is_operator_disconnected() ) {
			$heading = \__( 'ATmosphere: disconnected', 'atmosphere' );
			$lead    = \__( 'ATmosphere is disconnected from Bluesky.', 'atmosphere' );
		} else {
			$lead = reauth_reason_lead();
		}

		/* translators: %s: URL to the ATmosphere settings page. */
		$tail = \__( 'New posts and comments will not publish until you <a href="%s">reconnect on the settings page</a>. Your publishing preferences and verification headers stay in place in the meantime.', 'atmosphere' );

		/*
		 * Only the tail goes through sprintf: a lead translation
		 * containing a stray `%` must not be able to corrupt the
		 * placeholder substitution (PHP 8 throws on missing arguments).
		 */
		$message = $lead . ' ' . \sprintf( $tail, \esc_url( settings_url() ) );

		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php echo \esc_html( $heading ); ?></strong>
			</p>
			<p>
				<?php
				echo \wp_kses(
					$message,
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
	 * eligible to send. Registered as a settings error so it surfaces at
	 * the top of the page via `options-head.php`.
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

		if ( ! is_auto_publish_enabled() ) {
			return;
		}

		if ( ! empty( get_supported_post_types() ) ) {
			return;
		}

		\add_settings_error(
			'atmosphere',
			'no_post_types',
			\__( 'Auto-publish is on, but no post types are selected, so nothing will be published. Select one or more post types under “Post types” below to start publishing.', 'atmosphere' ),
			'warning'
		);
	}
}
