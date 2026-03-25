<?php
/**
 * Admin settings page, meta box, and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Backfill;
use Atmosphere\OAuth\Client;
use Atmosphere\Publisher;
use function Atmosphere\get_connection;
use function Atmosphere\is_connected;

/**
 * Admin class.
 */
class Admin {

	/**
	 * Boot admin hooks.
	 */
	public static function register(): void {
		\add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		\add_action( 'admin_init', array( self::class, 'handle_oauth_callback' ) );
		\add_action( 'admin_init', array( self::class, 'register_settings' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		\add_action( 'admin_post_atmosphere_disconnect', array( self::class, 'handle_disconnect' ) );

		// Meta box on syncable post types.
		\add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );

		// REST route for client metadata.
		\add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	/**
	 * Register the settings page under Settings.
	 */
	public static function add_menu(): void {
		\add_options_page(
			\__( 'ATmosphere', 'atmosphere' ),
			\__( 'ATmosphere', 'atmosphere' ),
			'manage_options',
			'atmosphere',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Register plugin settings, sections, and fields.
	 */
	public static function register_settings(): void {
		\register_setting(
			'atmosphere',
			'atmosphere_auto_publish',
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		\register_setting(
			'atmosphere',
			'atmosphere_handle',
			array(
				'type'              => 'string',
				'show_in_rest'      => false,
				'sanitize_callback' => array( self::class, 'sanitize_handle' ),
			)
		);

		// Connection section.
		\add_settings_section(
			'atmosphere_connection',
			\__( 'Connection', 'atmosphere' ),
			is_connected()
				? array( self::class, 'render_connected_section' )
				: array( self::class, 'render_connect_section' ),
			'atmosphere'
		);

		if ( ! is_connected() ) {
			\add_settings_field(
				'atmosphere_handle',
				\__( 'Handle', 'atmosphere' ),
				array( self::class, 'render_handle_field' ),
				'atmosphere',
				'atmosphere_connection'
			);

			return;
		}

		// Publishing section.
		\add_settings_section(
			'atmosphere_publishing',
			\__( 'Publishing', 'atmosphere' ),
			array( self::class, 'render_publishing_section' ),
			'atmosphere'
		);

		\add_settings_field(
			'atmosphere_auto_publish',
			\__( 'Auto-publish', 'atmosphere' ),
			array( self::class, 'render_auto_publish_field' ),
			'atmosphere',
			'atmosphere_publishing'
		);

		\add_settings_field(
			'atmosphere_backfill',
			\__( 'Backfill', 'atmosphere' ),
			array( self::class, 'render_backfill_field' ),
			'atmosphere',
			'atmosphere_publishing'
		);
	}

	/**
	 * Render the connected state: connection details and disconnect link.
	 */
	public static function render_connected_section(): void {
		$connection     = get_connection();
		$disconnect_url = \wp_nonce_url(
			\admin_url( 'admin-post.php?action=atmosphere_disconnect' ),
			'atmosphere_disconnect',
			'atmosphere_nonce'
		);

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php \esc_html_e( 'Handle', 'atmosphere' ); ?></th>
				<td>
					<strong><?php echo \esc_html( $connection['handle'] ?? '' ); ?></strong>
					<p class="description"><?php \esc_html_e( 'Your public AT Protocol identity, similar to a username.', 'atmosphere' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php \esc_html_e( 'DID', 'atmosphere' ); ?></th>
				<td>
					<code><?php echo \esc_html( $connection['did'] ?? '' ); ?></code>
					<p class="description"><?php \esc_html_e( 'Your Decentralized Identifier — a permanent, portable ID that stays the same even if you change your handle.', 'atmosphere' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php \esc_html_e( 'PDS', 'atmosphere' ); ?></th>
				<td>
					<code><?php echo \esc_html( $connection['pds_endpoint'] ?? '' ); ?></code>
					<p class="description"><?php \esc_html_e( 'Your Personal Data Server — where your AT Protocol records are stored and served from.', 'atmosphere' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<a href="<?php echo \esc_url( $disconnect_url ); ?>" class="button button-link-delete">
						<?php \esc_html_e( 'Disconnect', 'atmosphere' ); ?>
					</a>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the connect section description.
	 */
	public static function render_connect_section(): void {
		echo '<p>' . \esc_html__( 'Connect your site to the AT Protocol network using your handle. This authorizes ATmosphere to publish content to your Personal Data Server (PDS).', 'atmosphere' ) . '</p>';
	}

	/**
	 * Render the handle input field.
	 */
	public static function render_handle_field(): void {
		?>
		<input
			type="text"
			name="atmosphere_handle"
			id="atmosphere_handle"
			class="regular-text"
			placeholder="alice.bsky.social"
		>
		<p class="description"><?php \esc_html_e( 'Your AT Protocol handle, e.g. alice.bsky.social or your own domain.', 'atmosphere' ); ?></p>
		<?php
	}

	/**
	 * Sanitize the handle field and trigger OAuth if a value is submitted.
	 *
	 * @param string $value The submitted handle.
	 * @return string Empty string (never stored).
	 */
	public static function sanitize_handle( $value ): string {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return '';
		}

		$handle = \sanitize_text_field( $value );

		if ( empty( $handle ) ) {
			return '';
		}

		$auth_url = Client::authorize( $handle );

		if ( \is_wp_error( $auth_url ) ) {
			\add_settings_error( 'atmosphere', 'auth_failed', $auth_url->get_error_message() );
			return '';
		}

		\wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Render the Publishing section description.
	 */
	public static function render_publishing_section(): void {
		echo '<p>' . \esc_html__( 'Control how and when your WordPress content is published to the AT Protocol network.', 'atmosphere' ) . '</p>';
	}

	/**
	 * Render the Auto-publish settings field.
	 */
	public static function render_auto_publish_field(): void {
		?>
		<label>
			<input
				type="checkbox"
				name="atmosphere_auto_publish"
				value="1"
				<?php \checked( \get_option( 'atmosphere_auto_publish', '1' ), '1' ); ?>
			>
			<?php \esc_html_e( 'Automatically publish new posts to AT Protocol', 'atmosphere' ); ?>
		</label>
		<p class="description"><?php \esc_html_e( 'When enabled, posts are sent to your PDS as soon as they are published in WordPress.', 'atmosphere' ); ?></p>
		<?php
	}

	/**
	 * Render the Backfill field.
	 */
	public static function render_backfill_field(): void {
		$limit = (int) \apply_filters( 'atmosphere_backfill_limit', 10 );

		?>
		<div id="atmosphere-backfill">
			<button type="button" class="button" id="atmosphere-backfill-start">
				<?php \esc_html_e( 'Start Backfill', 'atmosphere' ); ?>
			</button>
			<div id="atmosphere-backfill-progress" style="display:none; margin-top: 10px;">
				<progress id="atmosphere-backfill-bar" value="0" max="100" style="width: 100%;"></progress>
				<p id="atmosphere-backfill-status"></p>
			</div>
		</div>
		<p class="description">
			<?php
			\printf(
				/* translators: %d: maximum number of posts to backfill */
				\esc_html__( 'Sync the last %d published posts that haven\'t been sent to AT Protocol yet. You can run this multiple times to gradually sync older content.', 'atmosphere' ),
				$limit
			);
			?>
		</p>
		<?php
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

		\wp_enqueue_script(
			'atmosphere-settings',
			ATMOSPHERE_PLUGIN_URL . 'assets/js/settings.js',
			array(),
			ATMOSPHERE_VERSION,
			true
		);

		\wp_localize_script(
			'atmosphere-settings',
			'atmosphere',
			array(
				'ajax_url'       => \admin_url( 'admin-ajax.php' ),
				'backfill_nonce' => \wp_create_nonce( 'atmosphere_backfill' ),
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

		\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere&connected=1' ) );
		exit;
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
	 * Add the ATmosphere meta box to syncable post types.
	 */
	public static function add_meta_box(): void {
		if ( ! is_connected() ) {
			return;
		}

		foreach ( Backfill::syncable_post_types() as $post_type ) {
			\add_meta_box(
				'atmosphere',
				\__( 'ATmosphere', 'atmosphere' ),
				array( self::class, 'render_meta_box' ),
				$post_type,
				'side'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		\load_template(
			ATMOSPHERE_PLUGIN_DIR . 'templates/meta-box.php',
			false,
			array( 'post' => $post )
		);
	}

	/**
	 * Register the client-metadata REST endpoint.
	 */
	public static function register_rest_routes(): void {
		\register_rest_route(
			'atmosphere/v1',
			'/client-metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'serve_client_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Serve the OAuth client metadata JSON.
	 *
	 * This endpoint URL IS the client_id per AT Protocol OAuth spec.
	 *
	 * @return \WP_REST_Response
	 */
	public static function serve_client_metadata(): \WP_REST_Response {
		$metadata = array(
			'client_id'                  => Client::client_id(),
			'client_name'                => \get_bloginfo( 'name' ) . ' (ATmosphere)',
			'client_uri'                 => \home_url( '/' ),
			'redirect_uris'              => array( Client::redirect_uri() ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'scope'                      => 'atproto transition:generic',
			'dpop_bound_access_tokens'   => true,
			'application_type'           => 'web',
		);

		/**
		 * Filters the OAuth client metadata served at the REST endpoint.
		 *
		 * @param array $metadata Client metadata.
		 */
		$metadata = \apply_filters( 'atmosphere_client_metadata', $metadata );

		return new \WP_REST_Response( $metadata, 200 );
	}
}
