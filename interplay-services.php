<?php
/**
 * Plugin Name:       Interplay Services
 * Plugin URI:        https://interplay.design
 * Description:       Central service layer for Interplay products: update delivery, license enforcement, and product registry.
 * Version:           0.1.4
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            the Interplay team
 * Author URI:        https://interplay.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       interplay-services
 * Domain Path:       /languages
 *
 * Can be used as a normal plugin or as an MU plugin.
 * When used as an MU plugin, place this folder in wp-content/mu-plugins/.
 *
 * @package InterplayServices
 */

namespace Interplay\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-update reactivation re-includes this file in the same request. The
// constants below would emit "already defined" notices on the second pass,
// so define them defensively.
if ( ! defined( 'INTERPLAY_SERVICES_VERSION' ) ) {
	define( 'INTERPLAY_SERVICES_VERSION', '0.1.4' );
}
if ( ! defined( 'INTERPLAY_SERVICES_FILE' ) ) {
	define( 'INTERPLAY_SERVICES_FILE', __FILE__ );
}
if ( ! defined( 'INTERPLAY_SERVICES_DIR' ) ) {
	define( 'INTERPLAY_SERVICES_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'INTERPLAY_SERVICES_URL' ) ) {
	define( 'INTERPLAY_SERVICES_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'INTERPLAY_SERVICES_SLUG' ) ) {
	define( 'INTERPLAY_SERVICES_SLUG', 'interplay-services' );
}

// ─── Autoloader ───────────────────────────────────────────────────────────────

require_once INTERPLAY_SERVICES_DIR . 'src/autoload.php';

// ─── Boot ─────────────────────────────────────────────────────────────────────

Plugin::instance()->boot();
