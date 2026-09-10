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
	BaseControl,
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
	RECONNECT_URL,
	CAN_MANAGE,
	SHARE_STATUS,
	REPLY_RESTRICTION_META_KEY,
	SETTINGS_URL,
	THREADGATE_NEEDS_RECONNECT,
} from '../config';
import {
	isSharingEnabled,
	shareHelpText,
	panelMessage,
	readReplyRestriction,
	getReplyMode,
	getReplyAudiences,
	buildRestrictionForMode,
	toggleReplyAudience,
	REPLY_AUDIENCE,
} from './utils';
import { ReconnectAction } from '../shared/reconnect-notice';

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
	const {
		postType,
		sharedUrl,
		hasRecord,
		publishError,
		editedStatus,
		editedPassword,
		isBeingScheduled,
		isDirty,
	} = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postType: editor.getCurrentPostType(),
			sharedUrl: editor.getCurrentPost()?.atmosphere_url,

			/*
			 * Not `!! sharedUrl`: that is a Bluesky web URL built from the
			 * post's own AT-URI, so a document-only site never has one even
			 * though the cleanup path still deletes its document record.
			 * This field is the server's `has_post_records()`, the same fact
			 * the deletion is keyed off.
			 */
			hasRecord: !! editor.getCurrentPost()?.atmosphere_has_record,
			publishError: editor.getCurrentPost()?.atmosphere_publish_error,
			editedStatus: editor.getEditedPostAttribute( 'status' ),
			editedPassword: editor.getEditedPostAttribute( 'password' ),

			/*
			 * `wp_insert_post()` rewrites `publish` to `future` when the
			 * date is a minute or more ahead, on updates as well as inserts,
			 * so moving a published post's date forward removes its record
			 * exactly like unpublishing does. Gutenberg's schedule control
			 * only edits the date, never the status, so the edited status is
			 * still `publish` and would miss it. This selector applies core's
			 * own one-minute rule.
			 */
			isBeingScheduled: editor.isEditedPostBeingScheduled(),

			/*
			 * Picks the tense of the removal warning: the condition behind it
			 * is state rather than a pending edit, so it survives the save
			 * that acts on it.
			 */
			isDirty: editor.isEditedPostDirty(),
		};
	}, [] );

	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

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
	const message = panelMessage( SHARE_STATUS, {
		enabled,
		hasRecord,
		hasPublishError: !! publishError,
		willBeUnpublished:
			'publish' !== editedStatus ||
			isBeingScheduled ||
			!! editedPassword ||
			! enabled,
		isDirty,
	} );
	const customText = ( meta && meta[ CUSTOM_TEXT_META_KEY ] ) || '';

	/* Precomputed so the notice below avoids a nested ternary. The
	   server classifies which failures only a reconnect can fix — the
	   panel keeps no error-code list of its own. The settings link is
	   shown only to users who can open the settings page; everyone
	   else is told who can.

	   These three branches are only reachable on a stale snapshot, which
	   is the bug this panel exists for: `SHARE_STATUS` is fixed at page
	   load, while `atmosphere_publish_error` refreshes with every save. A
	   connection that dies mid-session leaves `can_share` true (so
	   `panelMessage()` reaches `publishError` at all) while the REST field
	   starts reporting `needs_reconnect`. On a fresh load the site-level
	   banner has already taken over and none of this renders.

	   The strings are kept self-contained (not built from the shared
	   ReconnectAction lead + link) to preserve their existing
	   translations. There is no variant that drops the call to action:
	   this notice only renders when the site can share, so it can never
	   sit under a banner that already carries one. */
	const needsReconnect = publishError?.needs_reconnect;
	let reconnectMessage;
	if ( CAN_MANAGE && RECONNECT_URL ) {
		reconnectMessage = (
			<>
				{ __(
					'Sharing to Bluesky failed because your site is no longer connected to Bluesky.',
					'atmosphere'
				) }{ ' ' }
				<a href={ RECONNECT_URL }>
					{ __( 'Reconnect on the settings page.', 'atmosphere' ) }
				</a>
			</>
		);
	} else if ( CAN_MANAGE ) {
		reconnectMessage = __(
			'Sharing to Bluesky failed because your site is no longer connected to Bluesky. Reconnect your Bluesky account to fix this.',
			'atmosphere'
		);
	} else {
		reconnectMessage = __(
			'Sharing to Bluesky failed because your site is no longer connected to Bluesky. Ask an administrator to reconnect it.',
			'atmosphere'
		);
	}
	const retryMessage = publishError?.retrying
		? __(
				'Sharing to Bluesky failed. Your site will retry automatically.',
				'atmosphere'
		  )
		: __(
				'Sharing to Bluesky failed. Update the post to try again.',
				'atmosphere'
		  );

	/*
	 * Sharing forced off from outside, with no record to link to: a host
	 * plugin owns the sharing experience, so the panel stays out of the way
	 * rather than narrating an arrangement the author cannot act on.
	 *
	 * Keyed on the state rather than on `sharing_enabled`, which is also
	 * false when the site owner turned automatic sharing off in settings.
	 * That is the author's own site policy, not another plugin's, and the
	 * per-post toggle still decides whether `backfill` reaches this post, so
	 * the panel renders there.
	 */
	if ( 'sharing_off_external' === SHARE_STATUS.state && ! sharedUrl ) {
		return null;
	}

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
			{ /* One message, never two. `panelMessage()` owns the
			     precedence and the reasoning; the component only renders
			     what it decided. A warning or error is a Notice wrapped in
			     BaseControl, which is what gives it the block inspector's
			     16px bottom margin; an informational message is a plain
			     paragraph, because it states a setting rather than a
			     problem. */ }
			{ message && 'info' === message.severity && (
				<p>{ message.message }</p>
			) }
			{ message && 'info' !== message.severity && (
				<BaseControl>
					<Notice status={ message.severity } isDismissible={ false }>
						{ 'publishError' === message.kind ? (
							<>
								{ needsReconnect
									? reconnectMessage
									: retryMessage }
								{ publishError.message && (
									<p style={ { marginBottom: 0 } }>
										<small>{ publishError.message }</small>
									</p>
								) }
							</>
						) : (
							<>
								{ message.message }{ ' ' }
								{ message.action && <ReconnectAction /> }
							</>
						) }
					</Notice>
				</BaseControl>
			) }

			{ /* The controls, whose help text always states the outcome for
			     this post. They render in every state, because the meta they
			     write is load-bearing in every state: it is what
			     `is_post_publishable()` reads, so it decides whether
			     `wp atmosphere backfill` ever touches this post, and it
			     decides again if the connection comes back or the site turns
			     automatic sharing on. Hiding them left that preference
			     invisible and unchangeable while it still had an effect. */ }
			<ToggleControl
				label={ __( 'Share this post', 'atmosphere' ) }
				checked={ enabled }
				onChange={ ( value ) =>
					setMeta( {
						...meta,
						[ DISABLED_META_KEY ]: ! value,
					} )
				}
				help={ shareHelpText(
					enabled,
					SHARE_STATUS.can_share,
					SHARE_STATUS.sharing_enabled
				) }
			/>

			{ enabled && (
				<TextareaControl
					label={ __( 'Custom Bluesky text', 'atmosphere' ) }
					value={ customText }
					onChange={ ( value ) =>
						setMeta( {
							...meta,
							[ CUSTOM_TEXT_META_KEY ]: value,
						} )
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

			{ /* A fact, not a message, so it always comes last and renders
			     whether or not sharing is on for this post: the record is up
			     either way and there is no other way to look at it. */ }
			{ sharedUrl && (
				<p>
					<ExternalLink href={ sharedUrl }>
						{ __( 'View on Bluesky', 'atmosphere' ) }
					</ExternalLink>
				</p>
			) }
		</SettingsPanel>
	);
};

registerPlugin( 'atmosphere-editor-plugin', { render: EditorPlugin } );
