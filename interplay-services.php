<?php
/**
 * Plugin Name:       Interplay Services
 * Plugin URI:        https://interplay.design
 * Description:       Central service layer for Interplay products: update delivery, license enforcement, and product registry.
 * Version:           0.1.0-beta
 * Requires at least: 6.7
 * Requires PHP:      7.4
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

// ─── Constants ────────────────────────────────────────────────────────────────

define( 'INTERPLAY_SERVICES_VERSION',   '0.1.0-beta' );
define( 'INTERPLAY_SERVICES_FILE',      __FILE__ );
define( 'INTERPLAY_SERVICES_DIR',       plugin_dir_path( __FILE__ ) );
define( 'INTERPLAY_SERVICES_URL',       plugin_dir_url( __FILE__ ) );
define( 'INTERPLAY_SERVICES_SLUG',      'interplay-services' );

// ─── Autoloader ───────────────────────────────────────────────────────────────

require_once INTERPLAY_SERVICES_DIR . 'src/autoload.php';

// ─── Boot ─────────────────────────────────────────────────────────────────────

Plugin::instance()->boot();
