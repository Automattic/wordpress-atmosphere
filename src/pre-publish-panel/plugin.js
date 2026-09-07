/**
 * ATmosphere pre-publish federation panel.
 *
 * Registers a PluginPrePublishPanel that tells the author, before they
 * publish, whether the post will go to Bluesky, which strategy will run,
 * and how the body measures against Bluesky's 300-character limit. The
 * "what will actually happen" answer comes from the admin REST projector,
 * which runs the real server-side transformer, so it never drifts from
 * publish.
 */

import { PluginPrePublishPanel, store as editorStore } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Notice, Spinner } from '@wordpress/components';
import {
	DISABLED_META_KEY,
	CUSTOM_TEXT_META_KEY,
	PREVIEW_PATH,
	SHARE_STATUS,
} from '../config';
import { ReconnectAction } from '../shared/reconnect-notice';
import { strategyLabel, hasOverLimit, isAuthError } from './utils';

/**
 * Jetpack's whole-post newsletter access meta key.
 *
 * Read straight from the editor so the preview tracks a subscriber/paid
 * visibility change before it is saved; the server only reads this from the
 * last save otherwise. When the meta key is registered but empty, the level is
 * sent as an explicit 'everybody' so flipping a saved-gated post back to
 * public previews as public. When the key is absent (Jetpack inactive, or a
 * post type without the meta), no level is sent and the server falls back to
 * the saved value, failing closed.
 *
 * @type {string}
 */
const JETPACK_ACCESS_META_KEY = '_jetpack_newsletter_access';

/**
 * The pre-publish panel body.
 *
 * @return {React.JSX.Element} Panel.
 */
function PrePublishPanel() {
	const { postId, postType, title, content, excerpt, status, password } =
		useSelect( ( select ) => {
			const editor = select( editorStore );
			return {
				postId: editor.getCurrentPostId(),
				postType: editor.getCurrentPostType(),
				title: editor.getEditedPostAttribute( 'title' ),
				content: editor.getEditedPostContent(),
				excerpt: editor.getEditedPostAttribute( 'excerpt' ),
				status: editor.getEditedPostAttribute( 'status' ),
				password: editor.getEditedPostAttribute( 'password' ),
			};
		}, [] );

	const [ meta ] = useEntityProp( 'postType', postType, 'meta' );
	const disabled = !! ( meta && meta[ DISABLED_META_KEY ] );
	const customText = ( meta && meta[ CUSTOM_TEXT_META_KEY ] ) || '';
	const accessLevel =
		meta && JETPACK_ACCESS_META_KEY in meta
			? meta[ JETPACK_ACCESS_META_KEY ] || 'everybody'
			: undefined;

	const [ preview, setPreview ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const debounce = useRef( null );

	useEffect( () => {
		if ( ! postId ) {
			return undefined;
		}

		setLoading( true );
		setError( null );

		// Debounce so each keystroke doesn't fire a projector request.
		clearTimeout( debounce.current );
		debounce.current = setTimeout( () => {
			apiFetch( {
				path: PREVIEW_PATH,
				method: 'POST',
				data: {
					id: postId,
					title,
					content,
					excerpt,
					status,
					password,
					disabled,
					customText,
					accessLevel,
				},
			} )
				.then( ( result ) => {
					setPreview( result );
					setLoading( false );
				} )
				.catch( ( err ) => {
					// Drop any preview from an earlier keystroke so a failed
					// refresh never leaves stale text on screen, then keep the
					// error so the message can distinguish a permission failure
					// from a transient one, and log it for support reports.
					setPreview( null );
					// eslint-disable-next-line no-console -- Aid debugging.
					console.error(
						'ATmosphere pre-publish preview failed:',
						err
					);
					setError( err );
					setLoading( false );
				} );
		}, 400 );

		return () => clearTimeout( debounce.current );
	}, [
		postId,
		title,
		content,
		excerpt,
		status,
		password,
		disabled,
		customText,
		accessLevel,
	] );

	if ( loading ) {
		return <Spinner />;
	}

	if ( error ) {
		return (
			<p>
				{ isAuthError( error )
					? __(
							'You don’t have permission to preview this post.',
							'atmosphere'
					  )
					: __(
							'Could not load the Bluesky preview. Please try again.',
							'atmosphere'
					  ) }
			</p>
		);
	}

	if ( ! preview ) {
		return (
			<p>{ __( 'Could not load the Bluesky preview.', 'atmosphere' ) }</p>
		);
	}

	/*
	 * A dead connection is the one non-publishing reason someone can act on
	 * right now, so it renders as a warning with a way out. Every other
	 * reason (sharing off, private post, unsupported type) is a statement of
	 * fact and stays at info level.
	 */
	if ( ! preview.will_publish ) {
		return (
			<Notice
				status={ preview.needs_reconnect ? 'warning' : 'info' }
				isDismissible={ false }
			>
				{ preview.reason ||
					__(
						'This post won’t be shared to Bluesky.',
						'atmosphere'
					) }
				{ preview.needs_reconnect && (
					<>
						{ ' ' }
						<ReconnectAction />
					</>
				) }
			</Notice>
		);
	}

	const records = preview.records || [];

	return (
		<div className="atmosphere-pre-publish">
			<p>
				{ sprintf(
					/* translators: %s: the publishing strategy, e.g. "Short note" or "Link card". */
					__(
						'This post will be shared to Bluesky as: %s.',
						'atmosphere'
					),
					strategyLabel( preview.strategy )
				) }
			</p>

			{ records.length === 1 && (
				<p
					className={
						records[ 0 ].over_limit
							? 'atmosphere-over-limit'
							: undefined
					}
				>
					{ sprintf(
						/* translators: 1: character count, 2: the 300-character limit. */
						__( '%1$d / %2$d characters', 'atmosphere' ),
						records[ 0 ].characters,
						preview.limit
					) }
				</p>
			) }

			{ records.length > 1 && (
				<ul>
					{ records.map( ( record, index ) => (
						<li
							key={ index }
							className={
								record.over_limit
									? 'atmosphere-over-limit'
									: undefined
							}
						>
							{ sprintf(
								/* translators: 1: post number in the thread, 2: character count, 3: the limit. */
								__(
									'Post %1$d: %2$d / %3$d characters',
									'atmosphere'
								),
								index + 1,
								record.characters,
								preview.limit
							) }
						</li>
					) ) }
				</ul>
			) }

			{ hasOverLimit( records ) && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Your text is over Bluesky’s limit and will be shortened when published.',
						'atmosphere'
					) }
				</Notice>
			) }
		</div>
	);
}

registerPlugin( 'atmosphere-pre-publish-panel', {
	render: () => {
		/*
		 * A host plugin owns the sharing experience in connection-only mode,
		 * so ATmosphere says nothing about sharing anywhere in the editor,
		 * here included. The document panel is silent in the same state.
		 *
		 * Only the UI hides. The REST projector still answers in full, since
		 * anything else asking it deserves the reason rather than silence.
		 */
		if ( 'sharing_off_external' === SHARE_STATUS.state ) {
			return null;
		}

		return (
			<PluginPrePublishPanel
				title={ __( 'Bluesky', 'atmosphere' ) }
				initialOpen
			>
				<PrePublishPanel />
			</PluginPrePublishPanel>
		);
	},
} );
