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
import { ToggleControl, ExternalLink, Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import { META_KEY } from '../config';
import { isSharingEnabled } from './utils';

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

	// `PluginDocumentSettingPanel` moved from edit-post to editor; support both.
	const SettingsPanel = PluginDocumentSettingPanel || DocumentSettingPanel;

	return (
		<SettingsPanel
			name="atmosphere"
			title={ __( 'Bluesky', 'atmosphere' ) }
		>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Share this post to Bluesky', 'atmosphere' ) }
				checked={ enabled }
				onChange={ ( value ) =>
					setMeta( { ...meta, [ META_KEY ]: ! value } )
				}
				help={
					enabled
						? __(
								'This post will be shared to Bluesky when published.',
								'atmosphere'
						  )
						: __(
								'This post will not be shared to Bluesky.',
								'atmosphere'
						  )
				}
			/>

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
