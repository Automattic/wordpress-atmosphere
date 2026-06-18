import { useState, useRef } from '@wordpress/element';
import { Popover, Button } from '@wordpress/components';
import { useOptions } from '../shared/use-options';

/**
 * A row of reactor avatars for a set of reactions.
 *
 * @param {Object} props              Component props.
 * @param {Array}  props.reactions    Reaction items.
 * @param {string} props.displayStyle 'facepile' or 'compact'.
 * @return {Element|null} The avatar row, or null in compact mode.
 */
const FacepileRow = ( { reactions, displayStyle } ) => {
	const { defaultAvatarUrl } = useOptions();

	if ( displayStyle === 'compact' ) {
		return null;
	}

	return (
		<ul className="reaction-avatars">
			{ reactions.map( ( reaction, index ) => {
				const avatar = reaction.avatar || defaultAvatarUrl;

				return (
					<li key={ index }>
						<a
							href={ reaction.url }
							target="_blank"
							rel="noopener noreferrer"
						>
							<img
								src={ avatar }
								alt={ reaction.name }
								className="reaction-avatar"
								width="32"
								height="32"
								onError={ ( e ) => {
									e.target.src = defaultAvatarUrl;
								} }
							/>
						</a>
					</li>
				);
			} ) }
		</ul>
	);
};

/**
 * The dropdown list of reactions shown in the popover.
 *
 * @param {Object} props              Component props.
 * @param {Array}  props.reactions    Reaction items.
 * @param {string} props.displayStyle 'facepile' or 'compact'.
 * @return {Element} The reactions list.
 */
const ReactionList = ( { reactions, displayStyle } ) => {
	const { defaultAvatarUrl } = useOptions();

	return (
		<ul className="reactions-list">
			{ reactions.map( ( reaction, index ) => {
				const avatar = reaction.avatar || defaultAvatarUrl;
				return (
					<li key={ index } className="reaction-item">
						<a
							href={ reaction.url }
							className="reaction-item"
							target="_blank"
							rel="noopener noreferrer"
						>
							{ displayStyle === 'facepile' && (
								<img
									src={ avatar }
									alt={ reaction.name }
									width="32"
									height="32"
									onError={ ( e ) => {
										e.target.src = defaultAvatarUrl;
									} }
								/>
							) }
							<span className="reaction-name">
								{ reaction.name }
							</span>
						</a>
					</li>
				);
			} ) }
		</ul>
	);
};

/**
 * A reaction group: facepile plus a count button that opens the list.
 *
 * @param {Object} props              Component props.
 * @param {Array}  props.items        Reaction items.
 * @param {string} props.label        Group label (e.g. "9 likes").
 * @param {string} props.displayStyle 'facepile' or 'compact'.
 * @return {Element} The reaction group.
 */
const ReactionGroup = ( { items, label, displayStyle } ) => {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ buttonRef, setButtonRef ] = useState( null );
	const containerRef = useRef( null );

	const visibleItems = items.slice( 0, 20 );

	return (
		<div className="reaction-group" ref={ containerRef }>
			<FacepileRow
				reactions={ visibleItems }
				displayStyle={ displayStyle }
			/>
			<Button
				ref={ setButtonRef }
				className="reaction-label is-link"
				onClick={ () => setIsOpen( ! isOpen ) }
				aria-expanded={ isOpen }
			>
				{ label }
			</Button>
			{ isOpen && buttonRef && (
				<Popover
					anchor={ buttonRef }
					onClose={ () => setIsOpen( false ) }
					className="atmosphere-popover"
				>
					<ReactionList
						reactions={ items }
						displayStyle={ displayStyle }
					/>
				</Popover>
			) }
		</div>
	);
};

/**
 * The Reactions component — renders reaction groups from provided data.
 *
 * @param {Object}  props              Component props.
 * @param {?Object} props.reactions    Reactions data keyed by type.
 * @param {string}  props.displayStyle 'facepile' or 'compact'.
 * @return {?Element} The rendered component, or null when empty.
 */
export function Reactions( { reactions = null, displayStyle = 'facepile' } ) {
	if (
		! reactions ||
		! Object.values( reactions ).some( ( group ) => group.items?.length )
	) {
		return null;
	}

	return (
		<div className="atmosphere-reactions">
			{ Object.entries( reactions ).map( ( [ key, group ] ) => {
				if ( ! group.items?.length ) {
					return null;
				}

				return (
					<ReactionGroup
						key={ key }
						items={ group.items }
						label={ group.label }
						displayStyle={ displayStyle }
					/>
				);
			} ) }
		</div>
	);
}

// Export for testing.
export { FacepileRow };
