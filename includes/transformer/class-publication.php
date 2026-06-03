<?php
/**
 * Transforms WordPress site settings into a site.standard.publication record.
 *
 * One publication record per site — created when the user first
 * connects or explicitly syncs from the settings page.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

use function Atmosphere\build_at_uri;
use function Atmosphere\get_did;
use function Atmosphere\sanitize_text;
use function Atmosphere\truncate_graphemes;

/**
 * Standard.site publication transformer.
 */
class Publication extends Base {

	/**
	 * Option key for the publication TID.
	 *
	 * @var string
	 */
	public const OPTION_TID = 'atmosphere_publication_tid';

	/**
	 * Option key for the publication CID captured at the last successful
	 * `sync_publication()` write.
	 *
	 * Stored so {@see self::get_strong_ref()} can build a strongRef
	 * without a `getRecord` round-trip. The CID rotates whenever the
	 * publication's content changes (site title, theme color, etc.)
	 * and is re-captured on every successful putRecord. Both the TID
	 * and CID survive disconnect for the same reason — they're the
	 * stable site-level identifiers — and are only cleared on
	 * uninstall.
	 *
	 * @var string
	 */
	public const OPTION_CID = 'atmosphere_publication_cid';

	/**
	 * Transform site settings into a publication record.
	 *
	 * @return array site.standard.publication record.
	 */
	public function transform(): array {
		/*
		 * WordPress stores the site name and tagline HTML-entity encoded
		 * (esc_html at save time). sanitize_text() strips tags, decodes
		 * those entities, and collapses whitespace, so the record carries
		 * clean plain text rather than codes like `&#039;`.
		 *
		 * The site.standard.publication lexicon caps `name` at 500
		 * graphemes and `description` at 3000 graphemes. WordPress puts
		 * no such limit on `blogname` / `blogdescription`, so a long
		 * tagline would otherwise produce a non-spec record and get
		 * rejected by the PDS at sync time.
		 */
		$record = array(
			'$type'       => 'site.standard.publication',
			'url'         => \home_url( '/' ),
			'name'        => truncate_graphemes( sanitize_text( \get_bloginfo( 'name' ) ), 500 ),
			'description' => truncate_graphemes( sanitize_text( \get_bloginfo( 'description' ) ), 3000 ),
		);

		// Site icon. The site.standard.publication lexicon expects a square
		// `icon` blob (at least 256x256). The Site Icon control crops to a
		// square and recommends 512px, which clears that guideline.
		$icon_id = \get_option( 'site_icon' );
		if ( $icon_id ) {
			$blob = Post::upload_thumbnail( (int) $icon_id );
			if ( $blob ) {
				$record['icon'] = $blob;
			}
		}

		// Theme colours. site.standard.publication uses the `basicTheme`
		// field, a ref to site.standard.theme.basic with four required
		// colors. Skipped entirely if any colour can't be sourced — the
		// spec requires all four and a partial record is rejected.
		$basic_theme = $this->extract_basic_theme();
		if ( $basic_theme ) {
			$record['basicTheme'] = $basic_theme;
		}

		/**
		 * Filters the site.standard.publication record.
		 *
		 * Filters that return a non-array fall back to the pre-filter
		 * record.
		 *
		 * @param array $record Publication record.
		 */
		$filtered = \apply_filters( 'atmosphere_transform_publication', $record );

		if ( ! \is_array( $filtered ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_transform_publication must return an array; falling back to the unfiltered record.', 'atmosphere' ),
				'1.0.0'
			);
			return $record;
		}

		return $filtered;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_collection(): string {
		return 'site.standard.publication';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_rkey(): string {
		$rkey = \get_option( self::OPTION_TID );

		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
			\update_option( self::OPTION_TID, $rkey, false );
		}

		return $rkey;
	}

	/**
	 * Build a {@link https://atproto.com/specs/lexicon com.atproto.repo.strongRef}
	 * pointing at the connected site's publication record, or null
	 * when the strongRef cannot be safely constructed.
	 *
	 * Both the TID and the CID are required: the URI half is derivable
	 * from the connected DID + the stored TID, but the strongRef shape
	 * also needs the content-hash from {@see self::OPTION_CID}, which
	 * is only populated after a successful `sync_publication()` write.
	 * A fresh-connect install that has not yet synced returns null
	 * here and callers omit `associatedRefs` rather than ship a
	 * malformed strongRef.
	 *
	 * @return array{$type: string, uri: string, cid: string}|null
	 */
	public static function get_strong_ref(): ?array {
		$tid = (string) \get_option( self::OPTION_TID, '' );
		$cid = (string) \get_option( self::OPTION_CID, '' );

		if ( '' === $tid || '' === $cid ) {
			return null;
		}

		$did = get_did();

		if ( '' === $did ) {
			return null;
		}

		return array(
			'$type' => 'com.atproto.repo.strongRef',
			'uri'   => build_at_uri( $did, 'site.standard.publication', $tid ),
			'cid'   => $cid,
		);
	}

	/**
	 * Extract a site.standard.theme.basic record from the active theme.
	 *
	 * Returns null when any of the four required colours can't be
	 * sourced — the lexicon rejects partial records, so the whole
	 * `basicTheme` field is omitted in that case. Classic themes
	 * (which only reliably expose `background_color` via theme mods)
	 * fall into this path.
	 *
	 * @return array|null
	 */
	private function extract_basic_theme(): ?array {
		if ( ! \function_exists( 'wp_get_global_styles' ) ) {
			return null;
		}

		$styles  = \wp_get_global_styles();
		$palette = self::get_palette_lookup();

		return self::build_basic_theme( \is_array( $styles ) ? $styles : array(), $palette );
	}

	/**
	 * Build the spec-shaped basicTheme record from already-resolved
	 * global styles and a palette lookup.
	 *
	 * Pure transformation — accepts the WP-resolved inputs as arguments
	 * so the unit tests can drive it without standing up a real
	 * theme.json merge.
	 *
	 * @param array                $styles  Output of `wp_get_global_styles()`.
	 * @param array<string,string> $palette Slug => hex map from the theme palette.
	 * @return array|null Spec-shaped record, or null when any required colour
	 *                    is missing.
	 */
	public static function build_basic_theme( array $styles, array $palette ): ?array {
		$background = self::resolve_color( (string) ( $styles['color']['background'] ?? '' ), $palette );
		$foreground = self::resolve_color( (string) ( $styles['color']['text'] ?? '' ), $palette );

		/*
		 * Link colour is the conventional accent source in WP themes —
		 * it's the one element nearly every theme styles explicitly.
		 * Falls back to a palette slug literally named `accent` for
		 * themes that don't restyle links.
		 */
		$accent = self::resolve_color(
			(string) ( $styles['elements']['link']['color']['text'] ?? '' ),
			$palette
		);

		if ( null === $accent && isset( $palette['accent'] ) ) {
			$accent = self::resolve_color( $palette['accent'], $palette );
		}

		if ( null === $background || null === $foreground || null === $accent ) {
			return null;
		}

		return array(
			'background'       => self::color_object( $background ),
			'foreground'       => self::color_object( $foreground ),
			'accent'           => self::color_object( $accent ),
			'accentForeground' => self::color_object( self::contrast_color( $accent ) ),
		);
	}

	/**
	 * Resolve a colour value to an `{r, g, b}` array.
	 *
	 * Accepts a direct hex string or a `var(--wp--preset--color--{slug})`
	 * reference that resolves against the supplied palette lookup.
	 * Returns null for anything else (named colours, `currentColor`,
	 * gradients, etc.).
	 *
	 * @param string               $value           Raw colour value.
	 * @param array<string,string> $palette_lookup  Slug => hex map.
	 * @return array{r: int, g: int, b: int}|null
	 */
	private static function resolve_color( string $value, array $palette_lookup ): ?array {
		$value = \trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$rgb = self::hex_to_rgb( $value );
		if ( $rgb ) {
			return $rgb;
		}

		/*
		 * Anchored on both ends so a `var(...)` reference embedded in a
		 * larger expression (e.g. `linear-gradient(var(--wp--preset--
		 * color--primary), #fff)`) does NOT resolve to a single RGB
		 * triple — the surrounding gradient changes the rendered colour
		 * meaningfully, and there's no honest single-colour answer for
		 * the publication record. An optional `, fallback` between the
		 * slug and the closing `)` is consumed but ignored.
		 */
		if ( \preg_match( '/^var\(\s*--wp--preset--color--([a-z0-9_-]+)\s*(?:,[^)]*)?\)$/i', $value, $matches ) ) {
			$slug = \strtolower( $matches[1] );
			if ( isset( $palette_lookup[ $slug ] ) ) {
				return self::hex_to_rgb( $palette_lookup[ $slug ] );
			}
		}

		return null;
	}

	/**
	 * Flatten the active theme palette into a `slug => hex` lookup.
	 *
	 * Accepts either of the two shapes `wp_get_global_settings()` is
	 * known to return: a flat list of `{ slug, name, color }` entries
	 * (the default, when no `context` is supplied), or an origin-grouped
	 * map `{ default: [...], theme: [...], custom: [...] }` (some
	 * context-passing variants of the API). Theme-defined slugs land
	 * last in iteration order and overwrite default-palette slugs of
	 * the same name — same precedence consumers see when the browser
	 * resolves the CSS variable.
	 *
	 * The `$raw_palette` parameter exists to make the flattening
	 * testable without standing up a real `wp_theme_json` merge. The
	 * default `null` resolves to whatever `wp_get_global_settings()`
	 * returns at call time.
	 *
	 * @param mixed $raw_palette Raw palette data, or null to read from
	 *                           `wp_get_global_settings()`.
	 * @return array<string,string>
	 */
	public static function get_palette_lookup( $raw_palette = null ): array {
		if ( null === $raw_palette ) {
			if ( ! \function_exists( 'wp_get_global_settings' ) ) {
				return array();
			}
			$raw_palette = \wp_get_global_settings( array( 'color', 'palette' ) );
		}

		if ( ! \is_array( $raw_palette ) ) {
			return array();
		}

		$lookup = array();
		foreach ( $raw_palette as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}

			// Flat shape: `{ slug, color }`.
			if ( isset( $entry['slug'], $entry['color'] ) ) {
				$lookup[ \strtolower( (string) $entry['slug'] ) ] = (string) $entry['color'];
				continue;
			}

			// Origin-grouped shape: each nested entry is itself a `{ slug, color }` array.
			foreach ( $entry as $sub_entry ) {
				if ( \is_array( $sub_entry ) && isset( $sub_entry['slug'], $sub_entry['color'] ) ) {
					$lookup[ \strtolower( (string) $sub_entry['slug'] ) ] = (string) $sub_entry['color'];
				}
			}
		}

		return $lookup;
	}

