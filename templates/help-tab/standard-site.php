<?php
/**
 * Help Tab template for standard.site.
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

<h2><?php \esc_html_e( 'standard.site', 'atmosphere' ); ?></h2>
<p><?php \esc_html_e( 'Next to the short Bluesky post, ATmosphere saves the full article to your Bluesky account in the standard.site format. standard.site is an open format for blogs and newsletters on the same network as Bluesky, so long-form reading apps such as Leaflet and pckt can show your posts in full and link back to your site.', 'atmosphere' ); ?></p>
<p><?php \esc_html_e( 'Your site itself is saved as a publication, with its name, description, icon, and colors, and is kept up to date when you publish. Use Content format to choose how the full text is saved, and Theme to choose the colors reading apps use for your site.', 'atmosphere' ); ?></p>
<p>
	<?php
	\printf(
		/* translators: %s: standard.site URL. */
		\wp_kses( \__( 'Learn more at <a href="%s">standard.site</a>.', 'atmosphere' ), $anchor_html ),
		\esc_url( 'https://standard.site/' )
	);
	?>
</p>
