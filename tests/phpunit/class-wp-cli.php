<?php
/**
 * Capturing stub for the WP-CLI runtime facade.
 *
 * Loaded from {@see tests/phpunit/bootstrap.php} so the backfill command's
 * outer flow (`Backfill_Command::__invoke()`) can run under PHPUnit. The
 * real WP-CLI facade is not loaded by the WP test bootstrap. Every call is
 * recorded on the static `$calls` log; `error()` throws {@see WP_CLI_Halt}
 * to mirror WP-CLI halting the process, so tests can assert the
 * non-zero-exit and mid-run abort paths.
 *
 * @package Atmosphere
 */

/*
 * No ABSPATH guard: this file is required from `tests/phpunit/bootstrap.php`
 * *before* WordPress core's test bootstrap defines ABSPATH (see the sibling
 * `class-wp-cli-command.php` for the full rationale). The `class_exists`
 * guard below prevents a real WP-CLI runtime from being re-declared.
 */

if ( ! \class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal capturing stub of the WP-CLI facade.
	 */
	class WP_CLI {

		/**
		 * Captured calls. Each entry is `array{level: string, message: string}`.
		 *
		 * @var array<int, array<string, string>>
		 */
		public static $calls = array();

		/**
		 * Reset the captured-call log. Call from a test's `set_up()`.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$calls = array();
		}

		/**
		 * Messages captured at a given level, in call order.
		 *
		 * @param string $level Level to filter by (log|line|success|warning|error).
		 * @return string[]
		 */
		public static function messages( $level ) {
			$out = array();

			foreach ( self::$calls as $call ) {
				if ( $call['level'] === $level ) {
					$out[] = $call['message'];
				}
			}

			return $out;
		}

		/**
		 * Capture a log line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function log( $message ) {
			self::$calls[] = array(
				'level'   => 'log',
				'message' => (string) $message,
			);
		}

		/**
		 * Capture a raw line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function line( $message = '' ) {
			self::$calls[] = array(
				'level'   => 'line',
				'message' => (string) $message,
			);
		}

		/**
		 * Capture a success message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ) {
			self::$calls[] = array(
				'level'   => 'success',
				'message' => (string) $message,
			);
		}

		/**
		 * Capture a warning message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function warning( $message ) {
			self::$calls[] = array(
				'level'   => 'warning',
				'message' => (string) $message,
			);
		}

		/**
		 * Capture an error message and, when halting, throw to mirror the
		 * WP-CLI runtime ending the process.
		 *
		 * @param string|\WP_Error $message Message or error object.
		 * @param bool|int         $halt    Whether the runtime would halt.
		 * @return void
		 * @throws WP_CLI_Halt When `$halt` is truthy.
		 */
		public static function error( $message, $halt = true ) {
			if ( \is_object( $message ) && \method_exists( $message, 'get_error_message' ) ) {
				$text = $message->get_error_message();
			} else {
				$text = (string) $message;
			}

			self::$calls[] = array(
				'level'   => 'error',
				'message' => $text,
			);

			if ( $halt ) {
				/*
				 * Throw the raw text so assertions see the real message,
				 * matching the WP-CLI runtime (which does not HTML-escape).
				 */
				throw new WP_CLI_Halt( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
	}
}
