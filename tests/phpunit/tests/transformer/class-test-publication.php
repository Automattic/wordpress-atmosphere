<?php
/**
 * Tests for the Publication transformer.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use Atmosphere\Transformer\Publication;

/**
 * Publication transformer tests.
 */
class Test_Publication extends \WP_UnitTestCase {

	/**
	 * Clean up option/filter state between tests.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_publication_labels' );
		\remove_all_filters( 'atmosphere_publication_show_in_discover' );
		\remove_all_filters( 'atmosphere_publication_basic_theme' );
		\delete_option( 'site_icon' );
		\update_option( 'blog_public', 1 );
		\delete_option( Publication::OPTION_THEME_BACKGROUND );
		\delete_option( Publication::OPTION_THEME_FOREGROUND );
		\delete_option( Publication::OPTION_THEME_ACCENT );

		parent::tear_down();
	}

	/**
	 * Test hex_to_rgb with a full hex color.
	 */
	public function test_hex_to_rgb_full() {
		$rgb = Publication::hex_to_rgb( '#ff8800' );

		$this->assertSame( 255, $rgb['r'] );
		$this->assertSame( 136, $rgb['g'] );
		$this->assertSame( 0, $rgb['b'] );
	}

	/**
	 * Test hex_to_rgb with shorthand hex.
	 */
	public function test_hex_to_rgb_shorthand() {
		$rgb = Publication::hex_to_rgb( '#f80' );

		$this->assertSame( 255, $rgb['r'] );
		$this->assertSame( 136, $rgb['g'] );
		$this->assertSame( 0, $rgb['b'] );
	}

	/**
	 * Test hex_to_rgb rejects invalid input.
	 */
	public function test_hex_to_rgb_invalid() {
		$this->assertNull( Publication::hex_to_rgb( 'not-a-color' ) );
		$this->assertNull( Publication::hex_to_rgb( 'var(--wp-color)' ) );
	}

	/**
	 * Test that the publication TID is stable across calls.
	 */
	public function test_publication_tid_stable() {
		\delete_option( Publication::OPTION_TID );

		$pub  = new Publication( null );
		$rkey = $pub->get_rkey();

		$this->assertNotEmpty( $rkey );
		$this->assertSame( $rkey, $pub->get_rkey() );
	}

	/**
	 * Test the collection NSID.
	 */
	public function test_collection() {
		$pub = new Publication( null );

		$this->assertSame( 'site.standard.publication', $pub->get_collection() );
	}

