<?php
/**
 * Concrete product: Intro theme.
 *
 * Registered automatically by ProductRegistry::load_defaults().
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Registry\Products;

use Interplay\Services\Registry\Contracts\ProductInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IntroTheme implements ProductInterface {

	public function get_id(): string {
		return 'intro';
	}

	public function get_name(): string {
		return 'Intro';
	}

	public function get_type(): string {
		return 'theme';
	}

	public function get_installed_version(): string {
		$theme = wp_get_theme( 'intro' );
		if ( $theme->exists() ) {
			return (string) $theme->get( 'Version' );
		}

		// Fallback: if updates temporarily activate a hashed Intro folder,
		// still report the installed Intro version instead of 0.0.0.
		$active_stylesheet = (string) get_stylesheet();
		if ( $active_stylesheet !== '' ) {
			$active_theme = wp_get_theme( $active_stylesheet );
			if ( $active_theme->exists() ) {
				$active_name = strtolower( (string) $active_theme->get( 'Name' ) );
				if ( strpos( $active_name, 'intro' ) !== false ) {
					return (string) $active_theme->get( 'Version' );
				}
			}
		}

		return '0.0.0';
	}

	public function requires_license(): bool {
		// Intro will require a license once the license system is live.
		return false;
	}

	/**
	 * Update source: private GitHub repository, latest release.
	 *
	 * driver:      'github'
	 * repository:  GitHub owner/repo
	 * asset_name:  Optional: name of the release asset zip to download.
	 *              Leave null to use the auto-generated source archive.
	 *
	 * @return array<string,mixed>
	 */
	public function get_update_source(): array {
		return [
			'driver'     => 'github',
			'repository' => 'Interplay-Design/Intro',
			'asset_name' => null, // use GitHub's auto-generated source zip
		];
	}
}
