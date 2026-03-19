<?php
/**
 * Meta box template for ATmosphere.
 *
 * @package Atmosphere
 *
 * @var WP_Post $post Current post.
 */

use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post as BskyPost;

\defined( 'ABSPATH' ) || exit;

$bsky_uri = \get_post_meta( $post->ID, BskyPost::META_URI, true );
$doc_uri  = \get_post_meta( $post->ID, Document::META_URI, true );
$synced   = ! empty( $bsky_uri ) || ! empty( $doc_uri );
?>
<div class="atmosphere-meta-box">
	<?php if ( $synced ) : ?>
		<p>
			<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
			<?php \esc_html_e( 'Synced to AT Protocol', 'atmosphere' ); ?>
		</p>

		<?php if ( $bsky_uri ) : ?>
			<p class="description">
				<strong><?php \esc_html_e( 'Bluesky post:', 'atmosphere' ); ?></strong><br>
				<code style="font-size: 11px; word-break: break-all;"><?php echo \esc_html( $bsky_uri ); ?></code>
			</p>
		<?php endif; ?>

		<?php if ( $doc_uri ) : ?>
			<p class="description">
				<strong><?php \esc_html_e( 'Document:', 'atmosphere' ); ?></strong><br>
				<code style="font-size: 11px; word-break: break-all;"><?php echo \esc_html( $doc_uri ); ?></code>
			</p>
		<?php endif; ?>
	<?php else : ?>
		<p>
			<span class="dashicons dashicons-minus" style="color: #999;"></span>
			<?php \esc_html_e( 'Not yet synced to AT Protocol.', 'atmosphere' ); ?>
		</p>
		<p class="description">
			<?php \esc_html_e( 'This post will be published to AT Protocol when it is published or updated (if auto-publish is enabled).', 'atmosphere' ); ?>
		</p>
	<?php endif; ?>
</div>
