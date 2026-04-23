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

spl_autoload_register( function ( string $class ): void {
	$prefix = 'Interplay\\Services\\';
	$base   = \INTERPLAY_SERVICES_DIR . 'src/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = $base . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

	if ( is_readable( $file ) ) {
		require $file;
	}
} );
