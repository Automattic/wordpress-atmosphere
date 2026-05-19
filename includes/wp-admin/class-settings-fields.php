<?php
/**
 * Settings API sections, fields, and their render callbacks.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Atmosphere;
use Atmosphere\Handle;
use function Atmosphere\get_connection;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\has_identity;
use function Atmosphere\is_connected;

/**
 * Settings page UI assembly.
 *
 * Mirrors the ActivityPub `Settings_Fields` class: a single static
 * `register_settings_fields()` method that runs on
 * `load-settings_page_atmosphere`, building the sections / fields only
 * when the Settings page is actually being rendered.
 */
class Settings_Fields {

	/**
	 * Wire the registration hook.
	 */
	public static function init(): void {
		\add_action( 'load-settings_page_atmosphere', array( self::class, 'register_settings_fields' ) );
	}

	/**
	 * Build the Settings API sections and fields for the plugin's page.
	 */
	public static function register_settings_fields(): void {
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

			/*
			 * Even while the OAuth session is gone, an existing identity
			 * keeps the publishing-section UI visible so the user does
			 * not lose sight of their stored auto-publish, long-form,
			 * and post-type preferences during a reauth round-trip. The
			 * publish callbacks themselves gate on `is_connected()` and
			 * stay short-circuited until reauth completes.
			 */
			if ( ! has_identity() ) {
				return;
			}
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

		/*
		 * Register the domain-handle confirm row only when the offer is
		 * meaningful (root install, feature enabled, current handle differs
		 * from the site host). Skipping registration is the cleanest way to
		 * suppress the row entirely without rendering an empty <tr>.
		 */
		if ( Handle::should_offer(
			array(
				'connected' => true,
				'handle'    => get_connection()['handle'] ?? '',
			)
		) ) {
			\add_settings_field(
				'atmosphere_set_domain_handle',
				\__( 'Domain handle', 'atmosphere' ),
				array( self::class, 'render_domain_handle_field' ),
				'atmosphere',
				'atmosphere_connection'
			);
		}
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
	 * Render the "use my domain as my Bluesky handle" confirm field.
	 *
	 * Registered conditionally on the `atmosphere_connection` section
	 * via {@see self::register_settings_fields()}; only enqueued when
	 * {@see Handle::should_offer()} agrees the offer is meaningful.
	 */
	public static function render_domain_handle_field(): void {
		$current  = (string) ( get_connection()['handle'] ?? '' );
		$target   = Handle::get_target_handle();
		$post_url = \admin_url( 'admin-post.php?action=atmosphere_set_domain_handle' );
		?>
		<p>
			<?php
			if ( '' !== $current ) {
				echo \esc_html(
					\sprintf(
						/* translators: 1: current Bluesky handle (e.g. alice.bsky.social); 2: target handle = site host (e.g. example.com). */
						\__( 'Your current Bluesky handle is %1$s. Click the button below to replace it with %2$s.', 'atmosphere' ),
						$current,
						$target
					)
				);
			} else {
				echo \esc_html(
					\sprintf(
						/* translators: %s: target handle = site host (e.g. example.com). */
						\__( 'Click the button below to set your Bluesky handle to %s.', 'atmosphere' ),
						$target
					)
				);
			}
			?>
		</p>
		<p>
			<?php
			/*
			 * The settings page wraps every field in a single outer
			 * <form action="options.php" method="post">. A nested form
			 * would be invalid HTML, so the submit button overrides the
			 * outer form's destination via formaction/formmethod when —
			 * and only when — this button is the one clicked. The Save
			 * button at the bottom of the settings page still posts to
			 * options.php as normal. This keeps the nonce in the request
			 * body instead of leaking it through the URL / Referer
			 * header / link prefetching, which an <a> with
			 * wp_nonce_url() would do.
			 */
			\wp_nonce_field( 'atmosphere_set_domain_handle', 'atmosphere_nonce', false );
			?>
			<button
				type="submit"
				formaction="<?php echo \esc_url( $post_url ); ?>"
				formmethod="post"
				class="button">
				<?php
				echo \esc_html(
					\sprintf(
						/* translators: %s: target handle = site host (e.g. example.com). */
						\__( 'Use %s as my Bluesky handle', 'atmosphere' ),
						$target
					)
				);
				?>
			</button>
		</p>
		<p class="description">
			<?php \esc_html_e( 'Heads up: replacing your handle is destructive. Your previous handle will stop resolving immediately, and links to it will break. Bluesky verifies the new handle through this site automatically.', 'atmosphere' ); ?>
		</p>
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
}
