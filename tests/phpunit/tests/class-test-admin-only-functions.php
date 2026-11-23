<?php
/**
 * Guard against admin-only WordPress functions outside admin code.
 *
 * Publishing, backfill, and reaction sync run from cron, WP-CLI, and REST
 * requests, where WordPress never loads `wp-admin/includes/`. A call to a
 * function defined only there (e.g. `wp_tempnam()`) is a fatal error in
 * those contexts. The test suite can't catch that by running the code,
 * because the PHPUnit bootstrap loads the admin includes, so this scans
 * the source instead.
 *
 * The scan is a tripwire, not a proof: it only sees direct calls, so
 * string callbacks (`call_user_func( 'get_plugins' )`) and admin
 * functions declared inside a `function_exists()` block go unnoticed.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests;

/**
 * Admin-only function usage tests.
 */
class Test_Admin_Only_Functions extends \WP_UnitTestCase {

	/**
	 * Admin-only calls that are safe where they are: either reached only
	 * from an admin request, or guarded by `function_exists()`, which the
	 * scan can't see.
	 *
	 * Keyed by path relative to `includes/`.
	 *
	 * @var array<string, string[]>
	 */
	const ALLOWED = array(
		'class-block-editor.php' => array( 'get_current_screen' ),
		'class-handle.php'       => array( 'add_settings_error', 'get_settings_errors' ),
		'class-sanitize.php'     => array( 'add_settings_error' ),
	);

	/**
	 * Code outside `includes/wp-admin/` must not call admin-only functions.
	 */
	public function test_non_admin_code_does_not_call_admin_only_functions() {
		$admin_functions = $this->admin_only_functions();
		$this->assertContains( 'wp_tempnam', $admin_functions, 'The admin-only function list should be populated.' );

		$includes = \dirname( __DIR__, 3 ) . '/includes/';
		$files    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $includes, \FilesystemIterator::SKIP_DOTS ) );

		$violations = array();
		foreach ( $files as $file ) {
			$relative = \substr( $file->getPathname(), \strlen( $includes ) );
			if ( 'php' !== $file->getExtension() || 0 === \strpos( $relative, 'wp-admin/' ) ) {
				continue;
			}

			$allowed = self::ALLOWED[ $relative ] ?? array();
			foreach ( $this->called_functions( $file->getPathname() ) as $call ) {
				if ( \in_array( $call['name'], $admin_functions, true ) && ! \in_array( $call['name'], $allowed, true ) ) {
					$violations[] = $relative . ':' . $call['line'] . ' ' . $call['name'] . '()';
				}
			}
		}

		$this->assertSame( array(), $violations, 'These calls fatal outside an admin request (cron, WP-CLI, REST).' );
	}

	/**
	 * Collect the top-level functions defined in `wp-admin/includes/`.
	 *
	 * `noop.php` is skipped: it redefines core functions as stubs for the
	 * script loader and isn't a source of admin-only functions.
	 *
	 * @return string[] Lowercased function names.
	 */
	private function admin_only_functions(): array {
		$functions = array();

		foreach ( \glob( ABSPATH . 'wp-admin/includes/*.php' ) as $path ) {
			if ( 'noop.php' === \basename( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$tokens = \token_get_all( \file_get_contents( $path ) );
			$depth  = 0;

			foreach ( $tokens as $i => $token ) {
				if ( '{' === $token || ( \is_array( $token ) && \in_array( $token[0], array( \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $token ) {
					--$depth;
				} elseif ( 0 === $depth && \is_array( $token ) && \T_FUNCTION === $token[0] ) {
					$name = $this->next_meaningful( $tokens, $i );
					if ( \is_array( $name ) && \T_STRING === $name[0] ) {
						$functions[] = \strtolower( $name[1] );
					}
				}
			}
		}

		return \array_unique( $functions );
	}

	/**
	 * List plain function calls in a PHP file.
	 *
	 * Skips method calls, static calls, declarations, and instantiations,
	 * so a method that shares a name with an admin function isn't flagged.
	 *
	 * @param string $path File path.
	 * @return array<int, array{name:string, line:int}> Calls in source order.
	 */
	private function called_functions( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$tokens = \token_get_all( \file_get_contents( $path ) );
		$calls  = array();
		$skip   = array( \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW );

		foreach ( $tokens as $i => $token ) {
			if ( ! \is_array( $token ) || ! \in_array( $token[0], array( \T_STRING, \T_NAME_FULLY_QUALIFIED ), true ) ) {
				continue;
			}

			$previous = $this->previous_meaningful( $tokens, $i );
			if ( \is_array( $previous ) && \in_array( $previous[0], $skip, true ) ) {
				continue;
			}

			if ( '(' === $this->next_meaningful( $tokens, $i ) ) {
				$calls[] = array(
					'name' => \strtolower( \ltrim( $token[1], '\\' ) ),
					'line' => $token[2],
				);
			}
		}

		return $calls;
	}

	/**
	 * Return the next token that isn't whitespace or a comment.
	 *
	 * @param array $tokens Token list.
	 * @param int   $index  Current index.
	 * @return array|string|null Token, or null at the end of the file.
	 */
	private function next_meaningful( array $tokens, int $index ) {
		for ( $i = $index + 1; isset( $tokens[ $i ] ); $i++ ) {
			if ( ! $this->is_noise( $tokens[ $i ] ) ) {
				return $tokens[ $i ];
			}
		}
		return null;
	}

	/**
	 * Return the previous token that isn't whitespace or a comment.
	 *
	 * @param array $tokens Token list.
	 * @param int   $index  Current index.
	 * @return array|string|null Token, or null at the start of the file.
	 */
	private function previous_meaningful( array $tokens, int $index ) {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( ! $this->is_noise( $tokens[ $i ] ) ) {
				return $tokens[ $i ];
			}
		}
		return null;
	}

	/**
	 * Whether a token is whitespace or a comment.
	 *
	 * @param array|string $token Token.
	 * @return bool
	 */
	private function is_noise( $token ): bool {
		return \is_array( $token ) && \in_array( $token[0], array( \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT ), true );
	}
}
