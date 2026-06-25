<?php
/**
 * Rich-text facet extraction for AT Protocol posts.
 *
 * Facets annotate byte ranges in a plain-text string with semantic
 * features such as links, @-mentions, and #hashtags.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Resolver;

use function Atmosphere\get_connection;
use function Atmosphere\appview_url;

/**
 * Extracts facets from plain text.
 */
class Facet {

	/**
	 * Regex matching an AT Protocol `@handle.tld` mention.
	 *
	 * Capture group 1 is the bare handle (no leading `@`). Requires at
	 * least two dot-separated labels, mirroring DNS-name handle syntax.
	 * This is the single source of truth for "what is a Bluesky mention":
	 * {@see self::mentions()}, {@see self::resolve_handles()}, and the
	 * display-side {@see \Atmosphere\Mention::linkify()} all share it so the
	 * publish path and the front-end linkifier can never drift apart.
	 *
	 * The leading `(?<![\w@.])` boundary skips the domain half of an email
	 * address (`bob@example.com`) or an ActivityPub `@user@domain.tld`
	 * handle. The trailing `(?![\w@])` boundary rejects a WebFinger handle
	 * whose user half is itself domain-shaped (`@notiz.blog@notiz.blog`):
	 * without it the first `@notiz.blog` would be mistaken for a standalone
	 * Bluesky handle. Both boundaries keep these false positives from driving
	 * real DNS/HTTP resolution or minting a bogus `#mention` facet. A `.` is
	 * deliberately left out of the trailing class so a handle ending a
	 * sentence (`@bsky.app.`) still matches.
	 *
	 * @var string
	 */
	public const MENTION_PATTERN = '/(?<![\w@.])@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+)(?![\w@])/u';

	/**
	 * Request-scoped memo of handle => DID resolutions.
	 *
	 * Broadening mention collection to the full post body resolves the same
	 * handle more than once per publish (the carry-over detection pass and
	 * the final {@see self::extract()} on the composed text). Memoizing the
	 * resolved DID (or the empty-string miss) keeps that to one lookup per
	 * distinct handle per request, bounding duplicate DNS/HTTP egress. Keyed
	 * by lowercased handle.
	 *
	 * The self-handle short-circuit is intentionally evaluated outside this
	 * cache, since it depends on the live connection option.
	 *
	 * @var array<string,string>
	 */
	private static array $resolution_cache = array();

	/**
	 * Extract all facet types from a piece of text.
	 *
	 * @param string $text Plain text.
	 * @return array Sorted array of facet objects.
	 */
	public static function extract( string $text ): array {
		$facets = \array_merge(
			self::links( $text ),
			self::mentions( $text ),
			self::hashtags( $text )
		);

		\usort(
			$facets,
			static fn( $a, $b ) => $a['index']['byteStart'] <=> $b['index']['byteStart']
		);

		return $facets;
	}

