<?php
/**
 * Server-side render of the `atmosphere/reactions` block.
 *
 * Renders the Bluesky likes and reposts a post received (synced as WordPress
 * comments by {@see \Atmosphere\Reaction_Sync}) as facepile rows. Each row's
 * count opens a popover listing every reactor. Mirrors the ActivityPub
 * plugin's reactions block, minus the remote-interaction action buttons (AT
 * Protocol exposes only a compose intent, no like/repost intent).
 *
 * Outputs nothing when the post has no such reactions, is not publicly
 * viewable, or in a feed context.
 *
 * @package Atmosphere
 *
 * @var array     $attributes Block attributes.
 * @var string    $content    Block inner content (the optional title heading).
 * @var \WP_Block $block      Block instance.
 */

use Atmosphere\Blocks;

if ( is_feed() ) {
	return;
}

// Default display style follows the site's avatar setting.
$atmosphere_default_style = get_option( 'show_avatars', true ) ? 'facepile' : 'compact';

$atmosphere_attributes = wp_parse_args(
	$attributes ?? array(), // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
	array(
		'align'        => null,
		'displayStyle' => $atmosphere_default_style,
	)
);

$atmosphere_block   = $block ?? null;     // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
$atmosphere_content = $content ?? '';     // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

// Resolve the title heading from inner blocks; hide it when empty.
if ( $atmosphere_block && ! empty( $atmosphere_block->parsed_block['innerBlocks'] ) ) {
	$atmosphere_content = implode( PHP_EOL, wp_list_pluck( $atmosphere_block->parsed_block['innerBlocks'], 'innerHTML' ) );
	if ( '' === wp_strip_all_tags( $atmosphere_content ) ) {
		$atmosphere_content = '';
	}
}

$atmosphere_post_id = ( $atmosphere_block && isset( $atmosphere_block->context['postId'] ) )
	? (int) $atmosphere_block->context['postId']
	: (int) get_the_ID();

// Don't leak reaction metadata for posts that aren't publicly viewable.
if ( ! $atmosphere_post_id || ! is_post_publicly_viewable( $atmosphere_post_id ) ) {
	return;
}

$atmosphere_block_id = 'atmosphere-reactions-block-' . wp_unique_id();

/*
 * Derive the effective style from the block-style class (the source of
 * truth). Auto-hooked blocks arrive without one, so fall back to the
 * avatar-setting default and stamp the matching class on the wrapper.
 */
$atmosphere_class = $atmosphere_attributes['className'] ?? '';
if ( false !== strpos( $atmosphere_class, 'is-style-compact' ) ) {
	$atmosphere_show_avatars = false;
} elseif ( false !== strpos( $atmosphere_class, 'is-style-facepile' ) ) {
	$atmosphere_show_avatars = true;
} else {
	$atmosphere_show_avatars            = 'facepile' === $atmosphere_default_style;
	$atmosphere_attributes['className'] = trim( $atmosphere_class . ' is-style-' . $atmosphere_default_style );
}

// Fetch reactions, one entry per type.
$atmosphere_reactions = array();

foreach ( array( 'like', 'repost' ) as $atmosphere_type ) {
	$atmosphere_comments = get_comments(
		array(
			'post_id' => $atmosphere_post_id,
			'type'    => $atmosphere_type,
			'status'  => 'approve',
			'parent'  => 0,
		)
	);

	if ( empty( $atmosphere_comments ) ) {
		continue;
	}

	$atmosphere_count = count( $atmosphere_comments );

	if ( 'like' === $atmosphere_type ) {
		/* translators: %s: number of likes. */
		$atmosphere_label = sprintf( _n( '%s like', '%s likes', $atmosphere_count, 'atmosphere' ), number_format_i18n( $atmosphere_count ) );
	} else {
		/* translators: %s: number of reposts. */
		$atmosphere_label = sprintf( _n( '%s repost', '%s reposts', $atmosphere_count, 'atmosphere' ), number_format_i18n( $atmosphere_count ) );
	}

	$atmosphere_reactions[ $atmosphere_type ] = array(
		'label' => $atmosphere_label,
		'count' => $atmosphere_count,
		'items' => array_map(
			static function ( $comment ) {
				return array(
					'name'   => html_entity_decode( $comment->comment_author, ENT_QUOTES, 'UTF-8' ),
					'url'    => $comment->comment_author_url,
					'avatar' => get_avatar_url( $comment ),
				);
			},
			$atmosphere_comments
		),
	);
}

if ( empty( $atmosphere_reactions ) ) {
	echo '<!-- Reactions block: No reactions found. -->';
	return;
}

// A neutral gray default avatar for images that fail to load.
$atmosphere_default_avatar = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Ccircle cx='32' cy='32' r='32' fill='%23cfcfcf'/%3E%3C/svg%3E";

