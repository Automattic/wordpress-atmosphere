<?php
/**
 * Registry of content parsers.
 *
 * Holds every registered Content_Parser and selects the single parser
 * used for a post's `site.standard.document` content field. The
 * content field is a single open-union object, so exactly one parser
 * wins per document.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Content_Parser;

\defined( 'ABSPATH' ) || exit;

/**
 * Static content-parser registry.
 */
class Registry {

	/**
	 * Option storing the site's preferred content format (an NSID).
	 *
	 * An empty value means "automatic" — pick by priority.
	 */
	const OPTION_FORMAT = 'atmosphere_content_format';

	/**
	 * Registered parsers as { parser, priority } entries.
	 *
	 * @var array<int,array{parser:Content_Parser,priority:int}>
	 */
	private static array $parsers = array();

	/**
	 * Register a content parser.
	 *
	 * Re-registering the same NSID replaces the prior entry, so a
	 * later registration (e.g. a site override) can supersede a default
	 * without leaving a duplicate.
	 *
	 * @param Content_Parser $parser   The parser instance.
	 * @param int            $priority Lower wins when several parsers apply. Default 20.
	 * @return void
	 */
	public static function register( Content_Parser $parser, int $priority = 20 ): void {
		self::$parsers[ $parser->get_type() ] = array(
			'parser'   => $parser,
			'priority' => $priority,
		);
	}

	/**
	 * Remove a registered parser by NSID.
	 *
	 * @param string $type The parser NSID.
	 * @return void
	 */
	public static function unregister( string $type ): void {
		unset( self::$parsers[ $type ] );
	}

	/**
	 * Reset the registry. Test helper.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset(): void {
		self::$parsers = array();
	}

	/**
	 * All registered parsers, sorted by ascending priority.
	 *
	 * @return Content_Parser[] Keyed by NSID.
	 */
	public static function all(): array {
		$entries = self::$parsers;

		\uasort(
			$entries,
			static function ( array $a, array $b ): int {
				$priority = $a['priority'] <=> $b['priority'];

				return 0 !== $priority ? $priority : \strcmp( $a['parser']->get_type(), $b['parser']->get_type() );
			}
		);

		return \array_map( static fn( $entry ) => $entry['parser'], $entries );
	}

	/**
	 * Whether a parser is registered for the given NSID.
	 *
	 * @param string $type The parser NSID.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return isset( self::$parsers[ $type ] );
	}

	/**
	 * Select the parser for a post.
	 *
	 * Considers only parsers that apply to the post. A parser may expose
	 * an optional applies_to( \WP_Post $post ): bool method; parsers
	 * without that method are treated as applicable for compatibility. If
	 * the configured `atmosphere_content_format` names an applicable
	 * registered parser, it wins; otherwise the lowest-priority-number
	 * applicable parser wins. Returns null when nothing applies.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return Content_Parser|null
	 */
	public static function select( \WP_Post $post ): ?Content_Parser {
		$applicable = array();
		foreach ( self::all() as $type => $parser ) {
			if ( self::parser_applies_to( $parser, $post ) ) {
				$applicable[ $type ] = $parser;
			}
		}

		if ( empty( $applicable ) ) {
			return null;
		}

		$preferred = (string) \get_option( self::OPTION_FORMAT, '' );
		if ( '' !== $preferred && isset( $applicable[ $preferred ] ) ) {
			return $applicable[ $preferred ];
		}

		// all() is already priority-sorted, so the first applicable wins.
		return \reset( $applicable );
	}

	/**
	 * Whether a parser can produce content for a post.
	 *
	 * `applies_to()` is intentionally optional so older third-party
	 * implementations of Content_Parser keep working after the registry
	 * ships. Parser_Base provides the method with a default true result.
	 *
	 * @param Content_Parser $parser The parser instance.
	 * @param \WP_Post       $post   The WordPress post object.
	 * @return bool
	 */
	private static function parser_applies_to( Content_Parser $parser, \WP_Post $post ): bool {
		/*
		 * `is_callable()` rather than `method_exists()`: the latter is true
		 * for protected/private methods too, so a custom parser declaring a
		 * non-public `applies_to()` would pass the guard and then fatal on
		 * the external call. `is_callable()` checks accessibility from here.
		 */
		if ( ! \is_callable( array( $parser, 'applies_to' ) ) ) {
			return true;
		}

		return (bool) $parser->applies_to( $post );
	}
}
