import { getContext, store, getElement } from '@wordpress/interactivity';
import { withSyncEvent } from '../with-sync-event';

/**
 * @typedef {Object} context
 * @property {string}  blockId         - The ID of the block.
 * @property {Object}  modal           - The modal state.
 * @property {boolean} modal.isOpen    - Whether the modal is open.
 * @property {boolean} modal.isCompact - Whether the modal is compact.
 */

/**
 * Set up a modal store with actions and callbacks.
 *
 * The Interactivity API merges all stores that share the same namespace, so
 * these actions and callbacks are added to the importing block's store.
 *
 * @param {string} namespace The interactivity namespace for the block.
 */
export function createModalStore( namespace ) {
	const { actions, callbacks } = store( namespace, {
		actions: {
			/**
			 * Open the modal.
			 *
			 * @param {Event} event Click event.
			 */
			openModal( event ) {
				const context = getContext();

				context.modal.isOpen = true;

				if ( context.modal.isCompact ) {
					// Position the compact modal relative to the button.
					setTimeout( callbacks.positionModal, 0 );
				} else {
					// Clear any inline positioning left over from compact mode.
					const blockWrapper = document.getElementById(
						context.blockId
					);
					if ( blockWrapper ) {
						const modalOverlay = blockWrapper.querySelector(
							'.atmosphere-modal__overlay'
						);
						if ( modalOverlay ) {
							[ 'top', 'left', 'right', 'bottom' ].forEach(
								( prop ) => {
									modalOverlay.style.removeProperty( prop );
								}
							);
						}
					}

					// Set up the focus trap after the modal is open.
					setTimeout( () => {
						if ( blockWrapper ) {
							const modalFrame = blockWrapper.querySelector(
								'.atmosphere-modal__frame'
							);
							if ( modalFrame ) {
								callbacks.trapFocus( modalFrame );
							}
						}
					}, 50 );
				}

				if ( typeof callbacks.onModalOpen === 'function' ) {
					callbacks.onModalOpen( event );
				}
			},

			/**
			 * Close the modal.
			 *
			 * @param {Event} event Click event.
			 */
			closeModal( event ) {
				const context = getContext();

				context.modal.isOpen = false;

				// Return focus to the button that opened the modal.
				const button = getElement();

				if (
					button.ref.dataset[ 'wpOn-Click' ] === 'actions.toggleModal'
				) {
					button.ref.focus();
				} else {
					const blockWrapper = document.getElementById(
						context.blockId
					);
					if ( blockWrapper ) {
						const openButton = blockWrapper.querySelector(
							'[data-wp-on--click="actions.toggleModal"]'
						);
						if ( openButton ) {
							openButton.focus();
						}
					}
				}

				if ( typeof callbacks.onModalClose === 'function' ) {
					callbacks.onModalClose( event );
				}
			},

			/**
			 * Toggle the modal.
			 *
			 * @param {Event} event Click event.
			 */
			toggleModal: withSyncEvent( ( event ) => {
				event?.preventDefault?.();
				const { modal } = getContext();

				if ( modal.isOpen ) {
					actions.closeModal( event );
				} else {
					actions.openModal( event );
				}
			} ),
		},

		callbacks: {
			/**
			 * Abort controller for keydown and click event listeners.
			 *
			 * @type {AbortController | null} Abort controller.
			 */
			_abortController: null,

			/**
			 * Handles modal effects like body class and event listeners.
			 * Called via data-wp-watch in the modal HTML.
			 */
			handleModalEffects() {
				const { modal } = getContext();

				if ( modal.isOpen && ! modal.isCompact ) {
					document.body.classList.add( 'modal-open' );
				} else {
					document.body.classList.remove( 'modal-open' );
				}

				if ( callbacks._abortController ) {
					callbacks._abortController.abort();
					callbacks._abortController = null;
				}

				if ( modal.isOpen ) {
					callbacks._abortController = new AbortController();
					const { signal } = callbacks._abortController;

					document.addEventListener(
						'keydown',
						callbacks.documentKeydown,
						{ signal }
					);
					document.addEventListener(
						'click',
						callbacks.documentClick,
						{ signal }
					);
				}

				return undefined;
			},

			/**
			 * Handles keydown events on the document.
			 *
			 * @param {Event}  event     Keydown event.
			 * @param {string} event.key The key that was pressed.
			 */
			documentKeydown( event ) {
				const { modal } = getContext();

				if ( modal.isOpen && event.key === 'Escape' ) {
					actions.closeModal();
				}
			},

			/**
			 * Handles click events on the document (close on outside click).
			 *
			 * @param {Event} event Click event.
			 */
			documentClick( event ) {
				const { blockId, modal } = getContext();
				if ( ! modal.isOpen ) {
					return;
				}

				const blockWrapper = document.getElementById( blockId );
				if ( ! blockWrapper ) {
					return;
				}

				const toggleButtons = blockWrapper.querySelectorAll(
					'[data-wp-on--click="actions.toggleModal"]'
				);
				for ( const toggleButton of toggleButtons ) {
					if (
						toggleButton === event.target ||
						toggleButton.contains( event.target )
					) {
						return;
					}
				}

				const modalFrame = blockWrapper.querySelector(
					'.atmosphere-modal__frame'
				);
				if ( ! modalFrame || modalFrame.contains( event.target ) ) {
					return;
				}

				actions.closeModal();
			},

			/**
			 * Positions the compact modal relative to the button that opened it.
			 */
			positionModal() {
				const { blockId } = getContext();

				const blockWrapper = document.getElementById( blockId );
				if ( ! blockWrapper ) {
					return;
				}

				const modalOverlay = blockWrapper.querySelector(
					'.atmosphere-modal__overlay'
				);
				if ( ! modalOverlay ) {
					return;
				}

				modalOverlay.style.top = '';
				modalOverlay.style.left = '';
				modalOverlay.style.right = '';
				modalOverlay.style.bottom = '';

				const buttonRect = getElement().ref.getBoundingClientRect();
				const viewportWidth = window.innerWidth;
				const blockRect = blockWrapper.getBoundingClientRect();

				const relativeTop = buttonRect.bottom - blockRect.top;
				const relativeLeft = buttonRect.left - blockRect.left;
				const spaceRight = viewportWidth - buttonRect.right;

				const position = {
					top: `${ relativeTop + 8 }px`,
					left: `${ relativeLeft - 2 }px`,
				};

				if ( spaceRight < 250 ) {
					position.left = 'auto';
					position.right = `${
						blockRect.right - buttonRect.right
					}px`;
				}

				Object.assign( modalOverlay.style, position );
			},

			/**
			 * Traps focus within the specified element.
			 *
			 * @param {Element} element The element to trap focus within.
			 */
			trapFocus( element ) {
				const focusableElements = element.querySelectorAll(
					'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input[type="text"]:not([disabled]):not([readonly]), input[type="radio"]:not([disabled]), input[type="checkbox"]:not([disabled]), select:not([disabled])'
				);
				const firstFocusableElement = focusableElements[ 0 ];
				const lastFocusableElement =
					focusableElements[ focusableElements.length - 1 ];

				if ( ! firstFocusableElement ) {
					return;
				}

				if (
					firstFocusableElement.classList.contains(
						'atmosphere-modal__close'
					) &&
					focusableElements.length > 1
				) {
					focusableElements[ 1 ].focus();
				} else {
					firstFocusableElement.focus();
				}

				// Replace any handler from a previous open so listeners don't
				// accumulate across reopenings.
				if ( element._atmTrapFocus ) {
					element.removeEventListener(
						'keydown',
						element._atmTrapFocus
					);
				}

				element._atmTrapFocus = function ( event ) {
					if (
						event.key !== 'Tab' &&
						event.keyCode !== 9 /* KEYCODE_TAB */
					) {
						return;
					}

					const activeEl = element.ownerDocument.activeElement;
					if ( event.shiftKey ) {
						if ( activeEl === firstFocusableElement ) {
							lastFocusableElement.focus();
							event.preventDefault();
						}
					} else if ( activeEl === lastFocusableElement ) {
						firstFocusableElement.focus();
						event.preventDefault();
					}
				};

				element.addEventListener( 'keydown', element._atmTrapFocus );
			},
		},
	} );
}
