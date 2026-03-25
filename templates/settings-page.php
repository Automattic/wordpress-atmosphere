<?php
/**
 * Settings page template for ATmosphere.
 *
 * @package Atmosphere
 */

\defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php \esc_html_e( 'ATmosphere', 'atmosphere' ); ?></h1>
	<p><?php \esc_html_e( 'Publish your WordPress content to the AT Protocol network, including Bluesky and standard.site.', 'atmosphere' ); ?></p>

	<form method="post" action="options.php">
		<?php \settings_fields( 'atmosphere' ); ?>
		<?php \do_settings_sections( 'atmosphere' ); ?>
		<?php \submit_button(); ?>
	</form>
</div>
