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
import { META_KEY, PREVIEW_PATH } from '../config';
import { strategyLabel, hasOverLimit } from './utils';

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
	const disabled = !! ( meta && meta[ META_KEY ] );

	const [ preview, setPreview ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( false );
	const debounce = useRef( null );

	useEffect( () => {
		if ( ! postId ) {
			return undefined;
		}

		setLoading( true );
		setError( false );

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
				},
			} )
				.then( ( result ) => {
					setPreview( result );
					setLoading( false );
				} )
				.catch( () => {
					setError( true );
					setLoading( false );
				} );
		}, 400 );

		return () => clearTimeout( debounce.current );
	}, [ postId, title, content, excerpt, status, password, disabled ] );

	if ( loading ) {
		return <Spinner />;
	}

	if ( error || ! preview ) {
		return (
			<p>{ __( 'Could not load the Bluesky preview.', 'atmosphere' ) }</p>
		);
	}

	if ( ! preview.will_publish ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ preview.reason ||
					__(
						'This post won’t be shared to Bluesky.',
						'atmosphere'
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
	render: () => (
		<PluginPrePublishPanel
			title={ __( 'Bluesky', 'atmosphere' ) }
			initialOpen
		>
			<PrePublishPanel />
		</PluginPrePublishPanel>
	),
} );