wp_interactivity_config(
	'atmosphere/reactions',
	array(
		'defaultAvatarUrl' => $atmosphere_default_avatar,
		'namespace'        => 'atmosphere/v1',
	)
);

wp_interactivity_state(
	'atmosphere/reactions',
	array( 'reactions' => array( $atmosphere_post_id => $atmosphere_reactions ) )
);

// Render a bounded subset (most recent first) for the initial facepile.
$atmosphere_reactions = array_map(
	static function ( $reaction ) use ( $atmosphere_attributes ) {
		$limit = 20;
		if ( 'wide' === $atmosphere_attributes['align'] ) {
			$limit = 40;
		} elseif ( 'full' === $atmosphere_attributes['align'] ) {
			$limit = 60;
		}

		$reaction['items'] = array_slice( array_reverse( $reaction['items'] ), 0, $limit );

		return $reaction;
	},
	$atmosphere_reactions
);

$atmosphere_context = array(
	'blockId'   => $atmosphere_block_id,
	'modal'     => array(
		'isCompact' => true,
		'isOpen'    => false,
		'items'     => array(),
		'title'     => '',
	),
	'postId'    => $atmosphere_post_id,
	'reactions' => $atmosphere_reactions,
);

$atmosphere_type_labels = array(
	'like'   => __( 'likes', 'atmosphere' ),
	'repost' => __( 'reposts', 'atmosphere' ),
);

// Build the facepile + count buttons.
ob_start();
?>
<div class="atmosphere-reactions">
	<?php foreach ( $atmosphere_reactions as $atmosphere_type => $atmosphere_reaction ) : ?>
		<?php /* translators: %s: reaction type (likes/reposts). */ ?>
		<?php $atmosphere_aria = sprintf( __( 'View all %s', 'atmosphere' ), $atmosphere_type_labels[ $atmosphere_type ] ?? $atmosphere_type ); ?>
	<div class="reaction-group" data-reaction-type="<?php echo esc_attr( $atmosphere_type ); ?>">
		<?php if ( $atmosphere_show_avatars ) : ?>
		<ul class="reaction-avatars">
			<template data-wp-each="context.reactions.<?php echo esc_attr( $atmosphere_type ); ?>.items">
				<li>
					<a
						data-wp-bind--href="context.item.url"
						data-wp-bind--title="context.item.name"
						target="_blank"
						rel="noopener noreferrer"
					>
						<img
							data-wp-bind--src="context.item.avatar"
							data-wp-bind--alt="context.item.name"
							data-wp-on--error="callbacks.setDefaultAvatar"
							class="reaction-avatar"
							height="32"
							width="32"
							src=""
							alt=""
						/>
					</a>
				</li>
			</template>
		</ul>
		<?php endif; ?>
		<button
			class="reaction-label has-text-color has-background"
			data-reaction-type="<?php echo esc_attr( $atmosphere_type ); ?>"
			data-wp-on--click="actions.toggleModal"
			type="button"
			aria-label="<?php echo esc_attr( $atmosphere_aria ); ?>"
		>
			<?php echo esc_html( $atmosphere_reaction['label'] ); ?>
		</button>
	</div>
	<?php endforeach; ?>
</div>
<?php
$atmosphere_reactions_content = ob_get_clean();

// Build the compact modal (reactor list).
ob_start();
?>
<div data-wp-bind--hidden="!context.modal.isCompact">
	<ul class="reactions-list">
		<template data-wp-each="context.modal.items">
			<li class="reaction-item">
				<a data-wp-bind--href="context.item.url" target="_blank" rel="noopener noreferrer">
					<?php if ( $atmosphere_show_avatars ) : ?>
					<img
						alt=""
						data-wp-bind--alt="context.item.name"
						data-wp-bind--src="context.item.avatar"
						data-wp-on--error="callbacks.setDefaultAvatar"
						src=""
					/>
					<?php endif; ?>
					<span class="reaction-name" data-wp-text="context.item.name"></span>
				</a>
			</li>
		</template>
	</ul>
</div>
<?php
$atmosphere_modal_content = ob_get_clean();

ob_start();
Blocks::render_modal(
	array(
		'content'    => $atmosphere_modal_content,
		'is_compact' => true,
	)
);
$atmosphere_inner = $atmosphere_reactions_content . ob_get_clean();

$atmosphere_wrapper = get_block_wrapper_attributes(
	array(
		'id'                  => $atmosphere_block_id,
		'class'               => $atmosphere_attributes['className'] ?? '',
		'data-wp-interactive' => 'atmosphere/reactions',
		'data-wp-context'     => wp_json_encode( $atmosphere_context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ),
		'data-wp-init'        => 'callbacks.initReactions',
	)
);
?>
<div <?php echo $atmosphere_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped attributes. ?>>
	<?php echo $atmosphere_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner block (heading) markup, sanitized by the editor. ?>
	<?php echo $atmosphere_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above. ?>
</div>
<?php
