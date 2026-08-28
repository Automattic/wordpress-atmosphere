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
	SelectControl,
	CheckboxControl,
	ExternalLink,
	Notice,
	SVG,
	Path,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import {
	DISABLED_META_KEY,
	CUSTOM_TEXT_META_KEY,
	REPLY_RESTRICTION_META_KEY,
	SETTINGS_URL,
	CAN_MANAGE,
	THREADGATE_NEEDS_RECONNECT,
} from '../config';
import {
	isSharingEnabled,
	readReplyRestriction,
	getReplyMode,
	getReplyAudiences,
	buildRestrictionForMode,
	toggleReplyAudience,
	REPLY_AUDIENCE,
} from './utils';

/**
 * The ATmosphere symbol (the plugin logo), shown after the panel title like
 * the ⁂ on the Fediverse panel. `currentColor` so it renders monochrome and
 * follows the editor text color (black in light mode, white in dark); the
 * even-odd fill rule keeps the concentric rings hollow. aria-hidden because
 * the adjacent "ATmosphere" text is the accessible label.
 *
 * @type {React.JSX.Element}
 */
const atmosphereIcon = (
	<SVG
		width="16"
		height="16"
		viewBox="91 91 318 318"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
		focusable="false"
		style={ { marginInlineStart: '6px', verticalAlign: 'text-bottom' } }
	>
		<Path
			fillRule="evenodd"
			clipRule="evenodd"
			fill="currentColor"
			d="M252.352 125.333C286.128 91.5561 340.892 91.5561 374.668 125.333C408.445 159.109 408.445 213.872 374.668 247.648C373.865 248.451 373.049 249.235 372.223 250C373.049 250.765 373.865 251.549 374.668 252.352C408.445 286.129 408.445 340.891 374.668 374.668C340.892 408.444 286.128 408.444 252.352 374.668C251.548 373.864 250.765 373.048 249.999 372.221C249.234 373.048 248.451 373.864 247.648 374.668C213.871 408.444 159.109 408.444 125.332 374.668C91.5559 340.891 91.5559 286.129 125.332 252.352C126.136 251.549 126.95 250.765 127.777 250C126.951 249.235 126.135 248.451 125.332 247.648C91.5559 213.872 91.5559 159.109 125.332 125.333C159.109 91.5561 213.871 91.5561 247.648 125.333C248.451 126.136 249.235 126.951 249.999 127.777C250.764 126.951 251.549 126.136 252.352 125.333ZM250.46 163.971C202.693 163.972 163.97 202.695 163.97 250.462C163.97 298.229 202.693 336.952 250.46 336.952C298.228 336.952 336.951 298.229 336.951 250.462C336.95 202.694 298.228 163.971 250.46 163.971Z"
		/>
	</SVG>
);

/**
 * The ATmosphere document settings panel.
 *
 * @return {React.JSX.Element|null} The panel, or null for sync blocks.
 */
