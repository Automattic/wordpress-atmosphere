<?php
/**
 * Site Health Help Tab template.
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

<h2><?php \esc_html_e( 'Checking your connection', 'atmosphere' ); ?></h2>
<p>
	<?php
	\printf(
		/* translators: %s: Site Health screen URL. */
		\wp_kses( \__( 'ATmosphere adds its own checks to <a href="%s">Tools → Site Health</a>. These are the messages it can show:', 'atmosphere' ), $anchor_html ),
		\esc_url( \admin_url( 'site-health.php' ) )
	);
	?>
</p>
<dl>
	<dt><?php \esc_html_e( 'ATmosphere could not renew its Bluesky login recently', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'The last renewal failed. The Info tab shows the error under Last Login Renewal.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'ATmosphere has not renewed its Bluesky login recently', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Scheduled tasks do not run often enough. Set up a real server cron so the login does not expire on quiet sites.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'ATmosphere still uses the older Bluesky login', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'This login expires every two weeks. Disconnect and connect again once to switch to the longer-lasting login.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'Bluesky cannot reach ATmosphere on this site', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Connecting cannot work. Make sure your site uses HTTPS and that security plugins allow ATmosphere to use the REST API.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'ATmosphere could not validate this site’s certificate', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'If your certificate is valid for visitors, Bluesky will accept it and you can ignore this.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'ATmosphere needs a reconnect to restrict replies on Bluesky', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Reconnect once to let ATmosphere set who can reply to your posts.', 'atmosphere' ); ?></dd>
</dl>
<p><?php \esc_html_e( 'The reconnect, signing key, and login setup messages from the Error Messages tab show up here too.', 'atmosphere' ); ?></p>

<h2><?php \esc_html_e( 'Asking for help', 'atmosphere' ); ?></h2>
<p>
	<?php
	\printf(
		/* translators: %s: Site Health Info tab URL. */
		\wp_kses( \__( 'The <a href="%s">Info tab of Site Health</a> has an ATmosphere section with your plugin version, connection status, and handle. Use “Copy site info to clipboard” and paste it into your support request.', 'atmosphere' ), $anchor_html ),
		\esc_url( \admin_url( 'site-health.php?tab=debug' ) )
	);
	?>
</p>
