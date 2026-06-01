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
	 * Stored so callers building strongRefs (currently the link-card
	 * `embed.external.associatedRefs` array on outbound posts) don't need
	 * to round-trip `getRecord` for a value the PDS already returned to
	 * us when the publication was last written. The CID is allowed to be
	 * stale relative to the live record — `getRecord` resolves by URI,
	 * and the strongRef's CID just freezes the version we observed at
	 * publish time, exactly the same snapshot semantics Bluesky's own
	 * share UI uses when it builds `associatedRefs` from a URL's
	 * standard.site links.
	 *
	 * `OPTION_TID` is the stable AT-URI identifier and survives
	 * reconnect-to-the-same-account; the CID rotates every time the
	 * publication record's content changes (theme color, site title,
	 * etc.) and is re-captured on the next sync. Both options are
	 * cleared on uninstall; neither is cleared on disconnect for the
	 * same reason TID is preserved across disconnect today.
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
		// WordPress stores the site name and tagline HTML-entity encoded
		// (esc_html at save time). sanitize_text() strips tags, decodes
		// those entities, and collapses whitespace, so the record carries
		// clean plain text rather than codes like `&#039;`.
		$record = array(
			'$type'       => 'site.standard.publication',
			'url'         => \home_url( '/' ),
			'name'        => sanitize_text( \get_bloginfo( 'name' ) ),
			'description' => sanitize_text( \get_bloginfo( 'description' ) ),
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

		// Theme colors.
		$theme = $this->extract_theme();
		if ( $theme ) {
			$record['theme'] = $theme;
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
	 * pointing at the connected site's publication record, or null when
	 * the strongRef cannot be safely constructed.
	 *
	 * Both the TID and the CID are required: the URI half is derivable
	 * from the connected DID + the stored TID, but the strongRef shape
	 * also needs the content-hash from {@see self::OPTION_CID}, which is
	 * only populated after {@see \Atmosphere\Publisher::sync_publication()}
	 * has successfully written the publication and read back the CID
	 * from the PDS response. A fresh-connect install that has not yet
	 * triggered a sync (typically through one of the `update_option_*`
	 * triggers wired up in `Atmosphere::init()`) returns null here and
	 * the caller — currently the link-card embed builder in
	 * {@see \Atmosphere\Transformer\Post::build_embed()} — emits the
	 * `embed.external` block without `associatedRefs`. The next sync
	 * captures the CID and subsequent publishes pick the ref up
	 * automatically.
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
