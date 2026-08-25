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
use Atmosphere\Transformer\Publication;
use function Atmosphere\get_identity;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\handle_typeahead_url;
use function Atmosphere\has_identity;
use function Atmosphere\is_auto_publish_enabled;
use function Atmosphere\is_connected;
use function Atmosphere\is_comment_publishing_enabled;
use function Atmosphere\is_connection_only_mode;
use function Atmosphere\is_operator_disconnected;
use function Atmosphere\is_publication_sync_enabled;
use function Atmosphere\needs_reauth;
use function Atmosphere\reauth_lead_for_current_user;
use function Atmosphere\reconnect_url;
use function Atmosphere\settings_url;

/**
 * Admin class.
 */
class Admin {

	/**
	 * Transient holding the one-time notice the OAuth callback stashes for the
	 * post-redirect screen to display.
	 *
	 * @var string
	 */
	private const OAUTH_NOTICE_TRANSIENT = 'atmosphere_oauth_notice';

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
		\add_action( 'admin_notices', array( self::class, 'maybe_render_oauth_notice' ) );
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
	 * Hidden while {@see \Atmosphere\is_connection_only_mode()} is on: a host that
	 * embeds ATmosphere purely as a connection layer — driving the AT Protocol
	 * connection through the Settings → Connectors screen and its own UI — has no
	 * use for Settings → ATmosphere. The connection layer, publishing, and the
	 * Connectors card all keep working; only this plugin's own settings surface is
	 * hidden.
	 *
	 * @return bool True to show the settings screen (default), false to hide it.
	 */
	public static function is_settings_page_visible(): bool {
		return ! is_connection_only_mode();
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
	 * When the settings screen is hidden by connection-only mode, the shortcut is
	 * replaced with a plain, non-linked label so operators understand why the usual
	 * Settings link is gone. The filter cannot report which plugin hid the screen,
	 * so the copy is intentionally generic.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[] Filtered plugin action links.
	 */
	public static function filter_action_links( array $links ): array {
		if ( self::is_settings_page_visible() ) {
			$settings_link = \sprintf(
				'<a href="%s">%s</a>',
				\esc_url( settings_url() ),
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

		/*
		 * While hidden the page renders only a short notice (see render_page()),
		 * so none of the settings assets below are needed.
		 */
		if ( ! self::is_settings_page_visible() ) {
			return;
		}

		\wp_enqueue_style(
			'atmosphere-admin',
			ATMOSPHERE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ATMOSPHERE_VERSION
		);

		\wp_enqueue_style( 'wp-color-picker' );
		\wp_enqueue_script( 'wp-color-picker' );

		/*
		 * Offer the active theme's own palette as swatches: those are the
		 * colours the publication record would otherwise derive, so the
		 * common case ("same as my theme, but pinned") is one click.
		 */
		$swatches = array();

		foreach ( Publication::get_palette_lookup() as $hex ) {
			$hex = \sanitize_hex_color( (string) $hex );

			if ( $hex && ! \in_array( $hex, $swatches, true ) ) {
				$swatches[] = $hex;
			}
		}

		\wp_add_inline_script(
			'wp-color-picker',
			\sprintf(
				'jQuery( function ( $ ) { $( ".atmosphere-color-input" ).wpColorPicker( %s ); } );',
				\wp_json_encode( array( 'palettes' => \array_slice( $swatches, 0, 8 ) ) )
			)
		);

		/*
		 * The connect handle field — and so the typeahead that enhances it —
		 * only renders while disconnected.
		 */
		if ( is_connected() ) {
			return;
		}

		$asset_file = ATMOSPHERE_PLUGIN_DIR . 'build/settings-connect/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		/*
		 * No wp-components dependency: the typeahead's loading indicator uses
		 * core's own `.spinner` (free on every wp-admin page), so the classic
		 * settings page never pulls the wp-components bundle just for a spinner.
		 */
		\wp_enqueue_style(
			'atmosphere-handle-typeahead',
			ATMOSPHERE_PLUGIN_URL . 'assets/css/handle-typeahead.css',
			array(),
			ATMOSPHERE_VERSION
		);

		\wp_enqueue_script(
			'atmosphere-settings-connect',
			ATMOSPHERE_PLUGIN_URL . 'build/settings-connect/index.js',
			\array_merge(
				$asset['dependencies'] ?? array(),
				array( 'wp-element', 'wp-i18n' )
			),
			$asset['version'] ?? ATMOSPHERE_VERSION,
			true
		);

		/*
		 * Load the JS translations for this script's `__()` strings — a classic
		 * admin page doesn't set them up on its own. (The Connectors card is a
		 * script module; core's script-module i18n story is still limited, so
		 * its strings stay a known untranslated gap for now.)
		 */
		\wp_set_script_translations( 'atmosphere-settings-connect', 'atmosphere' );

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

		/*
		 * Read where the flow started before handle_callback() consumes the
		 * resolved record it lives in. Captured up front so the browser returns
		 * to the origin screen on success *or* failure, rather than stranding a
		 * failed connect on a hidden settings page (see render_page()).
		 */
		$origin = Client::pending_origin();

		$result = Client::handle_callback(
			\sanitize_text_field( $code ),
			\sanitize_text_field( $state )
		);

		$destination = self::oauth_return_destination( $origin );

		if ( \is_wp_error( $result ) ) {
			self::store_oauth_notice( 'error', $result->get_error_message() );

			\wp_safe_redirect( \add_query_arg( 'atmosphere_error', '1', $destination ) );
			exit;
		}

		// Auto-create publication on first connect — unless the host runs
		// ATmosphere purely as a connection layer and doesn't want a public
		// site.standard.publication record written to the connected repo.
		if ( is_publication_sync_enabled() ) {
			Publisher::sync_publication();
		}

		self::store_oauth_notice(
			'success',
			\__( 'Successfully connected to AT Protocol.', 'atmosphere' )
		);

		\wp_safe_redirect( \add_query_arg( 'connected', '1', $destination ) );
		exit;
	}

	/**
	 * Stash a one-time notice for the post-OAuth-callback redirect to display.
	 *
	 * The callback redirects the browser to the origin screen (the settings page
	 * or the Connectors screen), so the outcome can't be printed inline. Core's
	 * `settings_errors` transient is unreliable for this: it is only merged back
	 * when `settings-updated` is set, the Connectors screen never calls
	 * `settings_errors()` at all, and reusing it risks the stashed message
	 * surfacing on an unrelated `options.php` save.
	 *
	 * Keyed per user so a second admin (or a second tab) whose `admin_notices`
	 * fires first can't consume the message meant for the admin who initiated the
	 * connect. Consumed by {@see self::maybe_render_oauth_notice()} on the
	 * settings page, or by the Connectors card via {@see self::consume_oauth_notice()}
	 * (the Connectors screen is a React app where classic `admin_notices` output
	 * isn't reliably visible, so the card renders it from its hydration payload).
	 *
	 * @param string $type    Notice type: `error` or `success`.
	 * @param string $message Human-readable notice text.
	 */
	private static function store_oauth_notice( string $type, string $message ): void {
		\set_transient(
			self::oauth_notice_transient_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Per-user transient key for the stored OAuth-callback notice.
	 *
	 * @return string
	 */
	private static function oauth_notice_transient_key(): string {
		return self::OAUTH_NOTICE_TRANSIENT . '_' . \get_current_user_id();
	}

	/**
	 * Read and delete the current user's stored OAuth-callback notice, if any.
	 *
	 * Shared by the settings-page `admin_notices` renderer and the Connectors
	 * card's hydration payload so exactly one of them surfaces the outcome.
	 *
	 * @return array{type: string, message: string}|null The notice, or null.
	 */
	public static function consume_oauth_notice(): ?array {
		$notice = \get_transient( self::oauth_notice_transient_key() );
		if ( ! \is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}

		\delete_transient( self::oauth_notice_transient_key() );

		return array(
			'type'    => 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error',
			'message' => (string) $notice['message'],
		);
	}

	/**
	 * Print the OAuth-callback outcome as an admin notice, once.
	 *
	 * Runs on `admin_notices` for the settings page. Skips the Connectors screen,
	 * whose React UI renders the same notice from the card's hydration payload
	 * (see {@see \Atmosphere\Connectors::get_connector_data()}); letting both fire
	 * would double-render, or the classic notice would silently consume the
	 * message the card should have shown.
	 */
	public static function maybe_render_oauth_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;
		if ( $screen instanceof \WP_Screen && Connectors::is_connectors_screen( $screen->id ) ) {
			return;
		}

		$notice = self::consume_oauth_notice();
		if ( null === $notice ) {
			return;
		}

		\printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			\esc_attr( 'success' === $notice['type'] ? 'notice-success' : 'notice-error' ),
			\esc_html( $notice['message'] )
		);
	}

	/**
	 * Resolve where the OAuth callback should return the browser for a flow.
	 *
	 * A Connectors-card connect starts with origin `connectors` (see
	 * {@see \Atmosphere\OAuth\Client::authorize()}); honor it by returning to the
	 * Connectors screen, otherwise fall back to the settings page. The Connectors
	 * screen has two possible URLs (core's `options-connectors.php` and the
	 * Gutenberg plugin's Settings submenu), so the concrete URL is resolved
	 * server-side from the registered admin menu via {@see Connectors::screen_url()}
	 * — the origin is a fixed marker and the URL is never taken from request input,
	 * so nothing off the wire can steer the redirect.
	 *
	 * @param string $origin The flow's origin, from {@see Client::pending_origin()}.
	 * @return string The admin URL to redirect to.
	 */
	private static function oauth_return_destination( string $origin ): string {
		return 'connectors' === $origin
			? Connectors::screen_url()
			: settings_url();
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
	 * Surfaced on every admin screen, because the publish, comment, and
	 * update paths silently no-op until the user reconnects — without a
	 * visible nudge, an expired refresh token can sit unnoticed for days,
	 * and the person hitting Publish is often not the one who can
	 * reconnect. That argument reaches as far as `edit_posts` and no
	 * further: it covers authors, editors and contributors, and leaves out
	 * subscribers, who on a membership or WooCommerce site are most of the
	 * logged-in users and have nothing to do with sharing. Within that
	 * audience there is no further gate; the action sentence is swapped
	 * instead, so a reader who cannot reconnect is told who can rather
	 * than handed a link they cannot follow. The notice is dismissible per
	 * page-load only, so the user is reminded again on their next visit.
	 *
	 * Swaps copy when the disconnect was operator-initiated (the user
	 * clicked Disconnect) so the message does not falsely claim "your
	 * session has expired" for a state the user just chose.
	 */
	public static function maybe_render_reauth_notice(): void {
		if ( ! needs_reauth() ) {
			return;
		}

		if ( ! \current_user_can( 'edit_posts' ) ) {
			return;
		}

		$can_manage = \current_user_can( 'manage_options' );

		/*
		 * Resolve where the reconnect link points via the shared helper: the
		 * settings page normally hosts it, but in connection-only mode that
		 * page is hidden, so it falls back to the Connectors screen, whose
		 * card can also reconnect, when one exists (WP 7.0+). An empty
		 * result only costs the link: the notice itself still renders, so a
		 * dead session is never left without a site-wide signal.
		 */
		$reconnect_url = $can_manage ? reconnect_url() : '';

		$heading = \__( 'ATmosphere: reconnection required', 'atmosphere' );

		/*
		 * The cause lead comes from `reauth_lead_for_current_user()`
		 * (shared with the document panel and the pre-publish panel); only
		 * the notice's own tail (what stops working + the reconnect link)
		 * is composed here. The helper's non-admin and
		 * operator-disconnect-suppression arms do fire here, since this
		 * notice has no capability gate, which is why an empty lead falls
		 * back below. The heading still needs its own swap, as the
		 * helper's lead text doesn't distinguish which heading to show. The disconnect gate's stale-marker rationale
		 * lives in `is_operator_disconnected()`.
		 */
		if ( is_operator_disconnected() ) {
			$heading = \__( 'ATmosphere: disconnected', 'atmosphere' );
		}

		$lead = reauth_lead_for_current_user();

		/*
		 * The helper suppresses the cause for a reader without
		 * `manage_options` on an operator-initiated disconnect. This notice
		 * still renders for them, so fall back to the same generic sentence
		 * the helper gives every other non-admin rather than opening with
		 * the consequence and no subject.
		 */
		if ( '' === $lead ) {
			$lead = \__( 'Your site’s Bluesky connection needs attention.', 'atmosphere' );
		}

		/*
		 * What actually stops working depends on what this site uses the
		 * connection for. Naming posts and comments is wrong when both
		 * outgoing lanes are already off, which is exactly connection-only
		 * mode: there the host plugin's features are what break.
		 */
		if ( is_auto_publish_enabled() || is_comment_publishing_enabled() ) {
			$consequence = \__( 'New posts and comments will not publish until the connection is restored.', 'atmosphere' );
		} else {
			$consequence = \__( 'Anything on this site that uses your Bluesky connection will stop working until it is restored.', 'atmosphere' );
		}

		/*
		 * Only the action sentence goes through sprintf: a lead or
		 * consequence translation containing a stray `%` must not be able to
		 * corrupt the placeholder substitution (PHP 8 throws on missing
		 * arguments).
		 */
		if ( ! $can_manage ) {
			$action = \__( 'Ask an administrator to reconnect it.', 'atmosphere' );
		} elseif ( '' === $reconnect_url ) {
			$action = \__( 'Reconnect your Bluesky account to fix this.', 'atmosphere' );
		} else {
			$action = \sprintf(
				/* translators: %s: URL to reconnect the Bluesky account. */
				\__( '<a href="%s">Reconnect your account</a> to fix this.', 'atmosphere' ),
				\esc_url( $reconnect_url )
			);
		}

		$message = $lead . ' ' . $consequence . ' ' . $action . ' ' . \__( 'Your publishing preferences and verification headers stay in place in the meantime.', 'atmosphere' );

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
