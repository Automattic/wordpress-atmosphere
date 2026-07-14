<?php
/**
 * Front-end block registration.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's front-end blocks.
 */
class Blocks {

	/**
	 * Wire block registration on `init`.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'init', array( self::class, 'register_blocks' ) );
	}

	/**
	 * Register the `atmosphere/reactions` block.
	 *
	 * Skipped when the ActivityPub plugin is active: its own reactions block
	 * already renders the same Bluesky reactions (stored as WordPress
	 * comments in the shared shape), so registering ours too would double up.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		$register = ! is_activitypub_active();

		/**
		 * Filters whether to register the Bluesky reactions block.
		 *
		 * Defaults to false when the ActivityPub plugin is active, since it
		 * already renders these reactions. Return true to force the block on
		 * regardless, or false to suppress it.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $register Whether to register the reactions block.
		 */
		if ( ! \apply_filters( 'atmosphere_register_reactions_block', $register ) ) {
			return;
		}

		\register_block_type_from_metadata( ATMOSPHERE_PLUGIN_DIR . 'build/reactions' );
	}

	/**
	 * Render the shared Interactivity-API modal markup.
	 *
	 * Ported from the ActivityPub plugin's `Blocks::render_modal()`. Drives
	 * the reactor-list popover for the reactions block (compact mode). The
	 * overlay/frame/close wiring binds to the modal store created by
	 * `src/shared/modal`.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args {
	 *     Optional. Modal arguments.
	 *
	 *     @type string $content    Modal body markup. Default empty.
	 *     @type string $id         Wrapper ID prefix for the title element. Default empty.
	 *     @type bool   $is_compact Whether the modal opens as a compact popover. Default false.
	 *     @type string $title      Static modal title. Default empty.
	 * }
	 * @return void
	 */
	public static function render_modal( $args = array() ) {
		$args = \wp_parse_args(
			$args,
			array(
				'content'    => '',
				'id'         => '',
				'is_compact' => false,
				'title'      => '',
			)
		);
		?>
		<div
			class="atmosphere-modal__overlay<?php echo \esc_attr( $args['is_compact'] ? ' compact' : '' ); ?>"
			data-wp-bind--hidden="!context.modal.isOpen"
			data-wp-watch="callbacks.handleModalEffects"
			role="dialog"
			aria-modal="<?php echo \esc_attr( $args['is_compact'] ? 'false' : 'true' ); ?>"
			hidden
		>
			<div class="atmosphere-modal__frame">
				<?php if ( ! $args['is_compact'] || ! empty( $args['title'] ) ) : ?>
					<div class="atmosphere-modal__header">
						<h2
							class="atmosphere-modal__title"
							<?php if ( ! empty( $args['id'] ) ) : ?>
								id="<?php echo \esc_attr( $args['id'] . '-title' ); ?>"
							<?php endif; ?>
						><?php echo \esc_html( $args['title'] ); ?></h2>
						<button
							type="button"
							class="atmosphere-modal__close wp-element-button"
							data-wp-on--click="actions.closeModal"
							aria-label="<?php echo \esc_attr__( 'Close dialog', 'atmosphere' ); ?>"
						>
							<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
								<path d="M13 11.8l6.1-6.3-1-1-6.1 6.2-6.1-6.2-1 1 6.1 6.3-6.5 6.7 1 1 6.5-6.6 6.5 6.6 1-1z"></path>
							</svg>
						</button>
					</div>
				<?php endif; ?>
				<div class="atmosphere-modal__content">
					<?php echo $args['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller passes pre-built, escaped markup. ?>
				</div>
			</div>
		</div>
		<?php
	}
}
