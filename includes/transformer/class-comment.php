<?php
/**
 * Transforms an approved WordPress comment into an app.bsky.feed.post
 * reply record.
 *
 * Only comments authored by a registered WordPress user are published;
 * the Comment_Scheduler enforces that gate before instantiating this
 * transformer. Root is always the parent post's bsky record; parent
 * resolves to a previously-published sibling comment, a federated
 * reply ingested via Reaction_Sync, or falls through to the root.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Reaction_Sync;
use function Atmosphere\sanitize_text;
use function Atmosphere\truncate_text;

/**
 * Bluesky reply transformer.
 */
class Comment extends Base {

	/**
	 * Comment meta key for the bsky post TID (rkey).
	 *
	 * @var string
	 */
	public const META_TID = '_atmosphere_bsky_tid';

	/**
	 * Comment meta key for the bsky post AT-URI.
	 *
	 * @var string
	 */
	public const META_URI = '_atmosphere_bsky_uri';

	/**
	 * Comment meta key for the bsky post CID.
	 *
	 * @var string
	 */
	public const META_CID = '_atmosphere_bsky_cid';

	/**
	 * Transform the comment.
	 *
	 * @return array app.bsky.feed.post record.
	 */
	public function transform(): array {
		$comment = $this->object;

		$text = truncate_text( sanitize_text( (string) $comment->comment_content ), 300 );

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->to_iso8601( $comment->comment_date_gmt ),
			'langs'     => $this->get_langs(),
			'reply'     => $this->build_reply_ref( $comment ),
		);

		$facets = Facet::extract( $text );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		/**
		 * Filters the app.bsky.feed.post comment reply record before publishing.
		 *
		 * @param array       $record  Bsky post record.
		 * @param \WP_Comment $comment WordPress comment.
		 */
		return \apply_filters( 'atmosphere_transform_comment', $record, $comment );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_collection(): string {
		return 'app.bsky.feed.post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_rkey(): string {
		$rkey = \get_comment_meta( (int) $this->object->comment_ID, self::META_TID, true );

		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
			\update_comment_meta( (int) $this->object->comment_ID, self::META_TID, $rkey );
		}

		return $rkey;
	}

	/**
	 * Build the reply struct with root and parent refs.
	 *
	 * Root is always the WP post's bsky record. Parent is the closest
	 * resolvable ancestor: a local sibling comment that has already
	 * been published, a federated parent ingested by Reaction_Sync, or
	 * the post itself as a fallback when the parent comment can't be
	 * resolved to an AT record.
	 *
	 * @param \WP_Comment $comment WordPress comment.
	 * @return array{root: array{uri:string,cid:string}, parent: array{uri:string,cid:string}}
	 */
	private function build_reply_ref( \WP_Comment $comment ): array {
		$post_id = (int) $comment->comment_post_ID;

		$root = array(
			'uri' => (string) \get_post_meta( $post_id, Post::META_URI, true ),
			'cid' => (string) \get_post_meta( $post_id, Post::META_CID, true ),
		);

		$parent_id = (int) $comment->comment_parent;

		if ( $parent_id > 0 ) {
			$resolved = $this->resolve_parent_ref( $parent_id );
			if ( null !== $resolved ) {
				return array(
					'root'   => $root,
					'parent' => $resolved,
				);
			}
		}

		return array(
			'root'   => $root,
			'parent' => $root,
		);
	}

	/**
	 * Resolve a parent comment to its AT Protocol strong-ref.
	 *
	 * Checks local-publish meta first (this class's own keys), then
	 * falls through to Reaction_Sync meta for federated parents.
	 * Returns null when neither path yields both a URI and a CID, so
	 * the caller can fall back to the root reference — strongRef
	 * requires both fields to be set.
	 *
	 * @param int $parent_id Parent comment ID.
	 * @return array{uri:string,cid:string}|null
	 */
	private function resolve_parent_ref( int $parent_id ): ?array {
		$local_uri = \get_comment_meta( $parent_id, self::META_URI, true );
		$local_cid = \get_comment_meta( $parent_id, self::META_CID, true );
		if ( ! empty( $local_uri ) && ! empty( $local_cid ) ) {
			return array(
				'uri' => (string) $local_uri,
				'cid' => (string) $local_cid,
			);
		}

		if ( 'atproto' !== \get_comment_meta( $parent_id, Reaction_Sync::META_PROTOCOL, true ) ) {
			return null;
		}

		$federated_uri = \get_comment_meta( $parent_id, Reaction_Sync::META_SOURCE_ID, true );
		$federated_cid = \get_comment_meta( $parent_id, Reaction_Sync::META_BSKY_CID, true );
		if ( empty( $federated_uri ) || empty( $federated_cid ) ) {
			return null;
		}

		return array(
			'uri' => (string) $federated_uri,
			'cid' => (string) $federated_cid,
		);
	}
}
