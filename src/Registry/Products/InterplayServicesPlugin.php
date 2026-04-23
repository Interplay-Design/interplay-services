<?php
/**
 * Concrete product: Interplay Services plugin.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Registry\Products;

use Interplay\Services\Registry\Contracts\ProductInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InterplayServicesPlugin implements ProductInterface {

	public function get_id(): string {
		return 'interplay-services/interplay-services.php';
	}

	public function get_name(): string {
		return 'Interplay Services';
	}

	public function get_type(): string {
		return 'plugin';
	}

	public function get_installed_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = trailingslashit( WP_PLUGIN_DIR ) . $this->get_id();
		if ( ! file_exists( $plugin_file ) ) {
			return '0.0.0';
		}

		$data = get_plugin_data( $plugin_file, false, false );
		return ! empty( $data['Version'] ) ? (string) $data['Version'] : '0.0.0';
	}

	public function requires_license(): bool {
		return false;
	}

	/**
	 * Update source: public GitHub repository, latest release.
	 *
	 * @return array<string,mixed>
	 */
	public function get_update_source(): array {
		return [
			'driver'     => 'github',
			'repository' => 'interplaydesign/interplay-services',
			'asset_name' => null,
		];
	}
}