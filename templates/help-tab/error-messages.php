<?php
/**
 * Error Messages Help Tab template.
 *
 * @package Atmosphere
 */

\defined( 'ABSPATH' ) || exit;
?>

<h2><?php \esc_html_e( 'Common messages', 'atmosphere' ); ?></h2>
<dl>
	<dt><?php \esc_html_e( 'ATmosphere: reconnection required', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'ATmosphere can no longer use your saved Bluesky login. This happens when your Bluesky session expires, when your site’s address changes, or when the security keys in wp-config.php change after a migration or because a security plugin rotates them. Reconnect your account on this page. If it keeps happening, look for a plugin that rotates your security keys on a schedule.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'ATmosphere cannot read the key it uses to sign in to Bluesky', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'The security keys in wp-config.php changed, so the saved login cannot be renewed. Disconnect and connect again to create a new key.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'Bluesky rejected this site’s login setup', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Bluesky did not accept the details this site sent when renewing the login. Site Health explains what Bluesky objected to.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'Reply restrictions need a reconnect', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Your site connected to Bluesky before reply restrictions were available. Posts still share as usual. Reconnect your account to turn restrictions on.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'Auto-publish is on, but no post types are selected', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Nothing will be published until you select one or more post types under Post types.', 'atmosphere' ); ?></dd>
</dl>
