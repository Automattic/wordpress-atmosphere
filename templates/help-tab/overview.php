<?php
/**
 * Overview Help Tab template.
 *
 * @package Atmosphere
 */

\defined( 'ABSPATH' ) || exit;
?>

<h2><?php \esc_html_e( 'What ATmosphere does', 'atmosphere' ); ?></h2>
<p><?php \esc_html_e( 'ATmosphere shares your WordPress posts on Bluesky. When you publish a post, it appears on your Bluesky account a moment later, and the full article is stored on your account so other compatible apps can read it too.', 'atmosphere' ); ?></p>
<p><?php \esc_html_e( 'Replies, likes, and reposts on Bluesky come back as comments on your post. When Outgoing replies is turned on, approved comments from users who can publish posts are sent to Bluesky as replies.', 'atmosphere' ); ?></p>

<h2><?php \esc_html_e( 'Older posts', 'atmosphere' ); ?></h2>
<p>
	<?php
	\printf(
		/* translators: %s: WP-CLI command. */
		\esc_html__( 'Only posts published after connecting are shared. To share older posts, run %s from WP-CLI.', 'atmosphere' ),
		'<code>wp atmosphere backfill</code>'
	);
	?>
</p>
