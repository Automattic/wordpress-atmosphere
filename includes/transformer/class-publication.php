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

use function Atmosphere\get_did;

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
	 * Option key for the publication AT-URI.
	 *
	 * @var string
	 */
	public const OPTION_URI = 'atmosphere_publication_uri';

	/**
	 * Transform site settings into a publication record.
	 *
	 * @return array site.standard.publication record.
	 */
	public function transform(): array {
		$record = array(
			'$type'       => 'site.standard.publication',
			'url'         => \home_url( '/' ),
			'name'        => \get_bloginfo( 'name' ),
			'displayName' => \get_bloginfo( 'name' ),
			'description' => \get_bloginfo( 'description' ),
		);

		// Site icon as avatar.
		$icon_id = \get_option( 'site_icon' );
		if ( $icon_id ) {
			$blob = Post::upload_thumbnail( (int) $icon_id );
			if ( $blob ) {
				$record['avatar'] = $blob;
			}
		}

		// Theme colors.
		$theme = $this->extract_theme();
		if ( $theme ) {
			$record['theme'] = $theme;
		}

		/**
		 * Filters the site.standard.publication record.
		 *
		 * @param array $record Publication record.
		 */
		return \apply_filters( 'atmosphere_transform_publication', $record );
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
	 * Extract theme colours from the active theme.
	 *
	 * @return array|null
	 */
	private function extract_theme(): ?array {
		// Block theme: global styles.
		if ( \function_exists( 'wp_get_global_styles' ) ) {
			$styles = \wp_get_global_styles();

			$bg   = $styles['color']['background'] ?? '';
			$text = $styles['color']['text'] ?? '';

			$theme = array();

			if ( $bg ) {
				$rgb = self::hex_to_rgb( $bg );
				if ( $rgb ) {
					$theme['backgroundColor'] = $rgb;
				}
			}

			if ( $text ) {
				$rgb = self::hex_to_rgb( $text );
				if ( $rgb ) {
					$theme['textColor'] = $rgb;
				}
			}

			if ( ! empty( $theme ) ) {
				return $theme;
			}
		}

		// Classic theme: background_color mod.
		$bg_hex = \get_theme_mod( 'background_color' );
		if ( $bg_hex ) {
			$rgb = self::hex_to_rgb( '#' . \ltrim( $bg_hex, '#' ) );
			if ( $rgb ) {
				return array( 'backgroundColor' => $rgb );
			}
		}

		return null;
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
