<?php
/**
 * Settings Help Tab template.
 *
 * @package Atmosphere
 */

\defined( 'ABSPATH' ) || exit;
?>

<h2><?php \esc_html_e( 'Settings on this page', 'atmosphere' ); ?></h2>
<dl>
	<dt><?php \esc_html_e( 'Connection', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Connect or disconnect your Bluesky account. You sign in on Bluesky itself, and your login is stored encrypted on this site. Once connected, ATmosphere may offer to use your site’s domain as your Bluesky handle.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'Publishing', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Choose whether new posts are shared automatically, which post types are shared, how long posts become Bluesky threads, how the full article is saved, and whether the Bluesky ID is used as the short URL of a post. Individual posts can be left out from the ATmosphere panel in the editor sidebar.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'Theme', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Choose the colors reading apps use when they display your site. Empty fields use the colors of your WordPress theme.', 'atmosphere' ); ?></dd>
	<dt><?php \esc_html_e( 'Reactions', 'atmosphere' ); ?></dt>
	<dd><?php \esc_html_e( 'Choose which Bluesky replies, likes, and reposts become comments on your site, and whether WordPress comments are sent to Bluesky as replies.', 'atmosphere' ); ?></dd>
</dl>