	/**
	 * The site icon populates the spec-compliant `icon` field, not the
	 * legacy non-spec `avatar` field.
	 *
	 * Seeds the `_atmosphere_blob_ref` cache so the transformer resolves
	 * the blob from post meta instead of performing a network upload.
	 */
	public function test_site_icon_maps_to_icon_field() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'site-icon.png',
				'post_mime_type' => 'image/png',
			),
			0,
			array(
				'post_title' => 'Site icon',
			)
		);

		$blob = array(
			'cid'      => 'bafyicon',
			'mimeType' => 'image/png',
			'size'     => 4096,
		);
		\update_post_meta( $attachment_id, '_atmosphere_blob_ref', $blob );
		\update_option( 'site_icon', $attachment_id );

		$record = ( new Publication( null ) )->transform();

		$this->assertSame( 'site.standard.publication', $record['$type'] );
		$this->assertSame( $blob, $record['icon'], 'Site icon should populate the spec `icon` field.' );
		$this->assertArrayNotHasKey( 'avatar', $record, 'The non-spec `avatar` field must not be present.' );
	}

	/**
	 * When a site icon is set but its blob cannot be uploaded, the `icon`
	 * key is omitted rather than written as null. The attachment has no
	 * backing file and no cached blob ref, so upload_image_blob() returns
	 * null at its `! $file` guard without a network call.
	 */
	public function test_site_icon_omitted_when_blob_upload_fails() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'missing.png',
				'post_mime_type' => 'image/png',
			),
			0,
			array(
				'post_title' => 'Site icon',
			)
		);
		\delete_post_meta( $attachment_id, '_wp_attached_file' );
		\update_option( 'site_icon', $attachment_id );

		$record = ( new Publication( null ) )->transform();

		$this->assertArrayNotHasKey( 'icon', $record );
	}

	/**
	 * The publication record uses the spec field `name` and omits the
	 * non-spec `displayName` field carried over from the bsky profile shape.
	 */
	public function test_record_omits_non_spec_display_name() {
		$record = ( new Publication( null ) )->transform();

		$this->assertArrayHasKey( 'name', $record );
		$this->assertArrayNotHasKey( 'displayName', $record );
	}

	/**
	 * The publication URL follows standard.site guidance and omits a
	 * trailing slash.
	 */
	public function test_publication_url_omits_trailing_slash() {
		$record = ( new Publication( null ) )->transform();

		$this->assertSame( \untrailingslashit( \home_url( '/' ) ), $record['url'] );
		$this->assertStringEndsNotWith( '/', $record['url'] );
	}

	/**
	 * Extensions can add standard self-labels to publication records.
	 */
	public function test_publication_labels_filter_adds_self_labels() {
		\add_filter(
			'atmosphere_publication_labels',
			static fn() => array(
				'$type'  => 'com.atproto.label.defs#selfLabels',
				'values' => array(
					array( 'val' => 'adult' ),
				),
			)
		);

		$record = ( new Publication( null ) )->transform();

		$this->assertSame( 'com.atproto.label.defs#selfLabels', $record['labels']['$type'] );
		$this->assertSame( 'adult', $record['labels']['values'][0]['val'] );
	}

	/**
	 * A public site (blog_public on) opts into discovery by default.
	 */
	public function test_publication_show_in_discover_defaults_to_true_for_public_site() {
		\update_option( 'blog_public', 1 );

		$record = ( new Publication( null ) )->transform();

		$this->assertSame(
			array( 'showInDiscover' => true ),
			$record['preferences']
		);
	}

	/**
	 * A site that discourages search engines (blog_public off) stays out
	 * of discovery by default.
	 */
	public function test_publication_show_in_discover_defaults_to_false_for_private_site() {
		\update_option( 'blog_public', 0 );

		$record = ( new Publication( null ) )->transform();

		$this->assertSame(
			array( 'showInDiscover' => false ),
			$record['preferences']
		);
	}

	/**
	 * The filter overrides the blog_public default.
	 */
	public function test_publication_show_in_discover_filter_adds_preferences() {
		\update_option( 'blog_public', 1 );
		\add_filter( 'atmosphere_publication_show_in_discover', '__return_false' );

		$record = ( new Publication( null ) )->transform();

		$this->assertSame(
			array( 'showInDiscover' => false ),
			$record['preferences']
		);
	}

	/**
	 * A filter returning null omits the preference even on a public site.
	 */
	public function test_publication_show_in_discover_filter_can_omit_preferences() {
		\update_option( 'blog_public', 1 );
		\add_filter( 'atmosphere_publication_show_in_discover', '__return_null' );

		$record = ( new Publication( null ) )->transform();

		$this->assertArrayNotHasKey( 'preferences', $record );
	}

	/**
	 * The name and description are HTML-entity decoded before they reach
	 * the record. WordPress stores `blogname` / `blogdescription`
	 * entity-encoded (esc_html at save time), so the raw values carry
	 * `&#039;`, `&amp;`, etc. Use `pre_option_*` filters to inject the
	 * stored (encoded) form deterministically.
	 */
	public function test_name_and_description_decode_html_entities() {
		\add_filter( 'pre_option_blogname', static fn() => 'Toni&#039;s blog' );
		\add_filter( 'pre_option_blogdescription', static fn() => 'Tom &amp; Jerry' );

		try {
			$record = ( new Publication( null ) )->transform();
		} finally {
			\remove_all_filters( 'pre_option_blogname' );
			\remove_all_filters( 'pre_option_blogdescription' );
		}

		$this->assertSame( "Toni's blog", $record['name'] );
		$this->assertSame( 'Tom & Jerry', $record['description'] );
	}

	/**
	 * End-to-end proof against WordPress's real storage path: a site name
	 * and tagline saved with special characters round-trip to clean text
	 * in the record.
	 *
	 * `update_option()` runs the value through `sanitize_option()`, which
	 * `esc_html()`s `blogname` / `blogdescription` exactly once. `esc_html()`
	 * is idempotent (`_wp_specialchars()` defaults to `$double_encode =
	 * false`), so the stored value is always single-encoded — a single
	 * `html_entity_decode()` pass in `sanitize_text()` fully decodes it.
	 * This is the realistic counterpart to the `pre_option_*` test above,
	 * which injects an arbitrary encoded string directly.
	 */
	public function test_name_and_description_round_trip_real_option_values() {
		\update_option( 'blogname', "Toni's blog & Co" );
		\update_option( 'blogdescription', "Books, coffee & friends'" );

		$record = ( new Publication( null ) )->transform();

		$this->assertSame( "Toni's blog & Co", $record['name'] );
		$this->assertSame( "Books, coffee & friends'", $record['description'] );
	}

	/**
	 * A `blogname` longer than the standard.site lexicon limit (500
	 * graphemes for `name`) is hard-clamped before it lands in the
	 * record, so the PDS does not reject the putRecord with a Lexicon
	 * validation error.
	 */
	public function test_name_is_clamped_to_lexicon_grapheme_limit() {
		$long_name = \str_repeat( 'a', 600 );

		\add_filter( 'pre_option_blogname', static fn() => $long_name );

		try {
			$record = ( new Publication( null ) )->transform();
		} finally {
			\remove_all_filters( 'pre_option_blogname' );
		}

		$count = \function_exists( 'grapheme_strlen' )
			? \grapheme_strlen( $record['name'] )
			: \mb_strlen( $record['name'] );

		$this->assertLessThanOrEqual( 500, $count );
		$this->assertSame( \str_repeat( 'a', 500 ), $record['name'] );
	}

	/**
	 * The 3000-grapheme cap applies to `description` in the same way
	 * `name` is clamped — long taglines do not reach the PDS in a
	 * lexicon-violating shape.
	 */
	public function test_description_is_clamped_to_lexicon_grapheme_limit() {
		$long_description = \str_repeat( 'b', 3500 );

		\add_filter( 'pre_option_blogdescription', static fn() => $long_description );

		try {
			$record = ( new Publication( null ) )->transform();
		} finally {
			\remove_all_filters( 'pre_option_blogdescription' );
		}

		$count = \function_exists( 'grapheme_strlen' )
			? \grapheme_strlen( $record['description'] )
			: \mb_strlen( $record['description'] );

		$this->assertLessThanOrEqual( 3000, $count );
		$this->assertSame( \str_repeat( 'b', 3000 ), $record['description'] );
	}

	/**
	 * `build_basic_theme()` produces the spec-shaped record with all
	 * four required colours when background/foreground/accent are
	 * resolvable from the supplied styles. The record carries the
	 * `site.standard.theme.basic` `$type`, and each colour carries the
	 * `site.standard.theme.color#rgb` union discriminator.
	 */
	public function test_build_basic_theme_returns_spec_shape_with_all_four_colors() {
		$styles = array(
			'color'    => array(
				'background' => '#ffffff',
				'text'       => '#111111',
			),
			'elements' => array(
				'link' => array( 'color' => array( 'text' => '#0066cc' ) ),
			),
		);

		$record = Publication::build_basic_theme( $styles, array() );

		$this->assertIsArray( $record );

		/*
		 * Lexicon JSON objects are unordered — only the set of keys is
		 * part of the contract. Canonicalizing the comparison guards
		 * against false positives if a future refactor emits the same
		 * required fields in a different `transform()` insertion order.
		 */
		$this->assertEqualsCanonicalizing(
			array( '$type', 'background', 'foreground', 'accent', 'accentForeground' ),
			\array_keys( $record ),
			'basicTheme must carry its `$type` plus all four required colours.'
		);

		$this->assertSame(
			'site.standard.theme.basic',
			$record['$type'],
			'basicTheme must carry the `site.standard.theme.basic` `$type` so it passes lexicon validation.'
		);

		foreach ( $record as $key => $color ) {
			if ( '$type' === $key ) {
				continue;
			}

			$this->assertSame(
				'site.standard.theme.color#rgb',
				$color['$type'],
				"Color object `{$key}` must carry the rgb union discriminator."
			);
			$this->assertIsInt( $color['r'] );
			$this->assertIsInt( $color['g'] );
			$this->assertIsInt( $color['b'] );
		}

		$this->assertSame(
			array(
				'$type' => 'site.standard.theme.color#rgb',
				'r'     => 255,
				'g'     => 255,
				'b'     => 255,
			),
			$record['background']
		);
		$this->assertSame(
			array(
				'$type' => 'site.standard.theme.color#rgb',
				'r'     => 17,
				'g'     => 17,
				'b'     => 17,
			),
			$record['foreground']
		);
		$this->assertSame(
			array(
				'$type' => 'site.standard.theme.color#rgb',
				'r'     => 0,
				'g'     => 102,
				'b'     => 204,
			),
			$record['accent']
		);
	}

	/**
	 * Each required colour gates the entire record: if any one of
	 * background / foreground / accent cannot be resolved, `null` is
	 * returned and the caller omits `basicTheme` entirely. A partial
	 * record would be rejected by the PDS — the spec demands all four.
	 */
	public function test_build_basic_theme_returns_null_when_any_required_color_missing() {
		$base = array(
			'color'    => array(
				'background' => '#ffffff',
				'text'       => '#000000',
			),
			'elements' => array( 'link' => array( 'color' => array( 'text' => '#0066cc' ) ) ),
		);

		// No background.
		$without_bg                        = $base;
		$without_bg['color']['background'] = '';
		$this->assertNull( Publication::build_basic_theme( $without_bg, array() ) );

		// No foreground.
		$without_fg                  = $base;
		$without_fg['color']['text'] = '';
		$this->assertNull( Publication::build_basic_theme( $without_fg, array() ) );

		// No accent and no `accent` slug in palette.
		$without_accent                                      = $base;
		$without_accent['elements']['link']['color']['text'] = '';
		$this->assertNull( Publication::build_basic_theme( $without_accent, array() ) );
	}

	/**
	 * A `var(--wp--preset--color--{slug})` reference in any colour
	 * field resolves against the supplied palette lookup. This is the
	 * common modern shape — WP themes emit CSS variables that the
	 * browser later resolves against `:root` custom properties.
	 */
	public function test_build_basic_theme_resolves_css_var_references_against_palette() {
		$styles  = array(
			'color'    => array(
				'background' => 'var(--wp--preset--color--base)',
				'text'       => 'var(--wp--preset--color--contrast)',
			),
			'elements' => array(
				'link' => array( 'color' => array( 'text' => 'var(--wp--preset--color--primary)' ) ),
			),
		);
		$palette = array(
			'base'     => '#fafafa',
			'contrast' => '#222222',
			'primary'  => '#aa2233',
		);

		$record = Publication::build_basic_theme( $styles, $palette );

		$this->assertIsArray( $record );
		$this->assertSame( 250, $record['background']['r'] );
		$this->assertSame( 34, $record['foreground']['r'] );
		$this->assertSame( 170, $record['accent']['r'] );
	}

	/**
	 * A `var(...)` reference embedded in a larger expression — typically
	 * a gradient — does NOT resolve to a single RGB triple. The
	 * resulting publication record would advertise a flat colour where
	 * the rendered page draws a gradient, so the safer behaviour is to
	 * treat the value as unresolvable and omit `basicTheme` entirely
	 * (per the all-or-nothing required-fields contract).
	 */
	public function test_build_basic_theme_rejects_var_inside_gradient() {
		$styles  = array(
			'color'    => array(
				'background' => 'linear-gradient(var(--wp--preset--color--primary), #ffffff)',
				'text'       => '#000000',
			),
			'elements' => array(
				'link' => array( 'color' => array( 'text' => '#0066cc' ) ),
			),
		);
		$palette = array( 'primary' => '#ff0000' );

		$this->assertNull(
			Publication::build_basic_theme( $styles, $palette ),
			'A var() inside a gradient must not be silently resolved to a single colour.'
		);
	}

	/**
	 * `get_palette_lookup()` accepts the origin-grouped shape returned
	 * by some context-passing variants of `wp_get_global_settings()`,
	 * not just the flat default form. Slugs from later origin groups
	 * (typically `theme` after `default`) overwrite same-named slugs
	 * from earlier groups — matching CSS-variable precedence.
	 */
	public function test_get_palette_lookup_flattens_origin_grouped_shape() {
		$nested = array(
			'default' => array(
				array(
					'slug'  => 'primary',
					'color' => '#000000',
				),
				array(
					'slug'  => 'base',
					'color' => '#ffffff',
				),
			),
			'theme'   => array(
				array(
					'slug'  => 'primary',
					'color' => '#ff0000',
				),
				array(
					'slug'  => 'accent',
					'color' => '#00ff00',
				),
			),
		);

		$lookup = Publication::get_palette_lookup( $nested );

		$this->assertSame( '#ff0000', $lookup['primary'], 'Theme-origin slug should override default-origin slug.' );
		$this->assertSame( '#ffffff', $lookup['base'] );
		$this->assertSame( '#00ff00', $lookup['accent'] );
	}

	/**
	 * `get_palette_lookup()` still flattens a plain `{ slug, color }`
	 * list — the default shape `wp_get_global_settings()` returns when
	 * no context is supplied.
	 */
	public function test_get_palette_lookup_flattens_flat_shape() {
		$flat = array(
			array(
				'slug'  => 'primary',
				'color' => '#112233',
			),
			array(
				'slug'  => 'accent',
				'color' => '#445566',
			),
		);

		$lookup = Publication::get_palette_lookup( $flat );

		$this->assertSame( '#112233', $lookup['primary'] );
		$this->assertSame( '#445566', $lookup['accent'] );
	}

	/**
	 * Themes that don't style links explicitly fall back to a palette
	 * slug literally named `accent` for the accent colour source.
	 */
	public function test_build_basic_theme_falls_back_to_palette_accent_slug() {
		$styles  = array(
			'color' => array(
				'background' => '#ffffff',
				'text'       => '#000000',
			),
		);
		$palette = array( 'accent' => '#ff5500' );

		$record = Publication::build_basic_theme( $styles, $palette );

		$this->assertIsArray( $record );
		$this->assertSame( 255, $record['accent']['r'] );
		$this->assertSame( 85, $record['accent']['g'] );
		$this->assertSame( 0, $record['accent']['b'] );
	}

	/**
	 * `accentForeground` is derived from the accent's WCAG relative
	 * luminance — pure black for a light accent (yellow), pure white
	 * for a dark accent (deep blue). The 0.5 threshold places yellow
	 * (~0.93) firmly on the light side and deep blue (~0.05) firmly
	 * on the dark side.
	 */
	public function test_build_basic_theme_derives_accent_foreground_from_luminance() {
		$light_accent = array(
			'color'    => array(
				'background' => '#ffffff',
				'text'       => '#000000',
			),
			'elements' => array( 'link' => array( 'color' => array( 'text' => '#ffeb3b' ) ) ),
		);
		$dark_accent  = array(
			'color'    => array(
				'background' => '#ffffff',
				'text'       => '#000000',
			),
			'elements' => array( 'link' => array( 'color' => array( 'text' => '#0d47a1' ) ) ),
		);

		$light = Publication::build_basic_theme( $light_accent, array() );
		$dark  = Publication::build_basic_theme( $dark_accent, array() );

		$this->assertSame(
			array(
				'$type' => 'site.standard.theme.color#rgb',
				'r'     => 0,
				'g'     => 0,
				'b'     => 0,
			),
			$light['accentForeground'],
			'Light accent should yield black foreground.'
		);
		$this->assertSame(
			array(
				'$type' => 'site.standard.theme.color#rgb',
				'r'     => 255,
				'g'     => 255,
				'b'     => 255,
			),
			$dark['accentForeground'],
			'Dark accent should yield white foreground.'
		);
	}

	/**
	 * Stored publication-theme options override the derived theme colors.
	 */
	public function test_publication_theme_options_override_basic_theme_colors() {
		\update_option( Publication::OPTION_THEME_BACKGROUND, '#112233' );
		\update_option( Publication::OPTION_THEME_FOREGROUND, '#fafafa' );
		\update_option( Publication::OPTION_THEME_ACCENT, '#ff0000' );

		$record = ( new Publication( null ) )->transform();

		$this->assertArrayHasKey( 'basicTheme', $record );
		$this->assertSame( 17, $record['basicTheme']['background']['r'] );
		$this->assertSame( 34, $record['basicTheme']['background']['g'] );
		$this->assertSame( 51, $record['basicTheme']['background']['b'] );
		$this->assertSame( 250, $record['basicTheme']['foreground']['r'] );
		$this->assertSame( 250, $record['basicTheme']['foreground']['g'] );
		$this->assertSame( 250, $record['basicTheme']['foreground']['b'] );
		$this->assertSame( 255, $record['basicTheme']['accent']['r'] );
		$this->assertSame( 0, $record['basicTheme']['accent']['g'] );
		$this->assertSame( 0, $record['basicTheme']['accent']['b'] );

		// Red is dark enough to require a white accent foreground at our threshold.
		$this->assertSame( 255, $record['basicTheme']['accentForeground']['r'] );
		$this->assertSame( 255, $record['basicTheme']['accentForeground']['g'] );
		$this->assertSame( 255, $record['basicTheme']['accentForeground']['b'] );
	}

	/**
	 * A single stored override replaces only its own colour; the rest
	 * stay derived from the active theme.
	 */
	public function test_publication_theme_option_overrides_single_color() {
		$styles = array(
			'color'    => array(
				'background' => '#ffffff',
				'text'       => '#111111',
			),
			'elements' => array(
				'link' => array( 'color' => array( 'text' => '#0000ff' ) ),
			),
		);

		$derived = Publication::build_basic_theme( $styles, array() );
		$merged  = Publication::build_basic_theme(
			$styles,
			array(),
			array(
				'accent' => array(
					'r' => 255,
					'g' => 0,
					'b' => 0,
				),
			)
		);

		// Background and foreground keep the derived values.
		$this->assertSame( $derived['background'], $merged['background'] );
		$this->assertSame( $derived['foreground'], $merged['foreground'] );

		// Only the accent changes — and its contrast colour is recomputed.
		$this->assertSame( 255, $merged['accent']['r'] );
		$this->assertSame( 0, $merged['accent']['g'] );
		$this->assertSame( 255, $merged['accentForeground']['r'] );
	}

	/**
	 * Overrides still apply when the active theme exposes no usable
	 * colours, as long as all three are set — otherwise the record has
	 * no complete theme to publish.
	 */
	public function test_publication_theme_options_apply_without_derivable_theme() {
		$this->assertNull( Publication::build_basic_theme( array(), array() ) );

		$complete = Publication::build_basic_theme(
			array(),
			array(),
			array(
				'background' => array(
					'r' => 1,
					'g' => 2,
					'b' => 3,
				),
				'foreground' => array(
					'r' => 4,
					'g' => 5,
					'b' => 6,
				),
				'accent'     => array(
					'r' => 7,
					'g' => 8,
					'b' => 9,
				),
			)
		);

		$this->assertSame( 1, $complete['background']['r'] );
		$this->assertSame( 'site.standard.theme.basic', $complete['$type'] );

		$partial = Publication::build_basic_theme(
			array(),
			array(),
			array(
				'accent' => array(
					'r' => 7,
					'g' => 8,
					'b' => 9,
				),
			)
		);

		$this->assertNull( $partial, 'An incomplete theme must be omitted rather than half-published.' );
	}

	/**
	 * A malformed stored value is ignored rather than dropping the whole
	 * theme object from the record.
	 */
	public function test_publication_theme_option_ignores_unparseable_value() {
		\update_option( Publication::OPTION_THEME_ACCENT, 'not-a-color' );

		$this->assertSame( array(), Publication::get_theme_option_overrides() );
	}

	/**
	 * The dedicated basicTheme filter can override the transformed value.
	 */
	public function test_publication_basic_theme_filter_overrides_theme() {
		\add_filter(
			'atmosphere_publication_basic_theme',
			static function () {
				return array(
					'$type'            => 'site.standard.theme.basic',
					'background'       => array(
						'$type' => 'site.standard.theme.color#rgb',
						'r'     => 1,
						'g'     => 2,
						'b'     => 3,
					),
					'foreground'       => array(
						'$type' => 'site.standard.theme.color#rgb',
						'r'     => 4,
						'g'     => 5,
						'b'     => 6,
					),
					'accent'           => array(
						'$type' => 'site.standard.theme.color#rgb',
						'r'     => 7,
						'g'     => 8,
						'b'     => 9,
					),
					'accentForeground' => array(
						'$type' => 'site.standard.theme.color#rgb',
						'r'     => 10,
						'g'     => 11,
						'b'     => 12,
					),
				);
			}
		);

		$record = ( new Publication( null ) )->transform();

		$this->assertSame( 1, $record['basicTheme']['background']['r'] );
		$this->assertSame( 10, $record['basicTheme']['accentForeground']['r'] );
	}

	/**
	 * A null return from the basicTheme filter omits the field.
	 */
	public function test_publication_basic_theme_filter_can_omit_basic_theme() {
		\add_filter( 'atmosphere_publication_basic_theme', '__return_null' );

		$record = ( new Publication( null ) )->transform();

		$this->assertArrayNotHasKey( 'basicTheme', $record );
	}

	/**
	 * The record uses the spec field `basicTheme` (not the legacy
	 * `theme` field, which was modelled on the bsky profile shape and
	 * gets ignored by standard.site consumers).
	 */
	public function test_record_omits_non_spec_theme_field() {
		$record = ( new Publication( null ) )->transform();

		$this->assertArrayNotHasKey( 'theme', $record, 'Non-spec `theme` field must not be present.' );
	}

	/**
	 * `get_strong_ref()` returns a well-formed `com.atproto.repo.strongRef`
	 * when all three inputs are present: the connected DID, the
	 * stored TID, and the captured CID.
	 */
	public function test_get_strong_ref_returns_strong_ref_when_all_inputs_present() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ), false );
		\update_option( Publication::OPTION_TID, '3kpub00000000', false );
		\update_option( Publication::OPTION_CID, 'bafyreipublication0000000000000000000000000000000000000000000', false );

		$ref = Publication::get_strong_ref();

		$this->assertIsArray( $ref );
		$this->assertSame( 'com.atproto.repo.strongRef', $ref['$type'] );
		$this->assertSame( 'at://did:plc:test123/site.standard.publication/3kpub00000000', $ref['uri'] );
		$this->assertSame( 'bafyreipublication0000000000000000000000000000000000000000000', $ref['cid'] );

		\delete_option( 'atmosphere_identity' );
		\delete_option( Publication::OPTION_TID );
		\delete_option( Publication::OPTION_CID );
	}

	/**
	 * `get_strong_ref()` returns null when the publication has never
	 * been successfully sync'd (CID missing). The caller skips the
	 * strongRef rather than shipping a malformed entry without `cid`.
	 */
	public function test_get_strong_ref_returns_null_when_cid_is_missing() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ), false );
		\update_option( Publication::OPTION_TID, '3kpub00000000', false );
		\delete_option( Publication::OPTION_CID );

		$this->assertNull( Publication::get_strong_ref() );

		\delete_option( 'atmosphere_identity' );
		\delete_option( Publication::OPTION_TID );
	}

	/**
	 * Preview projections must stay read-only: a site icon whose blob
	 * has never been uploaded is omitted from the previewed record
	 * instead of triggering a PDS blob upload as a side effect of a
	 * front-page preview GET.
	 */
	public function test_preview_records_do_not_upload_uncached_site_icon() {
		$upload_dir = \wp_upload_dir();
		$path       = $upload_dir['basedir'] . '/atmosphere-icon-preview-test.png';
		\file_put_contents( $path, 'LOCAL-ICON-BYTES' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$attachment_id = self::factory()->attachment->create_object(
			$path,
			0,
			array( 'post_mime_type' => 'image/png' )
		);
		\update_option( 'site_icon', $attachment_id );

		$attempted     = false;
		$short_circuit = static function () use ( &$attempted ) {
			$attempted = true;
			return array( 'blob' => array( 'cid' => 'bafyupload' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $short_circuit );

		$records = ( new Publication( null ) )->get_preview_records();

		\remove_filter( 'atmosphere_pre_upload_blob', $short_circuit );
		\wp_delete_file( $path );

		$this->assertFalse( $attempted, 'Previewing must not upload blobs.' );
		$this->assertArrayNotHasKey( 'icon', $records[0] );
		$this->assertSame( '', (string) \get_post_meta( $attachment_id, '_atmosphere_blob_ref', true ) );
	}
}
