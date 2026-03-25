<?php
/**
 * Settings page template for ATmosphere.
 *
 * @package Atmosphere
 */

use function Atmosphere\get_connection;
use function Atmosphere\is_connected;

\defined( 'ABSPATH' ) || exit;

$connection = get_connection();
$connected  = is_connected();
?>
<div class="wrap">
	<h1><?php \esc_html_e( 'ATmosphere', 'atmosphere' ); ?></h1>

	<?php \settings_errors( 'atmosphere' ); ?>

	<!-- Connection -->
	<div class="card">
		<h2><?php \esc_html_e( 'Connection', 'atmosphere' ); ?></h2>

		<?php if ( $connected ) : ?>
			<p>
				<?php
				\printf(
					/* translators: %s: AT Protocol handle */
					\esc_html__( 'Connected as %s', 'atmosphere' ),
					'<strong>' . \esc_html( $connection['handle'] ?? $connection['did'] ?? '' ) . '</strong>'
				);
				?>
			</p>
			<p class="description">
				<?php \esc_html_e( 'DID:', 'atmosphere' ); ?>
				<code><?php echo \esc_html( $connection['did'] ?? '' ); ?></code>
			</p>
			<p class="description">
				<?php \esc_html_e( 'PDS:', 'atmosphere' ); ?>
				<code><?php echo \esc_html( $connection['pds_endpoint'] ?? '' ); ?></code>
			</p>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="atmosphere_disconnect">
				<?php \wp_nonce_field( 'atmosphere_disconnect', 'atmosphere_nonce' ); ?>
				<?php \submit_button( \__( 'Disconnect', 'atmosphere' ), 'delete', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p><?php \esc_html_e( 'Enter your AT Protocol handle to connect.', 'atmosphere' ); ?></p>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="atmosphere_connect">
				<?php \wp_nonce_field( 'atmosphere_connect', 'atmosphere_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="atmosphere_handle"><?php \esc_html_e( 'Handle', 'atmosphere' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								name="atmosphere_handle"
								id="atmosphere_handle"
								class="regular-text"
								placeholder="alice.bsky.social"
								required
							>
						</td>
					</tr>
				</table>

				<?php \submit_button( \__( 'Connect', 'atmosphere' ) ); ?>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( $connected ) : ?>
		<!-- Publication -->
		<div class="card">
			<h2><?php \esc_html_e( 'Publication', 'atmosphere' ); ?></h2>
			<p><?php \esc_html_e( 'Sync your site name, description, and icon as a standard.site publication record.', 'atmosphere' ); ?></p>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="atmosphere_sync_publication">
				<?php \wp_nonce_field( 'atmosphere_sync_publication', 'atmosphere_nonce' ); ?>
				<?php \submit_button( \__( 'Sync Publication', 'atmosphere' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>

		<!-- Settings -->
		<div class="card">
			<h2><?php \esc_html_e( 'Publishing', 'atmosphere' ); ?></h2>

			<form method="post" action="options.php">
				<?php \settings_fields( 'atmosphere' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php \esc_html_e( 'Auto-publish', 'atmosphere' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="atmosphere_auto_publish"
									value="1"
									<?php \checked( \get_option( 'atmosphere_auto_publish', '1' ), '1' ); ?>
								>
								<?php \esc_html_e( 'Automatically publish new posts to AT Protocol', 'atmosphere' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php \submit_button(); ?>
			</form>
		</div>

		<!-- Backfill -->
		<div class="card">
			<h2><?php \esc_html_e( 'Backfill', 'atmosphere' ); ?></h2>
			<p>
			<?php
			\printf(
				/* translators: %d: maximum number of posts to backfill */
				\esc_html__( 'Sync the last %d published posts that haven\'t been sent to AT Protocol yet.', 'atmosphere' ),
				(int) \apply_filters( 'atmosphere_backfill_limit', 10 )
			);
			?>
		</p>

			<div id="atmosphere-backfill">
				<button type="button" class="button" id="atmosphere-backfill-start">
					<?php \esc_html_e( 'Start Backfill', 'atmosphere' ); ?>
				</button>
				<div id="atmosphere-backfill-progress" style="display:none; margin-top: 10px;">
					<progress id="atmosphere-backfill-bar" value="0" max="100" style="width: 100%;"></progress>
					<p id="atmosphere-backfill-status"></p>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
