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
	 * Whether parse() should omit the $type field.
	 *
	 * @var bool
	 */
	public bool $omit_type = false;

	/**
	 * The $type value parse() returns.
	 *
	 * @var string
	 */
	public string $output_type = 'test.stub.parser';

	/**
	 * Whether applies_to() should return true.
	 *
	 * @var bool
	 */
	public bool $applies = true;

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'test.stub.parser';
	}

	/**
	 * Whether this parser applies to the post.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return bool
	 */
	public function applies_to( \WP_Post $post ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $this->applies;
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

		$record = array(
			'text' => $content,
		);

		if ( ! $this->omit_type ) {
			$record['$type'] = $this->output_type;
		}

		return $record;
	}
}