	/**
	 * Build facets for known URLs occurring in the text.
	 *
	 * @param string   $text Plain text.
	 * @param string[] $urls URLs to look for.
	 * @return array Facet array.
	 */
	public static function for_urls( string $text, array $urls ): array {
		$facets = array();

		foreach ( $urls as $url ) {
			$pos = \strpos( $text, $url );

			if ( false === $pos ) {
				continue;
			}

			$byte_start = \strlen( \mb_substr( $text, 0, $pos ) );

			$facets[] = array(
				'index'    => array(
					'byteStart' => $byte_start,
					'byteEnd'   => $byte_start + \strlen( $url ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $url,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Reassemble rich text by applying facets back onto a plain-text string.
	 *
	 * The inverse of {@see Facet::extract()}. AT Protocol stores a post's
	 * `text` as the *display* string — Bluesky truncates long URLs (e.g.
	 * `bsky.app/profile/jere...`) — while the real target lives in the
	 * `facets` array, which maps a byte range in `text` to a feature
	 * (link `uri`, mention `did`, or `tag`). Without this step an imported
	 * comment keeps only the lossy display string.
	 *
	 * Link and mention features become anchors; tags become hashtag-search
	 * links; the byte ranges between facets are copied through untouched.
	 * The result is an HTML fragment intended to be passed through
	 * `wp_kses_post()` by the caller (as the reaction-sync path does), so
	 * only the generated `href` attributes are escaped here.
	 *
	 * @since unreleased
	 *
	 * @param string $text   Plain-text display string from the record.
	 * @param array  $facets Facet array from the record, as stored on the PDS.
	 * @return string Text with facets resolved to anchors.
	 */
	public static function apply( string $text, array $facets ): string {
		/*
		 * Facets come from untrusted PDS JSON, so every nested shape is
		 * treated as unknown: drop facet entries that aren't arrays before
		 * touching them, otherwise a scalar entry fatals on array access.
		 */
		$facets = \array_filter( $facets, '\is_array' );

		if ( empty( $facets ) ) {
			return $text;
		}

		\usort(
			$facets,
			static fn( $a, $b ) => self::byte_start( $a ) <=> self::byte_start( $b )
		);

		// Facet indexes are UTF-8 byte offsets, so splice in byte space.
		$length = \strlen( $text );
		$cursor = 0;
		$result = '';

		foreach ( $facets as $facet ) {
			$index = $facet['index'] ?? null;

			if ( ! \is_array( $index ) ) {
				continue;
			}

			$start = $index['byteStart'] ?? null;
			$end   = $index['byteEnd'] ?? null;

			/*
			 * Skip ranges that are malformed, empty, out of bounds, or
			 * that overlap a facet we've already consumed. Dropping a bad
			 * range leaves its display text in place rather than corrupting
			 * the surrounding bytes.
			 */
			if ( ! \is_int( $start ) || ! \is_int( $end ) || $start < $cursor || $start >= $end || $end > $length ) {
				continue;
			}

			/*
			 * A facet may carry several features; in practice Bluesky emits
			 * one, and a non-array `features` (again, untrusted JSON) must
			 * not reach the array-typed renderer.
			 */
			$features = $facet['features'] ?? null;
			$feature  = \is_array( $features ) && \is_array( $features[0] ?? null ) ? $features[0] : array();

			$result .= \substr( $text, $cursor, $start - $cursor );
			$result .= self::render_feature( $feature, \substr( $text, $start, $end - $start ) );
			$cursor  = $end;
		}

		return $result . \substr( $text, $cursor );
	}

	/**
	 * Safely read a facet's `byteStart` for sorting.
	 *
	 * Tolerates malformed facet shapes from untrusted PDS JSON — a missing
	 * or non-array `index` sorts as 0 rather than fataling on array access.
	 *
	 * @param array $facet Facet array.
	 * @return int Byte offset, or 0 when absent/malformed.
	 */
	private static function byte_start( array $facet ): int {
		$index = $facet['index'] ?? null;

		return \is_array( $index ) && \is_int( $index['byteStart'] ?? null ) ? $index['byteStart'] : 0;
	}

	/**
	 * Render a single facet feature around its display text.
	 *
	 * Unknown feature types fall back to the display text unchanged, so a
	 * facet type we don't handle never drops the text it annotated.
	 *
	 * @param array  $feature Facet feature (first entry of a facet's `features`).
	 * @param string $display Display text the facet covers.
	 * @return string HTML fragment, or the display text unchanged.
	 */
	private static function render_feature( array $feature, string $display ): string {
		/*
		 * The display text is a slice of the remote record's `text`, so it
		 * can contain HTML-significant characters (e.g. `</a>`). Escape it
		 * before embedding so the anchor can't be broken out of and no
		 * markup is injected, independent of any downstream wp_kses_post().
		 */
		$display = \esc_html( $display );

		switch ( $feature['$type'] ?? '' ) {
			case 'app.bsky.richtext.facet#link':
				$href = \esc_url( $feature['uri'] ?? '' );
				break;

			case 'app.bsky.richtext.facet#mention':
				/*
				 * The mention facet only carries the DID, so link by DID.
				 * The appview's /profile/{did} resolves the same as the
				 * handle form used elsewhere in Reaction_Sync.
				 */
				$did  = $feature['did'] ?? '';
				$href = '' === $did
					? ''
					: \esc_url(
						appview_url(
							'profile/' . $did,
							array(
								'type' => 'mention',
								'did'  => $did,
							)
						)
					);
				break;

			case 'app.bsky.richtext.facet#tag':
				$tag  = $feature['tag'] ?? '';
				$href = '' === $tag
					? ''
					: \esc_url(
						appview_url(
							'hashtag/' . \rawurlencode( $tag ),
							array(
								'type' => 'hashtag',
								'tag'  => $tag,
							)
						)
					);
				break;

			default:
				$href = '';
				break;
		}

		/*
		 * Fall back to the bare (escaped) display text when there's no
		 * usable target — an unknown feature type, a missing value, or a
		 * scheme `esc_url()` rejected — rather than emitting an empty
		 * `href` that would link to the current page.
		 */
		if ( '' === $href ) {
			return $display;
		}

		return '<a href="' . $href . '">' . $display . '</a>';
	}

	/**
	 * Find URLs in text and return link facets.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	private static function links( string $text ): array {
		$facets  = array();
		$pattern = '#\bhttps?://[^\s<>\[\]"\']+#iu';

		if ( ! \preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $match ) {
			$url        = \rtrim( $match[0], '.,;:!?)' );
			$byte_start = $match[1];

			$facets[] = array(
				'index'    => array(
					'byteStart' => $byte_start,
					'byteEnd'   => $byte_start + \strlen( $url ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $url,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Find @handle mentions and return mention facets.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	private static function mentions( string $text ): array {
		$facets  = array();
		$pattern = self::MENTION_PATTERN;

		if ( ! \preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $i => $match ) {
			$full   = $match[0];
			$handle = $matches[1][ $i ][0];
			$start  = $match[1];

			$did = self::resolve_mention( $handle );

			/*
			 * `resolve_mention()` returns an empty string when the
			 * handle fails its (defence-in-depth) syntax check. Skip
			 * the facet entirely in that case — sending an empty
			 * `did` to Bluesky would have the PDS reject the record.
			 */
			if ( '' === $did ) {
				continue;
			}

			$facets[] = array(
				'index'    => array(
					'byteStart' => $start,
					'byteEnd'   => $start + \strlen( $full ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#mention',
						'did'   => $did,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Find resolvable `@handle.tld` mentions in a piece of text.
	 *
	 * Returns a map of handle => DID for every distinct, resolvable mention,
	 * in first-appearance order. Handles that fail resolution (malformed, or
	 * not a valid DNS name) are omitted, so a handle present in the result is
	 * guaranteed to produce a `#mention` facet when it reaches a record's
	 * `text`. Shares the regex and resolver used to build mention facets.
	 *
	 * @param string $text Plain text.
	 * @return array<string,string> Map of handle => DID.
	 */
	public static function resolve_handles( string $text ): array {
		if ( ! \preg_match_all( self::MENTION_PATTERN, $text, $matches ) ) {
			return array();
		}

		$handles = array();
		$seen    = array();

		foreach ( $matches[1] as $handle ) {
			$key = \strtolower( $handle );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$did = self::resolve_mention( $handle );
			if ( '' === $did ) {
				continue;
			}

			$handles[ $handle ] = $did;
		}

		return $handles;
	}

	/**
	 * Find #hashtags and return tag facets.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	private static function hashtags( string $text ): array {
		$facets  = array();
		$pattern = '/#([a-zA-Z][a-zA-Z0-9_]{0,63})/u';

		if ( ! \preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $i => $match ) {
			$full  = $match[0];
			$tag   = $matches[1][ $i ][0];
			$start = $match[1];

			$facets[] = array(
				'index'    => array(
					'byteStart' => $start,
					'byteEnd'   => $start + \strlen( $full ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#tag',
						'tag'   => $tag,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Resolve a handle to a DID for mention facets.
	 *
	 * Resolution uses the full AT Protocol handle-resolution chain (DNS
	 * TXT, then the HTTPS `.well-known/atproto-did` fallback) via
	 * {@see Resolver::handle_to_did()}. A handle that cannot be resolved
	 * yields an empty string, so the mention is left as plain text rather
	 * than fabricating a `did:web:<handle>` — the vast majority of handles
	 * (anything `*.bsky.social`, for one) resolve over the well-known
	 * endpoint, not DNS, so a `did:web` guess is almost always wrong and
	 * produces a record that links to a non-existent profile.
	 *
	 * The `is_valid_handle()` gate ensures only DNS-syntactically valid
	 * handles reach resolution — that closes the "malformed handle as DNS
	 * query smuggling" angle (e.g. control characters or percent-encoded
	 * segments injected through a regex relaxation). It does NOT block
	 * lookups against attacker-controlled but well-formed domains; that
	 * broader DNS/HTTP egress is by design, since mention resolution must
	 * reach the mentioned handle's authoritative server (the HTTP fallback
	 * uses `wp_safe_remote_get()`, which rejects internal hosts). If that
	 * egress surface becomes a concern, the right fix is at the
	 * threat-model layer (skip mention resolution on the commenter path,
	 * allowlist mention authorities, or move to DoH with a hard timeout)
	 * rather than tightening the syntactic gate further.
	 *
	 * @param string $handle AT Protocol handle.
	 * @return string DID string, or empty string if the handle is malformed
	 *                or cannot be resolved.
	 */
	private static function resolve_mention( string $handle ): string {
		$conn = get_connection();
		if ( ! empty( $conn['handle'] ) && \strtolower( $handle ) === \strtolower( $conn['handle'] ) ) {
			return $conn['did'];
		}

		if ( ! self::is_valid_handle( $handle ) ) {
			return '';
		}

		$key = \strtolower( $handle );
		if ( \array_key_exists( $key, self::$resolution_cache ) ) {
			return self::$resolution_cache[ $key ];
		}

		$did = Resolver::handle_to_did( $handle );
		if ( \is_wp_error( $did ) ) {
			$did = '';
		}

		self::$resolution_cache[ $key ] = $did;

		return $did;
	}

	/**
	 * RFC 1035-style DNS-name validation, mirroring
	 * `Resolver::is_valid_handle()`. Rejects empty strings, oversized
	 * labels, leading/trailing hyphens, single-label hosts, and any
	 * character outside `[A-Za-z0-9-]` — including percent-encoded
	 * forms.
	 *
	 * @param string $host Handle to validate.
	 * @return bool
	 */
	private static function is_valid_handle( string $host ): bool {
		if ( '' === $host || \strlen( $host ) > 253 ) {
			return false;
		}

		$label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

		return (bool) \preg_match( '/^' . $label . '(?:\.' . $label . ')+$/', $host );
	}
}