const EditorPlugin = () => {
	const { postType, sharedUrl, publishError } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postType: editor.getCurrentPostType(),
			sharedUrl: editor.getCurrentPost()?.atmosphere_url,
			publishError: editor.getCurrentPost()?.atmosphere_publish_error,
		};
	}, [] );

	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	/* The reply mode lives in local state, not derived straight from meta,
	   because "Specific people" with nothing ticked yet stores an empty
	   restriction — which is indistinguishable from "Everybody" on the
	   meta side. Deriving would snap the dropdown back to Everybody before
	   the author can tick a box. */
	const storedMode = getReplyMode( readReplyRestriction( meta ) );
	const [ replyMode, setReplyMode ] = useState( storedMode );

	/* Re-sync when the stored value implies a different mode — loading a
	   post, or an external change — but never clobber a freshly chosen
	   "Specific people" (stored empty until a box is ticked) by snapping it
	   back to Everybody. */
	useEffect( () => {
		if (
			storedMode !== replyMode &&
			! ( 'custom' === replyMode && 'everybody' === storedMode )
		) {
			setReplyMode( storedMode );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ storedMode ] );

	// Don't show when editing reusable/synced blocks.
	if ( 'wp_block' === postType ) {
		return null;
	}

	const enabled = isSharingEnabled( meta );
	const customText = ( meta && meta[ CUSTOM_TEXT_META_KEY ] ) || '';

	const restriction = readReplyRestriction( meta );
	const replyAudiences = getReplyAudiences( restriction );

	const setRestriction = ( value ) =>
		setMeta( { ...meta, [ REPLY_RESTRICTION_META_KEY ]: value } );

	const onReplyModeChange = ( mode ) => {
		setReplyMode( mode );
		setRestriction( buildRestrictionForMode( mode, replyAudiences ) );
	};

	// Audience checkboxes shown under "Specific people", as [ token, label ].
	const replyAudienceOptions = [
		[ REPLY_AUDIENCE.MENTIONED, __( 'People you mention', 'atmosphere' ) ],
		[ REPLY_AUDIENCE.FOLLOWING, __( 'People you follow', 'atmosphere' ) ],
		[ REPLY_AUDIENCE.FOLLOWER, __( 'Your followers', 'atmosphere' ) ],
	];

	/* Precomputed so the notice below avoids a nested ternary. The
	   server classifies which failures only a reconnect can fix — the
	   panel keeps no error-code list of its own. The settings link is
	   shown only to users who can open the settings page; everyone
	   else is told who can. */
	const needsReconnect = publishError?.needs_reconnect;
	const reconnectMessage = CAN_MANAGE ? (
		<>
			{ __(
				'Sharing to Bluesky failed because your site is no longer connected to Bluesky.',
				'atmosphere'
			) }{ ' ' }
			<a href={ SETTINGS_URL }>
				{ __( 'Reconnect on the settings page.', 'atmosphere' ) }
			</a>
		</>
	) : (
		__(
			'Sharing to Bluesky failed because your site is no longer connected to Bluesky. Ask an administrator to reconnect it.',
			'atmosphere'
		)
	);
	const retryMessage = publishError?.retrying
		? __(
				'Sharing to Bluesky failed. Your site will retry automatically.',
				'atmosphere'
		  )
		: __(
				'Sharing to Bluesky failed. Update the post to try again.',
				'atmosphere'
		  );

	// `PluginDocumentSettingPanel` moved from edit-post to editor; support both.
	const SettingsPanel = PluginDocumentSettingPanel || DocumentSettingPanel;

	return (
		<SettingsPanel
			name="atmosphere"
			className="block-editor-block-inspector"
			title={
				<>
					{ __( 'ATmosphere', 'atmosphere' ) }
					{ atmosphereIcon }
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

			{ enabled && (
				<SelectControl
					label={ __( 'Who can reply on Bluesky', 'atmosphere' ) }
					value={ replyMode }
					options={ [
						{
							label: __( 'Everybody', 'atmosphere' ),
							value: 'everybody',
						},
						{
							label: __( 'Nobody', 'atmosphere' ),
							value: 'nobody',
						},
						{
							label: __( 'Specific people', 'atmosphere' ),
							value: 'custom',
						},
					] }
					onChange={ onReplyModeChange }
					help={ __(
						'Choose who is allowed to reply to this post on Bluesky.',
						'atmosphere'
					) }
				/>
			) }

			{ enabled &&
				THREADGATE_NEEDS_RECONNECT &&
				replyMode !== 'everybody' && (
					<Notice status="warning" isDismissible={ false }>
						{ CAN_MANAGE ? (
							<>
								{ __(
									'This restriction is skipped until the site reconnects to Bluesky. The post still shares as usual.',
									'atmosphere'
								) }{ ' ' }
								<a href={ SETTINGS_URL }>
									{ __(
										'Reconnect on the settings page.',
										'atmosphere'
									) }
								</a>
							</>
						) : (
							__(
								'This restriction is skipped until an administrator reconnects the site to Bluesky. The post still shares as usual.',
								'atmosphere'
							)
						) }
					</Notice>
				) }

			{ enabled &&
				'custom' === replyMode &&
				replyAudienceOptions.map( ( [ token, label ] ) => (
					<CheckboxControl
						key={ token }
						label={ label }
						checked={ replyAudiences.includes( token ) }
						onChange={ ( on ) =>
							setRestriction(
								toggleReplyAudience( restriction, token, on )
							)
						}
					/>
				) ) }

			{ /* Empty "Specific people" serializes to an open post, so say so
			     rather than let the dropdown imply a restriction is in place. */ }
			{ enabled &&
				'custom' === replyMode &&
				0 === replyAudiences.length && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'No one selected yet, so everyone can reply. Pick at least one group to limit replies.',
							'atmosphere'
						) }
					</Notice>
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

			{ /* The last share attempt failed. The record comes from the
			     `atmosphere_publish_error` REST field (cleared server-side on
			     the next success), so the notice disappears once a share goes
			     through. Retrying=true means the backoff ladder has another
			     attempt queued; otherwise the author's update is the retry. */ }
			{ publishError && enabled && (
				<Notice status="error" isDismissible={ false }>
					{ needsReconnect ? reconnectMessage : retryMessage }
					{ publishError.message && (
						<p style={ { marginBottom: 0 } }>
							<small>{ publishError.message }</small>
						</p>
					) }
				</Notice>
			) }
		</SettingsPanel>
	);
};

registerPlugin( 'atmosphere-editor-plugin', { render: EditorPlugin } );
