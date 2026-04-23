<?php
/**
 * Update manager.
 *
 * Orchestrates update checking for all registered products.
 *
 * Responsibilities:
 *  - Load the correct update source driver for each product.
 *  - Hook into WordPress's update transients so that updates appear on the
 *    native Dashboard > Updates screen and via WP-CLI.
 *  - Delegate authenticated downloads to DownloadProxy.
 *  - Emit hooks so other code can react to available updates.
 *
 * WordPress theme update transient shape (per-theme entry):
 *   stdClass {
 *     theme:   string   stylesheet slug
 *     new_version: string
 *     url:     string   info link
 *     package: string   zip download URL
 *   }
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Updater;

use Interplay\Services\Http\Client;
use Interplay\Services\Registry\Contracts\ProductInterface;
use Interplay\Services\Registry\ProductRegistry;
use Interplay\Services\Updater\Contracts\UpdateResult;
use Interplay\Services\Updater\Contracts\UpdateSourceInterface;
use Interplay\Services\Updater\Sources\GitHubReleasesSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpdateManager {

	/** @var array<string, UpdateSourceInterface> Keyed by driver_name. */
	private array $drivers = [];

	private DownloadProxy $download_proxy;

	public function __construct(
		private readonly ProductRegistry $registry,
		private readonly Client          $http,
	) {
		$this->download_proxy = new DownloadProxy( $http );
		$this->register_default_drivers();
	}

	// ─── Driver registry ──────────────────────────────────────────────────────

	/**
	 * Register an update source driver.
	 * Later registrations override earlier ones with the same driver_name.
	 */
	public function register_driver( UpdateSourceInterface $driver ): void {
		$this->drivers[ $driver->driver_name() ] = $driver;
	}

	private function register_default_drivers(): void {
		$this->register_driver( new GitHubReleasesSource( $this->http ) );

		/**
		 * Fires after default update source drivers have been registered.
		 *
		 * Use this hook to add custom update source drivers.
		 *
		 * @param UpdateManager $manager
		 */
		do_action( 'interplay_services_register_update_drivers', $this );
	}

	private function driver_for( ProductInterface $product ): ?UpdateSourceInterface {
		$driver_name = $product->get_update_source()['driver'] ?? '';
		return $this->drivers[ $driver_name ] ?? null;
	}

	// ─── WordPress hook registration ──────────────────────────────────────────

	public function register_hooks(): void {
		// Intercept the core update transients.
		add_filter( 'pre_set_site_transient_update_themes',  [ $this, 'inject_theme_updates'  ] );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_plugin_updates' ] );

		// Provide metadata to the theme details modal.
		add_filter( 'themes_api', [ $this, 'filter_themes_api' ], 10, 3 );
		add_filter( 'plugins_api', [ $this, 'filter_plugins_api' ], 10, 3 );

		// Authenticated download proxy (for private repos).
		$this->download_proxy->register_hooks();

		// Cache-bust on manual "Check Again" requests.
		add_action( 'load-update-core.php', [ $this, 'maybe_bust_caches' ] );
	}

	// ─── Theme update injection ───────────────────────────────────────────────

	/**
	 * Filter: 'pre_set_site_transient_update_themes'
	 *
	 * WordPress calls this filter when it refreshes the update_themes transient.
	 * We append update entries for all registered themes that have a newer
	 * version available in their source.
	 *
	 * @param  \stdClass $transient
	 * @return \stdClass
	 */
	public function inject_theme_updates( \stdClass $transient ): \stdClass {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$themes = $this->registry->of_type( 'theme' );

		foreach ( $themes as $product ) {
			$result = $this->check_product( $product );

			if ( $result === null ) {
				continue;
			}

			if ( ! $result->is_update_available( $product->get_installed_version() ) ) {
				continue;
			}

			// Tell the download proxy to watch this URL.
			if ( $result->requires_auth ) {
				$this->download_proxy->watch( $result->package_url );
			}

			$transient->response[ $product->get_id() ] = (object) [
				'theme'       => $product->get_id(),
				'new_version' => $result->version,
				'url'         => $result->details_url,
				'package'     => $result->package_url,
			];

			/**
			 * Fires when an update is detected for a managed product.
			 *
			 * @param ProductInterface $product The product with an available update.
			 * @param UpdateResult     $result  The resolved update.
			 */
			do_action( 'interplay_services_update_available', $product, $result );
		}

		return $transient;
	}

	/**
	 * Filter: 'pre_set_site_transient_update_plugins'
	 *
	 * Append update entries for managed plugins.
	 *
	 * @param  \stdClass $transient
	 * @return \stdClass
	 */
	public function inject_plugin_updates( \stdClass $transient ): \stdClass {
		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$plugins = $this->registry->of_type( 'plugin' );

		foreach ( $plugins as $product ) {
			$result = $this->check_product( $product );

			if ( $result === null ) {
				continue;
			}

			if ( ! $result->is_update_available( $product->get_installed_version() ) ) {
				continue;
			}

			if ( $result->requires_auth ) {
				$this->download_proxy->watch( $result->package_url );
			}

			$transient->response[ $product->get_id() ] = (object) [
				'id'          => $product->get_id(),
				'slug'        => $this->plugin_slug_from_product( $product ),
				'plugin'      => $product->get_id(),
				'new_version' => $result->version,
				'url'         => $result->details_url,
				'package'     => $result->package_url,
			];

			do_action( 'interplay_services_update_available', $product, $result );
		}

		return $transient;
	}

	/**
	 * Filter: 'themes_api'
	 *
	 * Intercept requests for theme information used by the "View version details"
	 * modal in the Updates screen.
	 *
	 * @param  false|\stdClass $result
	 * @param  string          $action  e.g. 'theme_information'
	 * @param  \stdClass       $args
	 * @return false|\stdClass
	 */
	public function filter_themes_api( $result, string $action, \stdClass $args ) {
		if ( $action !== 'theme_information' ) {
			return $result;
		}

		$slug    = $args->slug ?? '';
		$product = $this->registry->find( $slug );

		if ( $product === null || $product->get_type() !== 'theme' ) {
			return $result;
		}

		$update = $this->check_product( $product );
		if ( $update === null ) {
			return $result;
		}

		return (object) [
			'name'          => $product->get_name(),
			'slug'          => $product->get_id(),
			'version'       => $update->version,
			'author'        => 'the Interplay team',
			'author_profile' => 'https://interplay.design',
			'homepage'      => $update->details_url ?: 'https://interplay.design/themes/intro',
			'sections'      => [
				'description' => sprintf(
					'<p>%s</p>',
					esc_html( $product->get_name() . ' by Interplay' )
				),
				'changelog'   => sprintf(
					'<p><a href="%s" target="_blank" rel="noopener">%s</a></p>',
					esc_url( $update->details_url ),
					esc_html__( 'View release notes on GitHub', 'interplay-services' )
				),
			],
			'download_link' => $update->package_url,
			'last_updated'  => gmdate( 'Y-m-d' ),
		];
	}

	/**
	 * Filter: 'plugins_api'
	 *
	 * Intercept requests for plugin information used by the native plugin
	 * details modal.
	 *
	 * @param  false|object|array $result
	 * @param  string             $action e.g. 'plugin_information'
	 * @param  object             $args
	 * @return false|object|array
	 */
	public function filter_plugins_api( $result, string $action, object $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		$slug = $args->slug ?? '';
		if ( ! is_string( $slug ) || $slug === '' ) {
			return $result;
		}

		$product = null;
		foreach ( $this->registry->of_type( 'plugin' ) as $candidate ) {
			if ( $this->plugin_slug_from_product( $candidate ) === $slug ) {
				$product = $candidate;
				break;
			}
		}

		if ( $product === null ) {
			return $result;
		}

		$update = $this->check_product( $product );
		if ( $update === null ) {
			return $result;
		}

		return (object) [
			'name'           => $product->get_name(),
			'slug'           => $this->plugin_slug_from_product( $product ),
			'plugin_name'    => $product->get_id(),
			'version'        => $update->version,
			'author'         => 'the Interplay team',
			'author_profile' => 'https://interplay.design',
			'homepage'       => $update->details_url ?: 'https://github.com/interplaydesign/interplay-services',
			'sections'       => [
				'description' => sprintf(
					'<p>%s</p>',
					esc_html__( 'Central service layer for Interplay product updates and licensing.', 'interplay-services' )
				),
				'changelog'   => sprintf(
					'<p><a href="%s" target="_blank" rel="noopener">%s</a></p>',
					esc_url( $update->details_url ),
					esc_html__( 'View release notes on GitHub', 'interplay-services' )
				),
			],
			'download_link'  => $update->package_url,
			'last_updated'   => gmdate( 'Y-m-d' ),
			'banners'        => [],
			'icons'          => [],
		];
	}

	// ─── Cache busting ────────────────────────────────────────────────────────

	/**
	 * Action: 'load-update-core.php'
	 * Bust caches when the user manually refreshes the Updates screen.
	 */
	public function maybe_bust_caches(): void {
		// WordPress appends ?force-check=1 to the URL when "Check Again" is pressed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['force-check'] ) ) {
			foreach ( $this->drivers as $driver ) {
				if ( method_exists( $driver, 'bust_cache' ) ) {
					$driver->bust_cache();
				}
			}
		}
	}

	// ─── Internal helpers ─────────────────────────────────────────────────────

	private function check_product( ProductInterface $product ): ?UpdateResult {
		$driver = $this->driver_for( $product );
		if ( $driver === null ) {
			return null;
		}

		return $driver->fetch_latest( $product );
	}

	private function plugin_slug_from_product( ProductInterface $product ): string {
		$id = $product->get_id();
		if ( str_contains( $id, '/' ) ) {
			return dirname( $id );
		}

		return $id;
	}
}
