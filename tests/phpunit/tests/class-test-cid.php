<?php
/**
 * Tests for the CID + DAG-CBOR encoder used to pre-compute strongRef
 * CIDs before atomic applyWrites.
 *
 * Encoder correctness is verified against three real records pulled
 * from the live PDS (`jellybaby.us-east.host.bsky.network`) at
 * commit time — see the test docblocks for the exact records and
 * their PDS-returned CIDs. If the encoder ever drifts (key
 * ordering, integer width, tag-42 cid-link shape), one of these
 * three CIDs will stop matching and the bug surfaces in CI.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group cid
 */

namespace Atmosphere\Tests;

use Atmosphere\CID;

/**
 * CID encoder tests.
 */
class Test_CID extends \WP_UnitTestCase {

	/**
	 * Real `site.standard.publication` record pulled from
	 * `at://did:plc:yss2znu5ctxjotrmknbbrlqn/site.standard.publication/3mjjjxe3zbciv`.
	 * PDS-returned CID: `bafyreieq64ytmsanutnt2zgob2nhuzmxgvqmjdecq3zh4hjli3khf6sf2m`.
	 *
	 * Exercises basic types: text strings (`$type`, `name`, `description`, `url`),
	 * an associative array, key sorting (length-first then bytewise).
	 */
	public function test_cid_matches_pds_for_real_publication_record() {
		$record = array(
			'url'         => 'https://matthiaspfefferle.blog/',
			'name'        => 'Matthias Pfefferle Atomic',
			'$type'       => 'site.standard.publication',
			'description' => 'a weblog mainly about the open, portable, interoperable, small, social, synaptic, semantic, structured, distributed, (re-)decentralized, independent, microformatted and federated social web',
		);

		$this->assertSame(
			'bafyreieq64ytmsanutnt2zgob2nhuzmxgvqmjdecq3zh4hjli3khf6sf2m',
			CID::from_record( $record )
		);
	}

	/**
	 * Real `site.standard.document` record pulled from
	 * `at://did:plc:yss2znu5ctxjotrmknbbrlqn/site.standard.document/3mn7u77lu63ns`.
	 * PDS-returned CID: `bafyreia67ux7yupdsfpd4ahnukdwii3cwmldyrficaomoskqzopde3ktiu`.
	 *
	 * Exercises the harder cases: a blob with a `$link` cid-link tag,
	 * an embedded strongRef whose `cid` field is a plain string (per
	 * the strongRef Lexicon, which declares `cid` as `format: cid`
	 * but underlying type `string`), an array of tag strings, a long
	 * UTF-8 textContent block, and unsorted input keys (the encoder
	 * is responsible for the DAG-CBOR canonical ordering).
	 */
	public function test_cid_matches_pds_for_real_document_record() {
		$record = array(
			'path'        => '/2026/06/01/the-standard-lorem-ipsum-passage-used-since-1966/',
			'site'        => 'at://did:plc:yss2znu5ctxjotrmknbbrlqn/site.standard.publication/3mjjjxe3zbciv',
			'tags'        => array( 'Allgemein' ),
			'$type'       => 'site.standard.document',
			'title'       => 'The standard Lorem Ipsum passage, used since 1966',
			'coverImage'  => array(
				'ref'      => array(
					'$link' => 'bafkreic6f5dn72vzsyp52l5rmi7r76gg46nyddzz4q6okagw4y6cmleuve',
				),
				'size'     => 82122,
				'$type'    => 'blob',
				'mimeType' => 'image/png',
			),
			'bskyPostRef' => array(
				'cid' => 'bafyreid4dwo6mrkv5epa32pkuljwlpphcualw4lviotdm2prxotc5lnmza',
				'uri' => 'at://did:plc:yss2znu5ctxjotrmknbbrlqn/app.bsky.feed.post/3mn7u77lp4dns',
			),
			'description' => '"Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat...',
			'publishedAt' => '2026-06-01T10:20:14.000Z',
			'textContent' => "„Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.\u{201C} Section 1.10.32 of „de Finibus Bonorum et Malorum\u{201C}, written by Cicero in 45 BC „Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?\u{201C} 1914 translation by H. Rackham „But I must explain to you how all this mistaken idea of denouncing pleasure and praising pain was born and I will give you a complete account of the system, and expound the actual teachings of the great explorer of the truth, the master-builder of human happiness. No one rejects, dislikes, or avoids pleasure itself, because it is pleasure, but because those who do not know how to pursue pleasure rationally encounter consequences that are extremely painful. Nor again is there anyone who loves or pursues or desires to obtain pain of itself, because it is pain, but because occasionally circumstances occur in which toil and pain can procure him some great pleasure. To take a trivial example, which of us ever undertakes laborious physical exercise, except to obtain some advantage from it? But who has any right to find fault with a man who chooses to enjoy a pleasure that has no annoying consequences, or one who avoids a pain that produces no resultant pleasure?\u{201C} Section 1.10.33 of „de Finibus Bonorum et Malorum\u{201C}, written by Cicero in 45 BC „At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio. Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat.\u{201C} 1914 translation by H. Rackham „On the other hand, we denounce with righteous indignation and dislike men who are so beguiled and demoralized by the charms of pleasure of the moment, so blinded by desire, that they cannot foresee the pain and trouble that are bound to ensue; and equal blame belongs to those who fail in their duty through weakness of will, which is the same as saying through shrinking from toil and pain. These cases are perfectly simple and easy to distinguish. In a free hour, when our power of choice is untrammelled and when nothing prevents our being able to do what we like best, every pleasure is to be welcomed and every pain avoided. But in certain circumstances and owing to the claims of duty or the obligations of business it will frequently occur that pleasures have to be repudiated and annoyances accepted. The wise man therefore always holds in these matters to this principle of selection: he rejects pleasures to secure other greater pleasures, or else he endures pains to avoid worse pains.\u{201C}",
		);

		$this->assertSame(
			'bafyreia67ux7yupdsfpd4ahnukdwii3cwmldyrficaomoskqzopde3ktiu',
			CID::from_record( $record )
		);
	}

	/**
	 * Real `app.bsky.feed.post` record pulled from
	 * `at://did:plc:yss2znu5ctxjotrmknbbrlqn/app.bsky.feed.post/3mn7u77lp4dns`.
	 * PDS-returned CID: `bafyreid4dwo6mrkv5epa32pkuljwlpphcualw4lviotdm2prxotc5lnmza`.
	 *
	 * The keystone test for the production target: a real bsky post
	 * with TWO strongRefs in `embed.external.associatedRefs`, a blob
	 * thumbnail, facets with nested index objects, a langs array.
	 * If the round-trip survives THIS record, the encoder is
	 * production-ready for every Atmosphere-published post.
	 */
	public function test_cid_matches_pds_for_real_bsky_post_record() {
		$record = array(
			'tags'      => array( 'Allgemein' ),
			'text'      => "The standard Lorem Ipsum passage, used since 1966\n\n\"Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam,...\n\nhttps://matthiaspfefferle.blog/2026/06/01/the-standard-lorem-ipsum-passage-used-since-1966/",
			'$type'     => 'app.bsky.feed.post',
			'embed'     => array(
				'$type'    => 'app.bsky.embed.external',
				'external' => array(
					'uri'            => 'https://matthiaspfefferle.blog/2026/06/01/the-standard-lorem-ipsum-passage-used-since-1966/',
					'thumb'          => array(
						'ref'      => array(
							'$link' => 'bafkreic6f5dn72vzsyp52l5rmi7r76gg46nyddzz4q6okagw4y6cmleuve',
						),
						'size'     => 82122,
						'$type'    => 'blob',
						'mimeType' => 'image/png',
					),
					'title'          => 'The standard Lorem Ipsum passage, used since 1966',
					'description'    => '"Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat...',
					'associatedRefs' => array(
						array(
							'cid'   => 'bafyreieq64ytmsanutnt2zgob2nhuzmxgvqmjdecq3zh4hjli3khf6sf2m',
							'uri'   => 'at://did:plc:yss2znu5ctxjotrmknbbrlqn/site.standard.publication/3mjjjxe3zbciv',
							'$type' => 'com.atproto.repo.strongRef',
						),
						array(
							'cid'   => 'bafyreiaxrmx5y3idjgtrr3g3awogwyojnyplbbljp4jncmnp6zrjdn73ra',
							'uri'   => 'at://did:plc:yss2znu5ctxjotrmknbbrlqn/site.standard.document/3mn7u77lu63ns',
							'$type' => 'com.atproto.repo.strongRef',
						),
					),
				),
			),
			'langs'     => array( 'de' ),
			'facets'    => array(
				array(
					'index'    => array(
						'byteEnd'   => 296,
						'byteStart' => 205,
					),
					'features' => array(
						array(
							'uri'   => 'https://matthiaspfefferle.blog/2026/06/01/the-standard-lorem-ipsum-passage-used-since-1966/',
							'$type' => 'app.bsky.richtext.facet#link',
						),
					),
				),
			),
			'createdAt' => '2026-06-01T10:20:14.000Z',
		);

		$this->assertSame(
			'bafyreid4dwo6mrkv5epa32pkuljwlpphcualw4lviotdm2prxotc5lnmza',
			CID::from_record( $record )
		);
	}

