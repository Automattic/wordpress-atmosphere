<?php
/**
 * Troubleshooting Help Tab template.
 *
 * @package Atmosphere
 */

\defined( 'ABSPATH' ) || exit;

$anchor_html = array(
	'a' => array(
		'href' => true,
	),
);
?>

<h2><?php \esc_html_e( 'Connecting does not work', 'atmosphere' ); ?></h2>
<p><?php \esc_html_e( 'When you connect, Bluesky fetches a small file from your site to identify it. Your site must be served over HTTPS and reachable from the internet. If a security plugin limits which plugins may use the REST API, allow ATmosphere.', 'atmosphere' ); ?></p>
<p>
	<?php
	\printf(
		/* translators: %s: Site Health screen URL. */
		\wp_kses( \__( '<a href="%s">Tools → Site Health</a> shows whether Bluesky can reach your site.', 'atmosphere' ), $anchor_html ),
		\esc_url( \admin_url( 'site-health.php' ) )
	);
	?>
</p>

<h2><?php \esc_html_e( 'A post did not appear on Bluesky', 'atmosphere' ); ?></h2>
<p><?php \esc_html_e( 'Check that auto-publish is on, that the post type is selected under Publishing, that the post is public, and that it was not left out in the editor sidebar. Posts published before connecting are not shared automatically.', 'atmosphere' ); ?></p>
