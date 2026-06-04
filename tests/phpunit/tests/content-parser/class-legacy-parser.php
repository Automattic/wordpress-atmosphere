<?php
/**
 * Legacy parser fixture without applies_to().
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Content_Parser;

use Atmosphere\Content_Parser\Content_Parser;

/**
 * A parser matching the pre-registry public contract.
 */
class Legacy_Parser implements Content_Parser {

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'test.legacy';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $content Raw post content.
	 * @param \WP_Post $post    The WordPress post object.
	 * @return array
	 */
	public function parse( string $content, \WP_Post $post ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return array( '$type' => $this->get_type() );
	}
}