	/**
	 * Wrap an `{r, g, b}` array in the site.standard.theme.color#rgb
	 * union shape.
	 *
	 * @param array{r: int, g: int, b: int} $rgb Source RGB triple, channels 0-255.
	 * @return array
	 */
	private static function color_object( array $rgb ): array {
		return array(
			'$type' => 'site.standard.theme.color#rgb',
			'r'     => $rgb['r'],
			'g'     => $rgb['g'],
			'b'     => $rgb['b'],
		);
	}

	/**
	 * Pick a high-contrast foreground colour (pure white or pure black)
	 * for a given accent colour, using WCAG relative luminance.
	 *
	 * The 0.5 threshold matches the rule of thumb used by most design
	 * systems: a colour with relative luminance above 0.5 reads as
	 * light, below as dark. White-on-dark / black-on-light always
	 * clears the WCAG AA 4.5:1 contrast bar.
	 *
	 * @param array{r: int, g: int, b: int} $rgb Accent colour, channels 0-255.
	 * @return array{r: int, g: int, b: int}
	 */
	private static function contrast_color( array $rgb ): array {
		$luminance = 0.2126 * self::srgb_to_linear( $rgb['r'] / 255 )
			+ 0.7152 * self::srgb_to_linear( $rgb['g'] / 255 )
			+ 0.0722 * self::srgb_to_linear( $rgb['b'] / 255 );

		return $luminance > 0.5
			? array(
				'r' => 0,
				'g' => 0,
				'b' => 0,
			)
			: array(
				'r' => 255,
				'g' => 255,
				'b' => 255,
			);
	}

	/**
	 * Inverse of the sRGB transfer function — convert a gamma-encoded
	 * 0..1 channel to a linear 0..1 channel for luminance calculation.
	 *
	 * @param float $c Channel value in 0..1.
	 * @return float
	 */
	private static function srgb_to_linear( float $c ): float {
		return $c <= 0.03928 ? $c / 12.92 : \pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * Convert a hex colour string to an RGB array.
	 *
	 * @param string $hex Hex string (#RRGGBB or #RGB).
	 * @return array{r: int, g: int, b: int}|null
	 */
	public static function hex_to_rgb( string $hex ): ?array {
		$hex = \ltrim( $hex, '#' );

		if ( ! \preg_match( '/^[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $hex ) ) {
			return null;
		}

		if ( 3 === \strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return array(
			'r' => \hexdec( \substr( $hex, 0, 2 ) ),
			'g' => \hexdec( \substr( $hex, 2, 2 ) ),
			'b' => \hexdec( \substr( $hex, 4, 2 ) ),
		);
	}
}
