<?php
/**
 * Configurable fake parser for registry tests.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Content_Parser;

use Atmosphere\Content_Parser\Content_Parser;

/**
 * A parser whose NSID and applicability are set at construction.
 */
class Fake_Parser implements Content_Parser {

	/**
	 * NSID this parser reports.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Whether applies_to() returns true.
	 *
	 * @var bool
	 */
	private bool $applies;

	/**
	 * Constructor.
	 *
	 * @param string $type    NSID.
	 * @param bool   $applies Whether the parser applies.
	 */
	public function __construct( string $type, bool $applies = true ) {
		$this->type    = $type;
		$this->applies = $applies;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_Post $post The WordPress post object.
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
		return array( '$type' => $this->type );
	}
}
