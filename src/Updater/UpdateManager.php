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
		// Intercept the core theme update transient.
		add_filter( 'pre_set_site_transient_update_themes', [ $this, 'inject_theme_updates' ] );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_plugin_updates' ] );

		// Re-register package watches whenever WordPress reads a cached transient.
		// This keeps the download proxy aware of the package URL during the actual
		// upgrade request, not just when the transient is first generated.
		add_filter( 'site_transient_update_themes', [ $this, 'reregister_theme_watches' ] );
		add_filter( 'site_transient_update_plugins', [ $this, 'reregister_plugin_watches' ] );

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
			$this->register_available_update( $product, $transient->response );
		}

		return $transient;
	}

	/**
	 * Filter: 'pre_set_site_transient_update_plugins'
	 *
	 * @param \stdClass $transient
	 * @return \stdClass
	 */
	public function inject_plugin_updates( \stdClass $transient ): \stdClass {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$plugins = $this->registry->of_type( 'plugin' );

		foreach ( $plugins as $product ) {
			$result = $this->check_product( $product );

			if ( $result === null || ! $result->is_update_available( $product->get_installed_version() ) ) {
				continue;
			}

			$this->register_download_watch( $product, $result );

			$slug = dirname( $product->get_id() );
			if ( $slug === '.' || $slug === '' ) {
				$slug = $product->get_id();
			}

			$transient->response[ $product->get_id() ] = (object) [
				'id'          => $product->get_update_source()['repository'] ?? $product->get_id(),
				'slug'        => $slug,
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
	 * @param false|object|array $result
	 * @param string             $action
	 * @param \stdClass          $args
	 * @return false|object|array
	 */
	public function filter_plugins_api( $result, string $action, \stdClass $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		$slug = (string) ( $args->slug ?? '' );
		$product = $this->registry->find( $slug . '/' . $slug . '.php' );

		if ( $product === null || $product->get_type() !== 'plugin' ) {
			return $result;
		}

		$update = $this->check_product( $product );
		if ( $update === null ) {
			return $result;
		}

		return (object) [
			'name'          => $product->get_name(),
			'slug'          => $slug,
			'version'       => $update->version,
			'author'        => 'the Interplay team',
			'author_profile'=> 'https://interplay.design',
			'homepage'      => $update->details_url ?: 'https://interplay.design',
			'sections'      => [
				'description' => sprintf( '<p>%s</p>', esc_html( $product->get_name() . ' by Interplay' ) ),
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

	/**
	 * @param array<string,mixed> $response
	 */
	private function register_available_update( ProductInterface $product, array &$response ): void {
		$result = $this->check_product( $product );

		if ( $result === null || ! $result->is_update_available( $product->get_installed_version() ) ) {
			return;
		}

		$this->register_download_watch( $product, $result );

		$response[ $product->get_id() ] = [
			'theme'       => $product->get_id(),
			'new_version' => $result->version,
			'url'         => $result->details_url,
			'package'     => $result->package_url,
		];

		do_action( 'interplay_services_update_available', $product, $result );
	}

	private function register_download_watch( ProductInterface $product, UpdateResult $result ): void {
		if ( ! $result->requires_auth ) {
			return;
		}

		$this->download_proxy->watch(
			$result->package_url,
			[
				'type' => $product->get_type(),
				'id'   => $product->get_id(),
			]
		);
	}

	/**
	 * Filter: 'site_transient_update_themes'
	 *
	 * Re-register package watches from the cached theme transient so the
	 * download proxy still has package metadata in the upgrade request.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function reregister_theme_watches( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->response ) ) {
			return $transient;
		}

		foreach ( $this->registry->of_type( 'theme' ) as $product ) {
			$entry = $transient->response[ $product->get_id() ] ?? null;
			$package = is_array( $entry ) ? (string) ( $entry['package'] ?? '' ) : '';

			if ( $package !== '' ) {
				$this->download_proxy->watch(
					$package,
					[
						'type' => 'theme',
						'id'   => $product->get_id(),
					]
				);
			}
		}

		return $transient;
	}

	/**
	 * Filter: 'site_transient_update_plugins'
	 *
	 * Re-register package watches from the cached plugin transient.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function reregister_plugin_watches( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->response ) ) {
			return $transient;
		}

		foreach ( $this->registry->of_type( 'plugin' ) as $product ) {
			$entry = $transient->response[ $product->get_id() ] ?? null;
			$package = is_object( $entry ) ? (string) ( $entry->package ?? '' ) : '';

			if ( $package !== '' ) {
				$this->download_proxy->watch(
					$package,
					[
						'type' => 'plugin',
						'id'   => $product->get_id(),
					]
				);
			}
		}

		return $transient;
	}
}
