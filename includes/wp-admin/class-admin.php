<?php
/**
 * Admin settings page, meta box, and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Atmosphere;
use Atmosphere\OAuth\Client;
use Atmosphere\Post_Types;
use Atmosphere\Publisher;
use function Atmosphere\get_connection;
use function Atmosphere\get_supported_post_types;
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

		/*
		 * REST route for client metadata is registered globally from
		 * Atmosphere::init() — rest_api_init does not fire on admin
		 * requests, so wiring it up here is redundant.
		 */
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
			'atmosphere_long_form_composition',
			array(
				'type'              => 'string',
				'description'       => 'Composition strategy for long-form Bluesky posts.',
				'default'           => 'link-card',
				'sanitize_callback' => array( self::class, 'sanitize_long_form_composition' ),
				'show_in_rest'      => array(
					'schema' => array(
						'enum' => Atmosphere::LONG_FORM_STRATEGIES,
					),
				),
			)
		);

		\register_setting(
			'atmosphere',
			'atmosphere_support_post_types',
			array(
				'type'              => 'array',
				'description'       => 'Post types to publish to AT Protocol.',
				'default'           => array( 'post' ),
				'sanitize_callback' => array( Post_Types::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
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
			'atmosphere_long_form_composition',
			\__( 'Long-form posts', 'atmosphere' ),
			array( self::class, 'render_long_form_composition_field' ),
			'atmosphere',
			'atmosphere_publishing'
		);

		\add_settings_field(
			'atmosphere_support_post_types',
			\__( 'Post types', 'atmosphere' ),
			array( self::class, 'render_support_post_types_field' ),
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
					<a href="<?php echo \esc_url( $disconnect_url ); ?>" class="button">
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
	 * Render the long-form composition radio group.
	 */
	public static function render_long_form_composition_field(): void {
		$current = \get_option( 'atmosphere_long_form_composition', 'link-card' );

		?>
		<fieldset>
			<legend class="screen-reader-text">
				<?php \esc_html_e( 'Long-form composition', 'atmosphere' ); ?>
			</legend>
			<?php
			foreach ( Atmosphere::LONG_FORM_STRATEGIES as $strategy ) :
				$choice = self::long_form_composition_choice( $strategy );
				?>
				<p>
					<label>
						<input
							type="radio"
							name="atmosphere_long_form_composition"
							value="<?php echo \esc_attr( $strategy ); ?>"
							<?php \checked( $current, $strategy ); ?>
						>
						<strong><?php echo \esc_html( $choice['label'] ); ?></strong>
					</label>
					<br>
					<span class="description"><?php echo \esc_html( $choice['help'] ); ?></span>
				</p>
			<?php endforeach; ?>
		</fieldset>
		<p class="description">
			<?php \esc_html_e( 'How posts longer than the Bluesky 300-character limit are published. Plugins can override this per post via the atmosphere_long_form_composition filter.', 'atmosphere' ); ?>
		</p>
		<?php
	}

	/**
	 * Return the translatable label/help for a long-form strategy.
	 *
	 * @param string $strategy Strategy slug from `Atmosphere::LONG_FORM_STRATEGIES`.
	 * @return array{label: string, help: string}
	 */
	private static function long_form_composition_choice( string $strategy ): array {
		switch ( $strategy ) {
			case 'truncate-link':
				return array(
					'label' => \__( 'Truncated post with link', 'atmosphere' ),
					'help'  => \__( 'A single Bluesky post containing the body text followed by an inline permalink. No card.', 'atmosphere' ),
				);
			case 'teaser-thread':
				return array(
					'label' => \__( 'Teaser thread', 'atmosphere' ),
					'help'  => \__( 'A short Bluesky thread: a hook, an optional body chunk for longer posts, and a "continue reading" reply with a link card back to the WordPress post.', 'atmosphere' ),
				);
			case 'link-card':
			default:
				return array(
					'label' => \__( 'Link card', 'atmosphere' ),
					'help'  => \__( 'A single Bluesky post with the title, an excerpt, and a permalink card. (Default — unchanged behavior.)', 'atmosphere' ),
				);
		}
	}

	/**
	 * Sanitize the long-form composition setting.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_long_form_composition( $value ): string {
		$value = \is_string( $value ) ? \sanitize_text_field( $value ) : '';

		return \in_array( $value, Atmosphere::LONG_FORM_STRATEGIES, true ) ? $value : 'link-card';
	}

	/**
	 * Render the post type support checkboxes.
	 */
	public static function render_support_post_types_field(): void {
		$post_types = \get_post_types( array( 'public' => true ), 'objects' );

		/*
		 * The checkbox state reflects the saved option only. Native
		 * `add_post_type_support()` opt-ins and the syncable filter are
		 * surfaced as a note below the label so the user can see when a
		 * post type is enabled outside this UI.
		 */
		$saved     = (array) \get_option( 'atmosphere_support_post_types', array( 'post' ) );
		$saved     = \array_filter( \array_map( 'sanitize_key', $saved ) );
		$effective = get_supported_post_types();
		?>
		<fieldset>
			<legend class="screen-reader-text">
				<?php \esc_html_e( 'Post types', 'atmosphere' ); ?>
			</legend>
			<?php
			foreach ( $post_types as $post_type ) :
				$is_saved        = \in_array( $post_type->name, $saved, true );
				$is_effective    = \in_array( $post_type->name, $effective, true );
				$is_external     = ! $is_saved && $is_effective;
				$is_filtered_out = $is_saved && ! $is_effective;
				?>
				<p>
					<label>
						<input
							type="checkbox"
							name="atmosphere_support_post_types[]"
							value="<?php echo \esc_attr( $post_type->name ); ?>"
							<?php \checked( $is_saved ); ?>
						>
						<?php echo \esc_html( $post_type->label ); ?>
						<code><?php echo \esc_html( $post_type->name ); ?></code>
					</label>
					<?php if ( $is_external ) : ?>
						<br>
						<span class="description">
							<?php \esc_html_e( 'Enabled by another plugin or theme.', 'atmosphere' ); ?>
						</span>
					<?php elseif ( $is_filtered_out ) : ?>
						<br>
						<span class="description">
							<?php \esc_html_e( 'Disabled by another plugin or theme — this post type will not be published.', 'atmosphere' ); ?>
						</span>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
		</fieldset>
		<p class="description">
			<?php \esc_html_e( 'Select which post types are published to AT Protocol.', 'atmosphere' ); ?>
		</p>
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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d with intval is safe.
			\printf(
				/* translators: %d: maximum number of posts to backfill */
				\esc_html__( 'Sync the last %d published posts that haven\'t been sent to AT Protocol yet. You can run this multiple times to gradually sync older content.', 'atmosphere' ),
				\intval( $limit )
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
		\set_transient( 'settings_errors', \get_settings_errors(), 30 );

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

		foreach ( get_supported_post_types() as $post_type ) {
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
			// MUST match the scope string requested by Client::authorize().
			// The auth server validates the request scope against the metadata;
			// a drift here silently downgrades to the smaller of the two.
			'scope'                      => 'atproto transition:generic identity:handle',
			'dpop_bound_access_tokens'   => true,
			'application_type'           => 'web',
		);

		/**
		 * Filters the OAuth client metadata served at the REST endpoint.
		 *
		 * @param array $metadata Client metadata.
		 */
		$metadata = \apply_filters( 'atmosphere_client_metadata', $metadata );

		$response = new \WP_REST_Response( $metadata, 200 );

		// Prevent intermediate caches (CDN edge, page cache, browser) from
		// serving stale metadata. AT Protocol auth servers fetch this URL to
		// validate authorization-request scope; a stale cached version here
		// rejects every fresh authorize with "Scope X is not declared in
		// the client metadata" until the cache fills with the current
		// document. The auth server's own metadata cache (10 min in
		// Bluesky's reference impl) is still in play, but with no-store we
		// at least guarantee the path between us and them carries the
		// current document — without it, hosted environments like wp.com
		// Atomic edge-cache the endpoint and serve a stale scope to every
		// auth server that asks.
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
