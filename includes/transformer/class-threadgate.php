<?php
/**
 * Transforms a post's reply-restriction setting into an app.bsky.feed.threadgate record.
 *
 * A threadgate limits who may reply to a Bluesky post. The record shares
 * the gated post's rkey and lives in the `app.bsky.feed.threadgate`
 * collection, so it is written in the same `applyWrites` batch as the
 * post it applies to.
 *
 * The setting is stored as an array of audience tokens:
 *
 *  - empty array (the default) means "everybody can reply" and no
 *    threadgate record is written;
 *  - an array containing {@see self::AUDIENCE_NOBODY} means "nobody can
 *    reply" (a present-but-empty `allow` list);
 *  - any other combination is the set of allowed audiences.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use function Atmosphere\build_at_uri;
use function Atmosphere\get_did;

/**
 * Bluesky threadgate transformer.
 */
class Threadgate extends Base {

	/**
	 * Post meta key for the reply-restriction setting.
	 *
	 * @var string
	 */
	public const META_RESTRICTION = 'atmosphere_reply_restriction';

	/**
	 * Audience token: nobody may reply.
	 *
	 * Distinct from the everybody default (an empty setting): a post gated
	 * to "nobody" still writes a threadgate, with an empty `allow` list.
	 *
	 * @var string
	 */
	public const AUDIENCE_NOBODY = 'nobody';

	/**
	 * Audience token: people mentioned in the post may reply.
	 *
	 * @var string
	 */
	public const AUDIENCE_MENTIONED = 'mentioned';

	/**
	 * Audience token: accounts the author follows may reply.
	 *
	 * @var string
	 */
	public const AUDIENCE_FOLLOWING = 'following';

	/**
	 * Audience token: the author's followers may reply.
	 *
	 * @var string
	 */
	public const AUDIENCE_FOLLOWER = 'follower';

	/**
	 * Map an allow-rule audience token to its threadgate rule `$type`.
	 *
	 * Excludes {@see self::AUDIENCE_NOBODY}, which is the absence of any
	 * rule rather than a rule of its own.
	 *
	 * @return array<string, string> Audience token => rule `$type`.
	 */
	public static function audience_rules(): array {
		return array(
			self::AUDIENCE_MENTIONED => 'app.bsky.feed.threadgate#mentionRule',
			self::AUDIENCE_FOLLOWING => 'app.bsky.feed.threadgate#followingRule',
			self::AUDIENCE_FOLLOWER  => 'app.bsky.feed.threadgate#followerRule',
		);
	}

	/**
	 * Reduce a raw setting to the recognized audience tokens.
	 *
	 * Doubles as the meta `sanitize_callback`: drops unknown tokens and
	 * de-duplicates, and collapses any set containing "nobody" to just
	 * `[ nobody ]` so a contradictory "nobody + mentioned" can't emit
	 * allow rules.
	 *
	 * @param mixed $value Raw setting.
	 * @return string[] Sanitized audience tokens.
	 */
	public static function sanitize_restriction( $value ): array {
		if ( ! \is_array( $value ) ) {
			return array();
		}

		// Keep only string tokens so a malformed value written straight to
		// meta (bypassing the REST schema) can't reach array_intersect's
		// string cast and raise a warning.
		$tokens = \array_filter( $value, '\is_string' );

		if ( \in_array( self::AUDIENCE_NOBODY, $tokens, true ) ) {
			return array( self::AUDIENCE_NOBODY );
		}

		return \array_values(
			\array_intersect(
				\array_keys( self::audience_rules() ),
				$tokens
			)
		);
	}

	/**
	 * Whether a post is gated (a threadgate record should be written).
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return bool
	 */
	public static function is_restricted( \WP_Post $post ): bool {
		return array() !== self::restriction( $post );
	}

	/**
	 * The sanitized reply-restriction tokens stored for a post.
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return string[]
	 */
	public static function restriction( \WP_Post $post ): array {
		return self::sanitize_restriction( \get_post_meta( $post->ID, self::META_RESTRICTION, true ) );
	}

	/**
	 * Transform the post's reply-restriction into a threadgate record.
	 *
	 * @return array app.bsky.feed.threadgate record.
	 */
	public function transform(): array {
		$record = array(
			'$type'     => 'app.bsky.feed.threadgate',
			'post'      => build_at_uri( get_did(), 'app.bsky.feed.post', $this->get_rkey() ),
			'allow'     => $this->build_allow_rules(),
			'createdAt' => $this->to_iso8601( $this->object->post_date_gmt ),
		);

		/**
		 * Filters the app.bsky.feed.threadgate record before publishing.
		 *
		 * Filters that return a non-array fall back to the pre-filter
		 * record.
		 *
		 * @since unreleased
		 *
		 * @param array    $record Threadgate record.
		 * @param \WP_Post $post   WordPress post.
		 */
		$filtered = \apply_filters( 'atmosphere_transform_threadgate', $record, $this->object );

		if ( ! \is_array( $filtered ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_transform_threadgate must return an array; falling back to the unfiltered record.', 'atmosphere' ),
				'unreleased'
			);
			return $record;
		}

		return $filtered;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_collection(): string {
		return 'app.bsky.feed.threadgate';
	}

	/**
	 * {@inheritDoc}
	 *
	 * A threadgate shares the rkey of the post it gates, so this reads the
	 * post's already-reserved TID rather than minting its own. The Publisher
	 * only writes a threadgate alongside a post whose rkey is reserved, so
	 * the meta is populated by the time this runs.
	 */
	public function get_rkey(): string {
		return (string) \get_post_meta( $this->object->ID, Post::META_TID, true );
	}

	/**
	 * Build the threadgate `allow` union from the stored restriction.
	 *
	 * An empty list ("nobody can reply") is a valid, gating value: the
	 * lexicon reads a present-but-empty `allow` as "no one", distinct from
	 * an omitted `allow` (everybody), which the plugin models as the
	 * absence of a threadgate record.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function build_allow_rules(): array {
		$rules   = array();
		$mapping = self::audience_rules();

		foreach ( self::restriction( $this->object ) as $audience ) {
			if ( isset( $mapping[ $audience ] ) ) {
				$rules[] = array( '$type' => $mapping[ $audience ] );
			}
		}

		return $rules;
	}
}
