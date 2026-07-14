import {
	getContext,
	getElement,
	store,
	withScope,
	getConfig,
} from '@wordpress/interactivity';
import './view-style.scss';
import { createModalStore } from '../shared/modal';

/* global ResizeObserver */

createModalStore( 'atmosphere/reactions' );

/**
 * @typedef {Object} context
 * @property {string}  blockId         The block ID.
 * @property {Object}  modal           The modal state.
 * @property {boolean} modal.isCompact Whether the modal is compact.
 * @property {boolean} modal.isOpen    Whether the modal is open.
 * @property {Object}  modal.items     The items to display in the modal.
 * @property {string}  postId          The post ID.
 * @property {Object}  reactions       Reactions data, keyed by reaction type.
 */

const { callbacks, state } = store( 'atmosphere/reactions', {
	callbacks: {
		/**
		 * Initialize the block: observe each group to recalc avatar count.
		 */
		initReactions() {
			const resizeObserver = new ResizeObserver(
				withScope( callbacks.calculateVisibleAvatars )
			);
			getElement()
				.ref.querySelectorAll( '.reaction-group' )
				.forEach( ( group ) => {
					resizeObserver.observe( group );
				} );

			return () => {
				resizeObserver.disconnect();
			};
		},

		/**
		 * Calculate and set how many avatars fit in each group's width.
		 */
		calculateVisibleAvatars() {
			const { postId } = getContext();

			const AVATAR_WIDTH = 32;
			const AVATAR_OVERLAP = 10;
			const EFFECTIVE_AVATAR_WIDTH = AVATAR_WIDTH - AVATAR_OVERLAP;
			const BUTTON_GAP = 12;

			const reactionTypes =
				state.reactions && state.reactions[ postId ]
					? Object.keys( state.reactions[ postId ] )
					: [];

			reactionTypes.forEach( ( reactionType ) => {
				if (
					! state.reactions?.[ postId ]?.[ reactionType ]?.items
						?.length
				) {
					return;
				}

				getElement()
					.ref.querySelectorAll(
						`.reaction-group[data-reaction-type="${ reactionType }"]`
					)
					.forEach( ( container ) => {
						const label =
							container.querySelector( '.reaction-label' );
						const labelWidth = label.offsetWidth || 0;
						const availableWidth =
							container.offsetWidth - labelWidth - BUTTON_GAP;

						let maxAvatars = 1;
						if ( availableWidth > AVATAR_WIDTH ) {
							maxAvatars += Math.floor(
								( availableWidth - AVATAR_WIDTH ) /
									EFFECTIVE_AVATAR_WIDTH
							);
						}

						const items =
							state.reactions[ postId ][ reactionType ].items;
						const visibleCount = Math.min(
							maxAvatars,
							items.length
						);

						const avatarsList =
							container.querySelector( '.reaction-avatars' );
						if ( avatarsList ) {
							avatarsList
								.querySelectorAll( 'li' )
								.forEach( ( item, index ) => {
									if ( index < visibleCount ) {
										item.removeAttribute( 'hidden' );
									} else {
										item.setAttribute( 'hidden', 'hidden' );
									}
								} );
						}
					} );
			} );
		},

		/**
		 * Fall back to the default avatar when an image fails to load.
		 *
		 * @param {Object} event The error event.
		 */
		setDefaultAvatar( event ) {
			event.target.src = getConfig().defaultAvatarUrl;
		},

		/**
		 * On modal open, populate it with the clicked group's reactors.
		 */
		onModalOpen() {
			const context = getContext();

			if ( context.modal.isCompact ) {
				const reactionType = getElement().ref.dataset.reactionType;
				context.modal.items =
					state.reactions?.[ context.postId ]?.[ reactionType ]
						?.items || [];
			}
		},

		/**
		 * On modal close, reset to compact mode for next use.
		 */
		onModalClose() {
			const context = getContext();
			context.modal.isCompact = true;
		},
	},
} );
