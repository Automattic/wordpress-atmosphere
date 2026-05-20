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
	 * Whether parse() should return null.
	 *
	 * @var bool
	 */
	public bool $return_null = false;

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
	public function parse( string $content, \WP_Post $post ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( $this->return_null ) {
			return null;
		}

		return array(
			'$type' => 'test.stub.parser',
			'text'  => $content,
		);
	}
}
