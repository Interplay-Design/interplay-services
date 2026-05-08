<?php
/**
 * PSR-4-style autoloader for the Interplay\Services namespace.
 *
 * Maps  Interplay\Services\Foo\Bar
 *   to  {plugin}/src/Foo/Bar.php
 *
 * @package InterplayServices
 */

namespace Interplay\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against double-registration: when WordPress's plugin upgrader reactivates
// this plugin after a self-update, it includes the bootstrap file again in the
// same request. require_once on this autoloader file already prevents re-execution,
// but we also use require_once below so any class file that's already in memory
// is never re-required (which would fatal with "cannot redeclare class").
spl_autoload_register( function ( string $class ): void {
	$prefix = 'Interplay\\Services\\';
	$base   = \INTERPLAY_SERVICES_DIR . 'src/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = $base . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );
