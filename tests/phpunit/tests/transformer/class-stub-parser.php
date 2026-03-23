<?php
/**
 * Stub content parser for testing.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Transformer;

use Atmosphere\Content_Parser\Content_Parser;

/**
 * Stub content parser that returns raw content as-is.
 */
class Stub_Parser implements Content_Parser {

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'test.stub.parser';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $content Raw post content.
	 * @param \WP_Post $post    The WordPress post object.
	 */
	public function parse( string $content, \WP_Post $post ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return array(
			'$type' => 'test.stub.parser',
			'text'  => $content,
		);
	}
}
