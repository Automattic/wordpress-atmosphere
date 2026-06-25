import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';

/**
 * Save the block.
 *
 * The facepile is server-rendered via render.php; the saved markup only
 * carries the InnerBlocks title content.
 *
 * @return {Element} Saved markup.
 */
export default function save() {
	return (
		<div { ...useBlockProps.save() }>
			<InnerBlocks.Content />
		</div>
	);
}
