<?php
/**
 * Tests for the `wp atmosphere backfill` command's input parser.
 *
 * This file covers the static `parse_ids()` parser. The command's
 * outer flow (argument validation, routing, exit codes, the publish
 * loop) is exercised in {@see Test_Backfill_Command_Invoke}.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group cli
 */

namespace Atmosphere\Tests\Cli;

use Atmosphere\Cli\Backfill_Command;

/**
 * Backfill command parse_ids tests.
 */
class Test_Backfill_Command extends \WP_UnitTestCase {

	/**
	 * Data provider for {@see test_parse_ids()}.
	 *
	 * Each row exercises the documented contract: the parser returns a
	 * `{ids, rejected}` array. Tokens that are empty after trim are
	 * skipped silently (trailing comma is not user error). Zero / negative
	 * digits round-trip cleanly to an empty `ids` slot without ever being
	 * rejected (operator clearly did not mean to publish post 0). Anything
	 * else non-digit lands in `rejected` so the CLI can refuse to run.
	 *
	 * @return array<string, array{0: string, 1: int[], 2: string[]}>
	 */
	public function data_parse_ids(): array {
		return array(
			'simple csv'                 => array( '1,2,3', array( 1, 2, 3 ), array() ),
			'whitespace tolerated'       => array( ' 1 , 2 ', array( 1, 2 ), array() ),
			'dedupes repeats'            => array( '1,1,2', array( 1, 2 ), array() ),
			'zero drops silently'        => array( '0,4', array( 4 ), array() ),
			'negative is rejected'       => array( '-3,4', array( 4 ), array( '-3' ) ),
			'empty string'               => array( '', array(), array() ),
			'whitespace and commas only' => array( ' , , ', array(), array() ),
			'preserves user order'       => array( '7,3,11', array( 7, 3, 11 ), array() ),
			'non-digit is rejected'      => array( 'abc,5,foo', array( 5 ), array( 'abc', 'foo' ) ),
			'order survives dedup'       => array( '3,1,3,2,1', array( 3, 1, 2 ), array() ),
		);
	}

	/**
	 * The CLI input-parsing rules are part of the documented contract,
	 * exercised here directly rather than through the WP-CLI runtime.
	 *
	 * @dataProvider data_parse_ids
	 *
	 * @param string   $raw      Raw flag value.
	 * @param int[]    $ids      Expected parsed IDs.
	 * @param string[] $rejected Expected raw tokens that failed strict validation.
	 */
	public function test_parse_ids( string $raw, array $ids, array $rejected ) {
		$parsed = Backfill_Command::parse_ids( $raw );

		$this->assertSame( $ids, $parsed['ids'] );
		$this->assertSame( $rejected, $parsed['rejected'] );
	}

	/**
	 * Regression test for the codex finding: PHP's `(int)` cast silently
	 * truncates `"1.5"` to 1 and `"123abc"` to 123. Strict parsing must
	 * surface those tokens via `rejected` so the CLI can abort before any
	 * publish — a typo that publishes a different post than the operator
	 * typed is a worse outcome than refusing to run.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function data_parse_ids_rejects_partially_numeric_tokens(): array {
		return array(
			'decimal'         => array( '1.5', array( '1.5' ) ),
			'trailing junk'   => array( '123abc', array( '123abc' ) ),
			'range syntax'    => array( '1-2', array( '1-2' ) ),
			'mixed valid bad' => array( '1,2,abc', array( 'abc' ) ),
			'leading sign'    => array( '+5', array( '+5' ) ),
		);
	}

	/**
	 * Assert that the rejection list matches the expected raw tokens.
	 *
	 * @dataProvider data_parse_ids_rejects_partially_numeric_tokens
	 *
	 * @param string   $raw      Raw flag value.
	 * @param string[] $rejected Expected raw tokens in the `rejected` list.
	 */
	public function test_parse_ids_rejects_partially_numeric_tokens( string $raw, array $rejected ) {
		$parsed = Backfill_Command::parse_ids( $raw );

		$this->assertSame( $rejected, $parsed['rejected'] );
	}
}
