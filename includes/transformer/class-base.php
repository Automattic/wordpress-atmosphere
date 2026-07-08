<?php
/**
 * Abstract base for AT Protocol record transformers.
 *
 * Each concrete transformer converts a WordPress object (post, site)
 * into a specific AT Protocol lexicon record.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Mention;
use function Atmosphere\build_at_uri;
use function Atmosphere\get_did;
use function Atmosphere\sanitize_text;
use function Atmosphere\to_iso8601;

/**
 * Abstract base transformer.
 */
abstract class Base {

	/**
	 * Maximum length of an `app.bsky.feed.post` `text` field.
	 *
	 * The `app.bsky.feed.post` lexicon caps `text` at 300 graphemes. The
	 * transformers approximate that with `mb_strlen` (code points) — every
	 * grapheme is at least one code point, so a code-point cap never exceeds
	 * the grapheme limit. Shared by the post and comment transformers, which
	 * both emit Bluesky records bounded by this cap.
	 *
	 * @var int
	 */
	public const BLUESKY_MAX_GRAPHEMES = 300;

	/**
	 * The WordPress object being transformed.
	 *
	 * @var mixed
	 */
	protected mixed $object;

	/**
	 * Constructor.
	 *
	 * @param mixed $object WordPress object.
	 */
	public function __construct( mixed $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		$this->object = $object;
	}

	/**
	 * Produce the AT Protocol record array.
	 *
	 * @return array
	 */
	abstract public function transform(): array;

	/**
	 * Collection NSID this record belongs to.
	 *
	 * @return string
	 */
	abstract public function get_collection(): string;

	/**
	 * Record key (TID).
	 *
	 * @return string
	 */
	abstract public function get_rkey(): string;

	/**
	 * Records this transformer would publish, for the `?atproto` preview.
	 *
	 * Defaults to the single record produced by {@see self::transform()}.
	 * Transformers that fan a post out into multiple records (e.g. a
	 * Bluesky thread) override this to return them in publish order.
	 *
	 * Implementations MUST be read-only: the preview is served on a GET
	 * request, so no blob uploads, meta writes, or rkey reservations.
	 * Override this method (like Document and Publication do) when
	 * `transform()` has publish-time side effects.
	 *
	 * @return array<int,array> Ordered list of record arrays, in publish order.
	 */
	public function get_preview_records(): array {
		return array( $this->transform() );
	}

	/**
	 * Build the full AT-URI for this record.
	 *
	 * @return string
	 */
	public function get_uri(): string {
		return build_at_uri(
			get_did(),
			$this->get_collection(),
			$this->get_rkey()
		);
	}

	/**
	 * WordPress locale as BCP-47 language tag array.
	 *
	 * @return string[]
	 */
	protected function get_langs(): array {
		return array( \substr( \get_locale(), 0, 2 ) );
	}

	/**
	 * Convert a GMT datetime string to ISO 8601.
	 *
	 * @param string $datetime GMT datetime.
	 * @return string
	 */
	protected function to_iso8601( string $datetime ): string {
		return to_iso8601( $datetime );
	}

	/**
	 * Collect tags from post taxonomies (max 8, no "uncategorized").
	 *
	 * @param \WP_Post $post Post object.
	 * @return string[]
	 */
	protected function collect_tags( \WP_Post $post ): array {
		$tags = array();

		$post_tags = \get_the_tags( $post->ID );
		if ( $post_tags ) {
			foreach ( $post_tags as $t ) {
				$tags[] = $t->name;
			}
		}

		$categories = \get_the_category( $post->ID );
		if ( $categories ) {
			foreach ( $categories as $cat ) {
				if ( 'uncategorized' !== $cat->slug ) {
					$tags[] = $cat->name;
				}
			}
		}

		return \array_slice( \array_unique( $tags ), 0, 8 );
	}

	/**
	 * Get a short plain-text excerpt for a post.
	 *
	 * @param \WP_Post $post      Post object.
	 * @param int      $word_limit Words to keep.
	 * @return string
	 */
	protected function get_excerpt( \WP_Post $post, int $word_limit = 30 ): string {
		if ( ! empty( $post->post_excerpt ) ) {
			return sanitize_text( $post->post_excerpt );
		}

		return \wp_trim_words( sanitize_text( $post->post_content ), $word_limit, '...' );
	}