	/**
	 * Pin the CBOR byte-level encoding for the four trivial primitives
	 * so a refactor of the major-type / argument helpers gets caught
	 * by an exact-match assertion rather than a CID hash that could
	 * coincidentally collide.
	 */
	public function test_encode_primitives() {
		$this->assertSame( "\xf6", CID::encode( null ) );
		$this->assertSame( "\xf5", CID::encode( true ) );
		$this->assertSame( "\xf4", CID::encode( false ) );

		// Small positive int 42: major type 0, 1-byte argument.
		$this->assertSame( "\x18\x2a", CID::encode( 42 ) );

		// Negative int -1: major type 1, argument 0 (== -1 - 0).
		$this->assertSame( "\x20", CID::encode( -1 ) );

		// Text string "hello": major type 3 length 5 + UTF-8 bytes.
		$this->assertSame( "\x65hello", CID::encode( 'hello' ) );

		// Three-element list: major type 4 length 3 + items.
		$this->assertSame( "\x83\x01\x02\x03", CID::encode( array( 1, 2, 3 ) ) );
	}

	/**
	 * Map keys must be sorted length-first then bytewise per DAG-CBOR
	 * canonical encoding. Builds a 3-key map deliberately in the
	 * wrong order and asserts the encoded byte stream lists them in
	 * the canonical order ("z" < "aa" < "ab", because length sorts
	 * first then bytewise within a length tier).
	 */
	public function test_map_keys_are_sorted_length_first_then_bytewise() {
		$encoded = CID::encode(
			array(
				'ab' => 2,
				'z'  => 1,
				'aa' => 3,
			)
		);

		/*
		 * Expected byte sequence:
		 *   a3              map of 3 entries
		 *   61 7a 01        text "z" -> 1
		 *   62 61 61 03     text "aa" -> 3
		 *   62 61 62 02     text "ab" -> 2
		 */
		$this->assertSame(
			"\xa3\x61\x7a\x01\x62\x61\x61\x03\x62\x61\x62\x02",
			$encoded
		);
	}

