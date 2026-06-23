/**
 * ATmosphere document settings panel.
 *
 * Adds a "Bluesky" panel to the block-editor document sidebar with a
 * per-post toggle that controls whether the post is shared to Bluesky,
 * plus a link to the shared post once it exists. Replaces the legacy PHP
 * meta box.
 */

import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { PluginDocumentSettingPanel as DocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import {
	ToggleControl,
	TextareaControl,
	ExternalLink,
	Notice,
	SVG,
	Path,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import { DISABLED_META_KEY, CUSTOM_TEXT_META_KEY } from '../config';
import { isSharingEnabled } from './utils';

/**
 * The Bluesky butterfly logo, shown next to the panel title (like the ⁂ on
 * the Fediverse panel, but an actual icon since there is no non-emoji
 * butterfly glyph). `currentColor` so it follows the editor text color;
 * aria-hidden because the adjacent "Bluesky" text is the accessible label.
 *
 * @type {React.JSX.Element}
 */
const blueskyIcon = (
	<SVG
		width="16"
		height="16"
		viewBox="0 0 568 501"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
		focusable="false"
		style={ { marginInlineStart: '6px', verticalAlign: 'text-bottom' } }
	>
		<Path
			fill="currentColor"
			d="M123.121 33.664C188.241 82.553 258.281 181.68 284 234.873c25.719-53.192 95.759-152.32 160.879-201.21C491.866-1.612 568-28.906 568 57.946c0 17.346-9.945 145.713-15.778 166.555-20.275 72.453-94.155 90.933-159.875 79.748C507.222 323.8 536.444 388.56 473.333 453.32 353.473 576.312 301.061 422.461 287.631 383.039c-2.462-7.227-3.614-10.608-3.631-7.733-.017-2.875-1.169.506-3.631 7.733-13.43 39.422-65.842 193.273-185.702 70.281-63.111-64.76-33.889-129.52 80.986-149.071-65.72 11.185-139.6-7.295-159.875-79.748C9.945 203.659 0 75.291 0 57.946 0-28.906 76.134-1.612 123.121 33.664Z"
		/>
	</SVG>
);

/**
 * The ATmosphere document settings panel.
 *
 * @return {React.JSX.Element|null} The panel, or null for sync blocks.
 */
const EditorPlugin = () => {
	const { postType, sharedUrl } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postType: editor.getCurrentPostType(),
			sharedUrl: editor.getCurrentPost()?.atmosphere_url,
		};
	}, [] );

	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	// Don't show when editing reusable/synced blocks.
	if ( 'wp_block' === postType ) {
		return null;
	}

	const enabled = isSharingEnabled( meta );
	const customText = ( meta && meta[ CUSTOM_TEXT_META_KEY ] ) || '';

	// `PluginDocumentSettingPanel` moved from edit-post to editor; support both.
	const SettingsPanel = PluginDocumentSettingPanel || DocumentSettingPanel;

	return (
		<SettingsPanel
			name="atmosphere"
			className="block-editor-block-inspector"
			title={
				<>
					{ __( 'ATmosphere', 'atmosphere' ) }
					{ blueskyIcon }
				</>
			}
		>
			<ToggleControl
				label={ __( 'Share this post', 'atmosphere' ) }
				checked={ enabled }
				onChange={ ( value ) =>
					setMeta( { ...meta, [ DISABLED_META_KEY ]: ! value } )
				}
				help={
					enabled
						? __(
								'This post will be shared via ATmosphere when published.',
								'atmosphere'
						  )
						: __(
								'This post will not be shared via ATmosphere.',
								'atmosphere'
						  )
				}
			/>

			{ enabled && (
				<TextareaControl
					label={ __( 'Custom Bluesky text', 'atmosphere' ) }
					value={ customText }
					onChange={ ( value ) =>
						setMeta( { ...meta, [ CUSTOM_TEXT_META_KEY ]: value } )
					}
					help={ __(
						'Leave empty to use the default message, or write your own. It’s shared with a link back to this post, and you can mention other Bluesky users.',
						'atmosphere'
					) }
				/>
			) }

			{ sharedUrl && enabled && (
				<p>
					<ExternalLink href={ sharedUrl }>
						{ __( 'View on Bluesky', 'atmosphere' ) }
					</ExternalLink>
				</p>
			) }

			{ /* Sharing is off but the post is still on Bluesky. Removal
			     happens on the next sync (which needs a live connection and
			     auto-publishing on), so the wording doesn't promise timing.
			     The notice stays visible until the record is gone, giving the
			     author a reason to re-save if it lingers. */ }
			{ sharedUrl && ! enabled && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Sharing is off, but this post is still on Bluesky. It will be removed the next time your site syncs.',
						'atmosphere'
					) }
				</Notice>
			) }
		</SettingsPanel>
	);
};

registerPlugin( 'atmosphere-editor-plugin', { render: EditorPlugin } );