	/**
	 * Whether post-derived record fields must be redacted.
	 *
	 * Transformer output can be written to a remote PDS, so this check
	 * must not use post_password_required(), which depends on the
	 * current request's unlock cookie.
	 *
	 * Intentionally narrower than `Atmosphere\is_post_publishable()`:
	 * direct transformer callers may transform unsupported post types,
	 * but protected/non-published fields still must not be serialized.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	protected function is_post_redacted( \WP_Post $post ): bool {
		if ( '' !== (string) $post->post_password ) {
			return true;
		}

		return 'publish' !== \get_post_status( $post );
	}

	/**
	 * Cache of `render_post_content_plain()` output keyed by post ID.
	 *
	 * Per-instance memoization; `the_content` filter chains can be
	 * expensive, and long-form composition may touch a post's plain
	 * text from multiple helpers inside a single publish pass.
	 *
	 * @var array<int,string>
	 */
	private array $plain_content_cache = array();

	/**
	 * Per-instance memoization of the rendered-HTML render (linkification off).
	 *
	 * @var array<int,string>
	 */
	private array $html_content_cache = array();

	/**
	 * Render a post's content to HTML, with mention linkification suppressed.
	 *
	 * Runs the `the_content` filter chain once and caches the result. Mention
	 * linkification is suppressed for the same reason {@see self::render_post_content_plain()}
	 * suppresses it: this render feeds the Bluesky post-text composition, where
	 * an `@handle` rendered as an `<a>` would become a `#link` facet (no
	 * notification) instead of a `#mention` facet (which notifies). The rich
	 * document / front-end render uses a separate, un-guarded `the_content` call.
	 *
	 * Callers that need mention/context awareness (which tags a handle sits
	 * inside) work from this HTML; callers that only need plain text go through
	 * {@see self::render_post_content_plain()}, which strips tags from it.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	protected function render_post_content_html( \WP_Post $post ): string {
		if ( isset( $this->html_content_cache[ $post->ID ] ) ) {
			return $this->html_content_cache[ $post->ID ];
		}

		$html = Mention::without_links(
			static fn() => \apply_filters( 'the_content', $post->post_content ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
		);

		$this->html_content_cache[ $post->ID ] = $html;

		return $html;
	}

	/**
	 * Render a post's content to plain text.
	 *
	 * Runs the_content filter, strips tags, decodes entities, and
	 * collapses whitespace. Shared by short-form Bluesky post
	 * composition and the document record's textContent field.
	 * Memoized per post ID to avoid re-running the filter chain.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	protected function render_post_content_plain( \WP_Post $post ): string {
		if ( isset( $this->plain_content_cache[ $post->ID ] ) ) {
			return $this->plain_content_cache[ $post->ID ];
		}

		$plain = sanitize_text( $this->render_post_content_html( $post ) );

		$this->plain_content_cache[ $post->ID ] = $plain;

		return $plain;
	}

	/**
	 * Validate an open-union extension object.
	 *
	 * @param mixed  $value   Filter return value.
	 * @param string $method  Method name for _doing_it_wrong().
	 * @param string $message Error message.
	 * @return array|null Valid union object, or null when omitted/invalid.
	 */
	protected static function validate_open_union( $value, string $method, string $message ): ?array {
		if ( null === $value || array() === $value ) {
			return null;
		}

		if ( ! \is_array( $value ) || empty( $value['$type'] ) || ! \is_string( $value['$type'] ) ) {
			\_doing_it_wrong( \esc_html( $method ), \esc_html( $message ), 'unreleased' );
			return null;
		}

		return $value;
	}

	/**
	 * Validate a com.atproto.label.defs#selfLabels object.
	 *
	 * @param mixed  $value  Filter return value.
	 * @param string $method Method name for _doing_it_wrong().
	 * @return array|null Valid self-labels object, or null when omitted/invalid.
	 */
	protected static function validate_self_labels( $value, string $method ): ?array {
		if ( null === $value || array() === $value ) {
			return null;
		}

		if (
			! \is_array( $value )
			|| 'com.atproto.label.defs#selfLabels' !== ( $value['$type'] ?? '' )
			|| ! isset( $value['values'] )
			|| ! \is_array( $value['values'] )
		) {
			\_doing_it_wrong(
				\esc_html( $method ),
				\esc_html__( 'Self-label filters must return a com.atproto.label.defs#selfLabels object with a values array; omitting the labels field.', 'atmosphere' ),
				'unreleased'
			);
			return null;
		}

		foreach ( $value['values'] as $label ) {
			if ( ! \is_array( $label ) || empty( $label['val'] ) || ! \is_string( $label['val'] ) ) {
				\_doing_it_wrong(
					\esc_html( $method ),
					\esc_html__( 'Self-label values must be arrays with a non-empty string val field; omitting the labels field.', 'atmosphere' ),
					'unreleased'
				);
				return null;
			}
		}

		return $value;
	}
}
