<?php
/**
 * Shared block/image fixtures for content-parser tests.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Content_Parser;

/**
 * Helpers to build block content and image attachments for tier-2
 * parser tests.
 */
trait Block_Fixtures {

	/**
	 * Create an image attachment with a cached blob ref and dimensions.
	 *
	 * Seeding `_atmosphere_blob_ref` makes Post::upload_image_blob()
	 * return without a network upload; the attachment metadata supplies
	 * the aspect ratio.
	 *
	 * @param string $alt    Alt text.
	 * @param int    $width  Pixel width.
	 * @param int    $height Pixel height.
	 * @return int Attachment ID.
	 */
	protected function make_image_attachment( string $alt = 'A photo', int $width = 1600, int $height = 900 ): int {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'photo.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Photo' )
		);

		\update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		\update_post_meta(
			$attachment_id,
			'_atmosphere_blob_ref',
			array(
				'$type'    => 'blob',
				'ref'      => array( '$link' => 'bafyfakecid' ),
				'mimeType' => 'image/jpeg',
				'size'     => 4096,
			)
		);
		\wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => $width,
				'height' => $height,
			)
		);

		return $attachment_id;
	}

	/**
	 * Block markup exercising paragraph, heading, list, quote, code.
	 *
	 * @return string
	 */
	protected function rich_block_content(): string {
		return implode(
			"\n",
			array(
				'<!-- wp:heading {"level":2} --><h2>Title</h2><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>Hello world.</p><!-- /wp:paragraph -->',
				'<!-- wp:list --><ul><!-- wp:list-item --><li>First</li><!-- /wp:list-item --><!-- wp:list-item --><li>Second</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
				'<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>A quote.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->',
				'<!-- wp:code --><pre class="wp-block-code"><code>echo 1;</code></pre><!-- /wp:code -->',
			)
		);
	}

	/**
	 * An image block referencing the given attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	protected function image_block( int $attachment_id ): string {
		return \sprintf(
			'<!-- wp:image {"id":%1$d} --><figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt="A photo" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
			$attachment_id
		);
	}
}
