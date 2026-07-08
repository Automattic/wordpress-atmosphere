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
use function Atmosphere\debug_log;

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
	 * The leading `(?<![\w@])` boundary skips the domain half of an email
	 * address (`bob@example.com`) or an ActivityPub `@user@domain.tld`
	 * handle. The trailing `(?![\w@])` boundary rejects a WebFinger handle
	 * whose user half is itself domain-shaped (`@notiz.blog@notiz.blog`):
	 * without it the first `@notiz.blog` would be mistaken for a standalone
	 * Bluesky handle. Both boundaries keep these false positives from driving
	 * real DNS/HTTP resolution or minting a bogus `#mention` facet. A `.` is
	 * deliberately left out of *both* classes: out of the trailing class so a
	 * handle ending a sentence (`@bsky.app.`) still matches, and out of the
	 * leading class so the Twitter-style dot-mention idiom
	 * (`.@alice.bsky.social`) still resolves and links.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public const MENTION_PATTERN = '/(?<![\w@])@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+)(?![\w@])/u';

	/**
	 * Hard cap on distinct handles resolved in a single {@see self::resolve_handles()} scan.
	 *
	 * `resolve_handles()` runs over the *entire* post body, and each distinct
	 * handle costs a DNS TXT lookup plus an HTTPS `.well-known/atproto-did`
	 * fallback ({@see Resolver::handle_to_did()}). Without a ceiling an author
	 * could pack a body with thousands of distinct `@fake-N.example.com` tokens
	 * and turn one publish (or a 500-post backfill chunk) into tens of thousands
	 * of blocking outbound requests aimed at attacker-chosen hosts. A Bluesky
	 * post is 300 graphemes, so only a handful of mentions can ever be carried
	 * anyway; 20 is a generous headroom over any legitimate post while keeping
	 * the egress bounded. Handles past the cap are ignored (and logged once).
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	private const MAX_RESOLVED_HANDLES = 20;

	/**
	 * Request-scoped memo of handle => DID resolutions.
	 *
	 * Broadening mention collection to the full post body resolves the same
	 * handle more than once per publish (the carry-over detection pass and
	 * the final {@see self::extract()} on the composed text). Memoizing the
	 * resolved DID keeps that to one lookup per distinct handle per request,
	 * bounding duplicate DNS/HTTP egress. Only successful resolutions are
	 * cached — see {@see self::resolve_mention()} for why misses are not. Keyed
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
	 * `$with_mentions` gates the one facet type that performs network
	 * resolution. Mention facets require a DID, so building them runs the full
	 * handle-resolution chain (DNS + HTTPS) — see {@see self::resolve_mention()}.
	 * Callers that feed lower-trust, third-party text (the comment-sync path
	 * passes commenter-supplied content) pass `false` so an approved comment
	 * mentioning `@target.example.com` can't make the server issue outbound
	 * HTTPS to an arbitrary host. Those mentions simply stay as plain text in
	 * the record. Link and hashtag facets, which never touch the network, are
	 * unaffected.
	 *
	 * `$blocked` names handles that must never mint a `#mention` facet even
	 * when they occur in `$text` — the post path passes the handles that live
	 * only inside a protected region of the source (a `<code>` sample or an
	 * existing `<a>`), which the front end leaves as plain text. Passing `null`
	 * (the default) blocks nothing.
	 *
	 * @param string                  $text          Plain text.
	 * @param bool                    $with_mentions Whether to resolve and emit `#mention` facets. Default true.
	 * @param array<string,true>|null $blocked  Lowercased handles that must not mint a facet, or null.
	 * @return array Sorted array of facet objects.
	 */
	public static function extract( string $text, bool $with_mentions = true, ?array $blocked = null ): array {
		$facets = \array_merge(
			self::links( $text ),
			$with_mentions ? self::mentions( $text, $blocked ) : array(),
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
	 * @since 2.0.0
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
	 * Resolution is bounded the same way {@see self::resolve_handles()} bounds
	 * the body scan: at most {@see self::MAX_RESOLVED_HANDLES} *distinct* new
	 * handles are resolved per call, and a within-call miss is not retried, so a
	 * record — or teaser-thread entry — packed with unresolvable tokens can't
	 * fan out into an unbounded run of blocking DNS + HTTPS lookups. Every
	 * occurrence of an already-resolved handle still mints its facet.
	 *
	 * @param string                  $text    Plain text.
	 * @param array<string,true>|null $blocked Lowercased handles that must not mint a facet, or null.
	 * @return array
	 */
	private static function mentions( string $text, ?array $blocked = null ): array {
		$facets = array();

		if ( ! \preg_match_all( self::MENTION_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		$resolved = array(); // Lowercased handle => DID ('' marks a within-call miss).
		$capped   = false;

		foreach ( $matches[0] as $i => $match ) {
			$full   = $match[0];
			$handle = $matches[1][ $i ][0];
			$start  = $match[1];
			$key    = \strtolower( $handle );

			/*
			 * A handle the display side would never linkify (it lives only
			 * inside a protected region of the source) must not mint a
			 * `#mention` facet or notify anyone — see Mention::classify_handles().
			 */
			if ( null !== $blocked && isset( $blocked[ $key ] ) ) {
				continue;
			}

			if ( ! isset( $resolved[ $key ] ) ) {
				if ( \count( $resolved ) >= self::MAX_RESOLVED_HANDLES ) {
					$capped = true;
					continue;
				}
				$resolved[ $key ] = self::resolve_mention( $handle );
			}

			$did = $resolved[ $key ];

			/*
			 * `resolve_mention()` returns an empty string when the handle fails
			 * its (defence-in-depth) syntax check or cannot be resolved. Skip
			 * the facet entirely in that case — sending an empty `did` to
			 * Bluesky would have the PDS reject the record.
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

		if ( $capped ) {
			debug_log(
				\sprintf(
					'Facet::mentions: text has more than %d distinct @mentions; the rest are left unresolved to bound DNS/HTTP lookups.',
					self::MAX_RESOLVED_HANDLES
				)
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
	 * @since 2.0.0
	 *
	 * @param string $text Plain text.
	 * @return array<string,string> Map of handle => DID.
	 */
	public static function resolve_handles( string $text ): array {
		if ( ! \preg_match_all( self::MENTION_PATTERN, $text, $matches ) ) {
			return array();
		}

		return self::resolve_handle_list( $matches[1] );
	}

	/**
	 * Resolve an explicit list of handles to a handle => DID map.
	 *
	 * The companion to {@see self::resolve_handles()} for callers that already
	 * hold the handle list — the post path resolves the display-linkable set
	 * from {@see \Atmosphere\Mention::classify_handles()} rather than re-scanning
	 * flattened text, which would glue a handle to a preceding word across a
	 * stripped tag and miss it. Deduplicates by lowercased handle, keeps
	 * first-appearance order, drops handles that don't resolve, and applies the
	 * same {@see self::MAX_RESOLVED_HANDLES} egress cap.
	 *
	 * @since 2.0.0
	 *
	 * @param string[] $handles Candidate handles (no leading `@`).
	 * @return array<string,string> Map of handle => DID.
	 */
	public static function resolve_handle_list( array $handles ): array {
		$resolved = array();
		$seen     = array();

		foreach ( $handles as $handle ) {
			$key = \strtolower( $handle );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			/*
			 * Stop resolving once the distinct-handle cap is reached: each new
			 * handle past this point costs a fresh DNS + HTTPS lookup. See
			 * self::MAX_RESOLVED_HANDLES for the threat this bounds.
			 */
			if ( \count( $seen ) >= self::MAX_RESOLVED_HANDLES ) {
				debug_log(
					\sprintf(
						'Facet::resolve_handle_list: more than %d distinct @mentions; the rest are left unresolved to bound DNS/HTTP lookups.',
						self::MAX_RESOLVED_HANDLES
					)
				);
				break;
			}

			$seen[ $key ] = true;

			$did = self::resolve_mention( $handle );
			if ( '' === $did ) {
				continue;
			}

			$resolved[ $handle ] = $did;
		}

		return $resolved;
	}

	/**
	 * Collect the distinct `@handle.tld` mentions present in a piece of text.
	 *
	 * A resolution-free companion to {@see self::resolve_handles()}: it applies
	 * the shared {@see self::MENTION_PATTERN} but performs no DNS/HTTP lookups,
	 * returning a set keyed by lowercased handle (value `true`). Used to test
	 * mention membership on token boundaries — a substring check would treat
	 * `@alice.com` as already present inside `@alice.company` — and to size the
	 * carry-over line in the DNS-free pre-publish preview.
	 *
	 * @since 2.0.0
	 *
	 * @param string $text Plain text.
	 * @return array<string,true> Set of lowercased handles present in the text.
	 */
	public static function handles_in( string $text ): array {
		if ( ! \preg_match_all( self::MENTION_PATTERN, $text, $matches ) ) {
			return array();
		}

		$set = array();
		foreach ( $matches[1] as $handle ) {
			$set[ \strtolower( $handle ) ] = true;
		}

		return $set;
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
	 * The {@see Resolver::is_valid_handle()} gate ensures only DNS-syntactically
	 * valid handles reach resolution (sharing the resolver's rules, including
	 * its reserved-TLD rejection) — that closes the "malformed handle as DNS
	 * query smuggling" angle (e.g. control characters or percent-encoded
	 * segments injected through a regex relaxation). It does NOT block
	 * lookups against attacker-controlled but well-formed domains; that
	 * broader DNS/HTTP egress is by design, since mention resolution must
	 * reach the mentioned handle's authoritative server (the HTTP fallback
	 * uses `wp_safe_remote_get()`, which rejects internal hosts). The
	 * lower-trust commenter path opts out of this resolution entirely by
	 * passing `$with_mentions = false` to {@see self::extract()}; the
	 * body-scan path bounds it with {@see self::MAX_RESOLVED_HANDLES}. If the
	 * remaining egress surface becomes a concern, the right next step is at the
	 * threat-model layer (allowlist mention authorities, or move to DoH with a
	 * hard timeout) rather than tightening the syntactic gate further.
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

		if ( ! Resolver::is_valid_handle( $handle ) ) {
			return '';
		}

		$key = \strtolower( $handle );
		if ( isset( self::$resolution_cache[ $key ] ) ) {
			return self::$resolution_cache[ $key ];
		}

		$did = Resolver::handle_to_did( $handle );
		if ( \is_wp_error( $did ) ) {
			/**
			 * Filters whether an unresolvable handle falls back to `did:web:<handle>`.
			 *
			 * Resolution can fail either definitively (no `_atproto` DNS TXT
			 * record and no `.well-known/atproto-did`) or transiently (a DNS or
			 * HTTPS outage at publish time). By default the mention is left as
			 * plain text: for the overwhelming majority of handles — every
			 * `*.bsky.social`, for one — the true DID is a `did:plc:…` served
			 * over the well-known endpoint, so a fabricated `did:web:<handle>`
			 * would point the facet at a non-existent profile. A site that hosts
			 * `did:web` accounts (where the handle *is* the DID authority) can
			 * return true to restore the pre-1.0 fallback and keep the mention
			 * even through a transient resolver blip.
			 *
			 * @since 2.0.0
			 *
			 * @param bool     $fallback Whether to fall back to `did:web:<handle>`. Default false.
			 * @param string   $handle   The handle that failed to resolve.
			 * @param \WP_Error $error    The resolver error (its code distinguishes a
			 *                            definitive miss from a transient network failure).
			 */
			$fallback = \apply_filters( 'atmosphere_mention_didweb_fallback', false, $handle, $did );

			$did = $fallback ? 'did:web:' . $handle : '';
		}

		// Cache successes only. Memoizing a miss would let a single transient
		// DNS/HTTP blip suppress that handle's #mention facet across every later
		// post in a long-lived WP-CLI / cron run; re-resolving a genuine miss
		// (at most twice per post) is the cheaper trade.
		if ( '' !== $did ) {
			self::$resolution_cache[ $key ] = $did;
		}

		return $did;
	}
}