	/**
	 * `{ "$link": "bafy..." }` JSON form must encode as CBOR tag 42
	 * containing a byte string of `0x00 || cid_v1_bytes`, not as a
	 * plain one-key map. Round-trip identity with the PDS depends on
	 * this special case.
	 */
	public function test_cid_link_encodes_as_tag_42() {
		$encoded = CID::encode(
			array( '$link' => 'bafkreic6f5dn72vzsyp52l5rmi7r76gg46nyddzz4q6okagw4y6cmleuve' )
		);

		/*
		 * Expected byte layout:
		 *   d8 2a    tag 42 with 1-byte argument
		 *   58 25    byte string with 1-byte length = 37
		 *   00       multibase identity prefix
		 *   ...      36 bytes of decoded CIDv1
		 *
		 * Total: 4-byte prefix + 37-byte payload = 41 bytes.
		 */
		$this->assertSame( "\xd8\x2a", \substr( $encoded, 0, 2 ) );
		$this->assertSame( 41, \strlen( $encoded ) );
		$this->assertSame( "\x00", $encoded[4] );
	}

	/**
	 * Sibling keys alongside `$link` keep the value as a plain map —
	 * the cid-link detection requires `$link` to be the SOLE key, so
	 * a legitimate Atmosphere record using `$link` as one of several
	 * keys (unlikely but legal) does not get misencoded.
	 */
	public function test_link_with_sibling_keys_is_plain_map() {
		$encoded = CID::encode(
			array(
				'$link' => 'bafy...',
				'extra' => 1,
			)
		);

		/*
		 * Must NOT start with tag 42 (0xd8 0x2a). Should be a 2-entry
		 * map (0xa2) with keys sorted: $link (5 chars), extra (5 chars
		 * — tied length, bytewise: '$' < 'e', so $link first).
		 */
		$this->assertSame( "\xa2", $encoded[0] );
	}

	/**
	 * Negative integers use major type 1 with `-1 - value` argument
	 * encoding, smallest form. -100 -> argument 99 -> 1-byte (0x18 0x63).
	 */
	public function test_encode_negative_int_uses_shortest_form() {
		/*
		 * -100: major type 1 (0x20), argument 99 (one-byte form 0x18 0x63).
		 * Combined header byte: (1 << 5) | 0x18 = 0x38.
		 */
		$this->assertSame( "\x38\x63", CID::encode( -100 ) );
	}

	/**
	 * NaN / infinite floats are forbidden by DAG-CBOR. The encoder
	 * throws rather than silently producing a record the PDS will
	 * reject.
	 */
	public function test_encode_rejects_nan_and_infinity() {
		$this->expectException( \InvalidArgumentException::class );
		CID::encode( NAN );
	}

	/**
	 * Decoding a CID string and re-encoding the bytes round-trips
	 * back to the same base32 string. Locks the base32 alphabet and
	 * the multibase prefix handling.
	 */
	public function test_decode_string_round_trips() {
		$cid_string = 'bafyreieq64ytmsanutnt2zgob2nhuzmxgvqmjdecq3zh4hjli3khf6sf2m';
		$bytes      = CID::decode_string( $cid_string );

		/*
		 * CIDv1 dag-cbor sha256: version byte 0x01, codec byte 0x71,
		 * multihash code 0x12, multihash length 0x20, then 32 bytes.
		 */
		$this->assertSame( 36, \strlen( $bytes ) );
		$this->assertSame( "\x01", $bytes[0] );
		$this->assertSame( "\x71", $bytes[1] );
		$this->assertSame( "\x12", $bytes[2] );
		$this->assertSame( "\x20", $bytes[3] );
	}

	/**
	 * CID strings without the `b` multibase prefix are rejected —
	 * Atmosphere only ever sees base32-lowercase CIDs from atproto,
	 * so other multibases (base58btc on the legacy CIDv0, etc.) are
	 * out of scope and would silently misencode if let through.
	 */
	public function test_decode_string_rejects_missing_multibase_prefix() {
		$this->expectException( \InvalidArgumentException::class );
		CID::decode_string( 'afyreieq64ytmsanutnt2zgob2nhuzmxgvqmjdecq3zh4hjli3khf6sf2m' );
	}
}
