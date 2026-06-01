<?php
/**
 * AT Protocol CID computation.
 *
 * Implements the minimum DAG-CBOR encoder + CIDv1 builder needed to
 * pre-compute the CID of a record we are about to write, so a
 * strongRef pointing at that record can ride along in the same
 * atomic `applyWrites` batch. Without this, AT Protocol's chicken-
 * and-egg constraint — strongRefs need the target's CID, which only
 * exists after the write returns — forces a follow-up
 * `applyWrites#update`. The follow-up does land at the PDS but
 * Bluesky's AppView indexes posts at initial create only, ignoring
 * later updates for `source` / `associatedProfiles` enrichment, so
 * the visible UI never picks up the added refs (verified on
 * `atmosphere-blog.bsky.social` 3mn7u77lp4dns — PDS holds both refs,
 * AppView serves the initial one-ref version indefinitely).
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * CID generator + DAG-CBOR encoder targeted at the AT Protocol
 * record subset Atmosphere produces.
 *
 * Scope:
 *   - Encodes the types Atmosphere records actually use: null,
 *     booleans, integers, UTF-8 strings, arrays, maps, and cid-link
 *     tags via the `{ "$link": "bafy..." }` JSON convention.
 *   - Treats maps with exactly one string key `$link` as cid-link
 *     references (CBOR tag 42), matching the canonical atproto data
 *     model JSON form. Other map shapes encode as plain CBOR maps.
 *   - Sorts map keys length-first then bytewise per DAG-CBOR.
 *   - Uses shortest integer encoding (DAG-CBOR requires it).
 *   - Throws on NaN / Infinity floats — disallowed by DAG-CBOR.
 *
 * Out of scope: indefinite-length encoding (DAG-CBOR forbids it),
 * float16 / float32, arbitrary tags beyond 42, big-int beyond
 * PHP_INT_MAX (Atmosphere records do not produce those).
 */
class CID {

	/**
	 * Compute the CIDv1 base32 string of a record using AT Protocol's
	 * encoding (DAG-CBOR + SHA-256 + dag-cbor codec).
	 *
	 * The returned value matches what the PDS round-trips back in
	 * `applyWrites` / `getRecord` responses, byte-for-byte. Round-trip
	 * stability is the contract: if a third party encodes the same
	 * record and computes its CID independently, they must get the
	 * same string.
	 *
	 * Returns a `WP_Error` if the encoder hits a record shape it cannot
	 * handle (an unsupported value type, NaN / Infinity float, a
	 * malformed `$link` cid-link, etc.). Callers in the publish path
	 * treat that as "skip the optional strongRef" rather than aborting
	 * the publish — the record still ships, just without the
	 * `associatedRefs` entry that depended on the failed CID.
	 *
	 * @param array $record Record value (typically a transformer's
	 *                      `transform()` output).
	 * @return string|\WP_Error CID string with multibase prefix `b`, or
	 *                          an error from the encoder.
	 */
	public static function from_record( array $record ): string|\WP_Error {
		$bytes = self::encode( $record );
		if ( \is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$digest = \hash( 'sha256', $bytes, true );

		/*
		 * Build the CIDv1 byte string:
		 *   0x01 — CIDv1 version
		 *   0x71 — multicodec for dag-cbor
		 *   0x12 — multihash code for sha2-256
		 *   0x20 — multihash digest length (32 bytes)
		 *   $digest — the 32-byte SHA-256 hash
		 *
		 * Multibase-prefixed with `b` to flag base32 lowercase (RFC 4648,
		 * no padding) per the atproto data model spec.
		 */
		$cid_bytes = "\x01\x71\x12\x20" . $digest;

		return 'b' . self::base32_lower_encode( $cid_bytes );
	}

	/**
	 * Decode a CID string (e.g. `bafyrei...`) back to its raw bytes.
	 *
	 * Used inside this class to reconstruct the byte form when
	 * encoding cid-link tags, and exposed for callers that need to
	 * verify a CID independently of the multibase form.
	 *
	 * @param string $cid_string CID string (must start with `b` multibase prefix).
	 * @return string|\WP_Error Raw CIDv1 bytes, or `atmosphere_cid_invalid_*`
	 *                          when the prefix is missing or the base32 body
	 *                          contains characters outside the alphabet.
	 */
	public static function decode_string( string $cid_string ): string|\WP_Error {
		if ( '' === $cid_string || 'b' !== $cid_string[0] ) {
			return new \WP_Error(
				'atmosphere_cid_invalid_multibase',
				\sprintf(
					/* translators: %s: the raw CID string the caller passed in. */
					\__( 'CID must use the base32 multibase prefix "b": %s', 'atmosphere' ),
					$cid_string
				)
			);
		}

		$decoded = self::base32_lower_decode( \substr( $cid_string, 1 ) );
		if ( false === $decoded ) {
			return new \WP_Error(
				'atmosphere_cid_invalid_base32',
				\__( 'CID body contains characters outside the base32 alphabet.', 'atmosphere' )
			);
		}

		return $decoded;
	}

	/**
	 * Encode an arbitrary PHP value as DAG-CBOR bytes.
	 *
	 * Dispatches by PHP type. The only non-obvious mapping is the
	 * `{ "$link": "bafy..." }` shape — a one-key associative array
	 * whose key is exactly `$link` and value is a string is encoded
	 * as a CBOR tag-42 CID rather than a plain map, matching the
	 * canonical JSON form for cid-link types in the atproto data
	 * model. Callers that legitimately want a literal `$link` map
	 * key with multiple sibling keys are unaffected (the detector
	 * requires exactly one key).
	 *
	 * @param mixed $value PHP value.
	 * @return string|\WP_Error DAG-CBOR encoded bytes, or an
	 *                          `atmosphere_cid_*` error for an
	 *                          unsupported value type / shape.
	 */
	public static function encode( $value ): string|\WP_Error {
		if ( null === $value ) {
			return "\xf6";
		}

		if ( true === $value ) {
			return "\xf5";
		}

		if ( false === $value ) {
			return "\xf4";
		}

		if ( \is_int( $value ) ) {
			return self::encode_int( $value );
		}

		if ( \is_string( $value ) ) {
			return self::encode_text_string( $value );
		}

		if ( \is_array( $value ) ) {
			/*
			 * `{ "$link": "bafy..." }` is the atproto JSON form for a
			 * cid-link. Detect that exact shape — exactly one key
			 * named `$link`, value is a string — and emit CBOR tag 42
			 * instead of a plain map. Anything else (two keys, a
			 * different key name, a non-string value) falls through
			 * to the normal map/array encoding.
			 */
			if ( 1 === \count( $value ) && isset( $value['$link'] ) && \is_string( $value['$link'] ) ) {
				return self::encode_cid_link( $value['$link'] );
			}

			if ( \array_is_list( $value ) ) {
				return self::encode_array( $value );
			}

			return self::encode_map( $value );
		}

		if ( \is_float( $value ) ) {
			return self::encode_float( $value );
		}

		return new \WP_Error(
			'atmosphere_cid_unsupported_type',
			\sprintf(
				/* translators: %s: PHP type name (e.g. "object", "resource"). */
				\__( 'DAG-CBOR encoder cannot handle value of type %s.', 'atmosphere' ),
				\gettype( $value )
			)
		);
	}

	/**
	 * Encode a CBOR major-type byte plus the smallest integer
	 * argument encoding that fits the value.
	 *
	 * DAG-CBOR requires the shortest possible encoding for every
	 * integer; this helper centralises that choice across all type
	 * tags (positive ints, negative ints, byte strings, text strings,
	 * arrays, maps). On a 64-bit PHP build the `>= 2^32` branch
	 * handles values up to PHP_INT_MAX via `pack('J', ...)`.
	 *
	 * @param int $major_type CBOR major type (0..7).
	 * @param int $argument   Length / value to encode (must be non-negative).
	 * @return string CBOR header bytes.
	 */
	private static function encode_argument( int $major_type, int $argument ): string {
		$prefix = $major_type << 5;

		if ( $argument < 24 ) {
			return \chr( $prefix | $argument );
		}

		if ( $argument < 256 ) {
			return \chr( $prefix | 0x18 ) . \chr( $argument );
		}

		if ( $argument < 65536 ) {
			return \chr( $prefix | 0x19 ) . \pack( 'n', $argument );
		}

		if ( $argument < 4294967296 ) {
			return \chr( $prefix | 0x1a ) . \pack( 'N', $argument );
		}

		return \chr( $prefix | 0x1b ) . \pack( 'J', $argument );
	}

	/**
	 * Encode an integer.
	 *
	 * Positive ints use major type 0. Negative ints use major type 1
	 * with argument `-1 - value`, the standard CBOR convention for
	 * representing arbitrary negative values without a sign bit.
	 *
	 * @param int $value Integer to encode.
	 * @return string CBOR bytes.
	 */
	private static function encode_int( int $value ): string {
		if ( $value >= 0 ) {
			return self::encode_argument( 0, $value );
		}

		return self::encode_argument( 1, -1 - $value );
	}

	/**
	 * Encode a UTF-8 text string (major type 3).
	 *
	 * @param string $value Text bytes.
	 * @return string CBOR bytes.
	 */
	private static function encode_text_string( string $value ): string {
		return self::encode_argument( 3, \strlen( $value ) ) . $value;
	}

	/**
	 * Encode a byte string (major type 2).
	 *
	 * @param string $value Raw bytes.
	 * @return string CBOR bytes.
	 */
	private static function encode_byte_string( string $value ): string {
		return self::encode_argument( 2, \strlen( $value ) ) . $value;
	}

	/**
	 * Encode a list-shaped array (major type 4).
	 *
	 * Propagates the first `WP_Error` from a nested `encode()` call so
	 * an unsupported value buried deep inside a record short-circuits
	 * the whole encode without producing partial bytes.
	 *
	 * @param array $value List to encode.
	 * @return string|\WP_Error CBOR bytes or the propagated error.
	 */
	private static function encode_array( array $value ): string|\WP_Error {
		$bytes = self::encode_argument( 4, \count( $value ) );

		foreach ( $value as $item ) {
			$encoded = self::encode( $item );
			if ( \is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$bytes .= $encoded;
		}

		return $bytes;
	}

	/**
	 * Encode a map / associative array (major type 5).
	 *
	 * DAG-CBOR requires keys to be sorted length-first (shorter byte
	 * length comes first), then bytewise for ties. This is a strict
	 * canonical-encoding requirement — round-trip equality with the
	 * PDS depends on it.
	 *
	 * @param array $value Map to encode.
	 * @return string|\WP_Error CBOR bytes or the propagated error from
	 *                          a nested `encode()` call.
	 */
	private static function encode_map( array $value ): string|\WP_Error {
		$keys = \array_map( 'strval', \array_keys( $value ) );

		\usort(
			$keys,
			static function ( string $a, string $b ): int {
				$len_diff = \strlen( $a ) - \strlen( $b );
				if ( 0 !== $len_diff ) {
					return $len_diff;
				}
				return \strcmp( $a, $b );
			}
		);

		$bytes = self::encode_argument( 5, \count( $keys ) );
		foreach ( $keys as $key ) {
			$bytes  .= self::encode_text_string( $key );
			$encoded = self::encode( $value[ $key ] );
			if ( \is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$bytes .= $encoded;
		}

		return $bytes;
	}

	/**
	 * Encode a float as IEEE 754 double-precision (major type 7,
	 * additional info 27 / 0xfb).
	 *
	 * DAG-CBOR forbids NaN, +Inf, and -Inf, and forbids the smaller
	 * float16 / float32 representations.
	 *
	 * @param float $value Float to encode.
	 * @return string|\WP_Error CBOR bytes, or
	 *                          `atmosphere_cid_invalid_float` for NaN /
	 *                          ±Infinity.
	 */
	private static function encode_float( float $value ): string|\WP_Error {
		if ( \is_nan( $value ) || \is_infinite( $value ) ) {
			return new \WP_Error(
				'atmosphere_cid_invalid_float',
				\__( 'DAG-CBOR does not permit NaN or Infinity.', 'atmosphere' )
			);
		}

		// 'E' = IEEE 754 double precision, big-endian (PHP 7.0.15+ / 7.1.1+).
		return "\xfb" . \pack( 'E', $value );
	}

	/**
	 * Encode a CID-link as CBOR tag 42 containing a byte string of
	 * `0x00 || cid_v1_bytes`.
	 *
	 * The leading `0x00` is the multibase identity prefix that
	 * DAG-CBOR's cid-link spec requires; without it the encoding
	 * would not round-trip through atproto's own decoders.
	 *
	 * @param string $cid_string CID string (with multibase prefix `b`).
	 * @return string|\WP_Error CBOR bytes, or the propagated decode error.
	 */
	private static function encode_cid_link( string $cid_string ): string|\WP_Error {
		$cid_bytes = self::decode_string( $cid_string );

		if ( \is_wp_error( $cid_bytes ) ) {
			return $cid_bytes;
		}

		// 0xd8 0x2a = CBOR tag 42 with a 1-byte argument.
		return "\xd8\x2a" . self::encode_byte_string( "\x00" . $cid_bytes );
	}


	/**
	 * Base32 RFC 4648 lowercase encode (no padding).
	 *
	 * @param string $bytes Raw bytes.
	 * @return string Base32 string.
	 */
	private static function base32_lower_encode( string $bytes ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$result   = '';
		$buffer   = 0;
		$bits     = 0;
		$length   = \strlen( $bytes );

		for ( $i = 0; $i < $length; $i++ ) {
			$buffer = ( $buffer << 8 ) | \ord( $bytes[ $i ] );
			$bits  += 8;
			while ( $bits >= 5 ) {
				$bits   -= 5;
				$result .= $alphabet[ ( $buffer >> $bits ) & 31 ];
			}
		}

		if ( $bits > 0 ) {
			$result .= $alphabet[ ( $buffer << ( 5 - $bits ) ) & 31 ];
		}

		return $result;
	}

	/**
	 * Base32 RFC 4648 lowercase decode (no padding).
	 *
	 * Mirrors the `string|false` shape of the OAuth crypto / encoder
	 * helpers ({@see \Atmosphere\OAuth\DPoP::base64url_decode()},
	 * {@see \Atmosphere\OAuth\Encryption::decrypt()}) so callers
	 * branch on `false === $decoded` rather than on a thrown
	 * exception.
	 *
	 * @param string $value Base32 string.
	 * @return string|false Raw bytes, or false on a character outside
	 *                      the base32 alphabet.
	 */
	private static function base32_lower_decode( string $value ): string|false {
		static $lookup = null;
		if ( null === $lookup ) {
			$lookup = \array_flip( \str_split( 'abcdefghijklmnopqrstuvwxyz234567' ) );
		}

		$buffer = 0;
		$bits   = 0;
		$result = '';
		$length = \strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];
			if ( ! isset( $lookup[ $char ] ) ) {
				return false;
			}
			$buffer = ( $buffer << 5 ) | $lookup[ $char ];
			$bits  += 5;
			if ( $bits >= 8 ) {
				$bits   -= 8;
				$result .= \chr( ( $buffer >> $bits ) & 0xff );
			}
		}

		return $result;
	}
}
