<?php
/**
 * Plugin-wide logger.
 *
 * Centralizes log output so every call site uses the same format and
 * the same destinations. Two destinations:
 *
 *  1. PHP's error_log (lands in wp-content/debug.log when WP_DEBUG_LOG is on).
 *     All levels go here.
 *  2. A ring buffer of the last MAX_ENTRIES entries stored in a wp_option,
 *     so the admin UI can show "Recent Activity" without grepping disk.
 *     DEBUG entries are excluded from the buffer to keep it small.
 *
 * Levels:
 *  - error / warn: ALWAYS written, regardless of WP_DEBUG.
 *  - info:         always written.
 *  - debug:        only written when WP_DEBUG is true.
 *
 * The buffer is autoload=false on the option so it doesn't bloat every
 * request's options load.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	public const LEVEL_DEBUG = 'debug';
	public const LEVEL_INFO  = 'info';
	public const LEVEL_WARN  = 'warn';
	public const LEVEL_ERROR = 'error';

	public const OPTION_KEY  = 'interplay_services_log';
	public const MAX_ENTRIES = 100;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function error( string $message, array $context = [] ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	public function warn( string $message, array $context = [] ): void {
		$this->log( self::LEVEL_WARN, $message, $context );
	}

	public function info( string $message, array $context = [] ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	public function debug( string $message, array $context = [] ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->log( self::LEVEL_DEBUG, $message, $context );
		}
	}

	/**
	 * Convenience: capture a Throwable with full source location.
	 */
	public function exception( string $context_label, \Throwable $e ): void {
		$this->error(
			sprintf( '%s: %s', $context_label, $e->getMessage() ),
			[
				'class' => get_class( $e ),
				'file'  => $e->getFile(),
				'line'  => $e->getLine(),
			]
		);
	}

	/**
	 * Retrieve recent entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_recent( int $limit = 50 ): array {
		$log = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $log ) ) {
			return [];
		}
		// Stored chronologically; reverse for "newest first" display.
		return array_slice( array_reverse( $log ), 0, max( 1, $limit ) );
	}

	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * True if any error or warning was logged within the given window.
	 */
	public function has_recent_errors( int $within_seconds = 86400 ): bool {
		$cutoff = time() - $within_seconds;
		foreach ( $this->get_recent( self::MAX_ENTRIES ) as $entry ) {
			$level = (string) ( $entry['level'] ?? '' );
			$time  = (int) ( $entry['time'] ?? 0 );
			if ( $time >= $cutoff && in_array( $level, [ self::LEVEL_ERROR, self::LEVEL_WARN ], true ) ) {
				return true;
			}
		}
		return false;
	}

	// ─── Internals ────────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		$entry = [
			'time'    => time(),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		];

		$this->write_to_error_log( $entry );

		if ( $level !== self::LEVEL_DEBUG ) {
			$this->append_to_buffer( $entry );
		}
	}

	/**
	 * @param array<string,mixed> $entry
	 */
	private function write_to_error_log( array $entry ): void {
		$context_str = ! empty( $entry['context'] )
			? ' ' . wp_json_encode( $entry['context'] )
			: '';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[Interplay Services][%s] %s%s',
			strtoupper( (string) $entry['level'] ),
			(string) $entry['message'],
			$context_str
		) );
	}

	/**
	 * @param array<string,mixed> $entry
	 */
	private function append_to_buffer( array $entry ): void {
		// Wrap in try/catch — the logger itself must never throw.
		try {
			$log = get_option( self::OPTION_KEY, [] );
			if ( ! is_array( $log ) ) {
				$log = [];
			}
			$log[] = $entry;
			if ( count( $log ) > self::MAX_ENTRIES ) {
				$log = array_slice( $log, -self::MAX_ENTRIES );
			}
			// autoload=false so a growing buffer doesn't bloat every request.
			update_option( self::OPTION_KEY, $log, false );
		} catch ( \Throwable $e ) {
			// Swallow — failing to log shouldn't break anything.
		}
	}
}
