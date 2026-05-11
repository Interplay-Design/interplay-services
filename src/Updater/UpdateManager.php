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
use Interplay\Services\Log\Logger;
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

	private bool $hooks_registered = false;

	public function register_hooks(): void {
		// Idempotent: the plugin's own update reactivates the plugin file mid-request,
		// which would otherwise re-add every filter and double-fire the injection logic.
		if ( $this->hooks_registered ) {
			return;
		}
		$this->hooks_registered = true;

		// Intercept the core theme/plugin update transients.
		add_filter( 'pre_set_site_transient_update_themes', [ $this, 'inject_theme_updates' ] );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_plugin_updates' ] );

		// Re-register package watches whenever WordPress reads a cached transient.
		// This keeps the download proxy aware of the package URL during the actual
		// upgrade request, not just when the transient is first generated.
		add_filter( 'site_transient_update_themes', [ $this, 'reregister_theme_watches' ] );
		add_filter( 'site_transient_update_plugins', [ $this, 'reregister_plugin_watches' ] );

		// Provide metadata to the theme/plugin details modal.
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
	 * Tolerant of non-stdClass values (false, null, arrays) that other plugins
	 * or edge-case core paths can pass through this filter.
	 *
	 * @param  mixed $transient
	 * @return mixed
	 */
	public function inject_theme_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		try {
			foreach ( $this->registry->of_type( 'theme' ) as $product ) {
				$this->register_available_update( $product, $transient->response );
			}
		} catch ( \Throwable $e ) {
			$this->log_exception( 'inject_theme_updates', $e );
		}

		return $transient;
	}

	/**
	 * Filter: 'pre_set_site_transient_update_plugins'
	 *
	 * @param  mixed $transient
	 * @return mixed
	 */
	public function inject_plugin_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		try {
			foreach ( $this->registry->of_type( 'plugin' ) as $product ) {
				$result = $this->check_product( $product );

				if ( $result === null ) {
					continue;
				}

				$installed = $product->get_installed_version();
				if ( ! $result->is_update_available( $installed ) ) {
					Logger::instance()->debug( 'plugin up-to-date', [
						'product'   => $product->get_id(),
						'installed' => $installed,
						'latest'    => $result->version,
					] );
					continue;
				}

				Logger::instance()->info( 'plugin update available', [
					'product'     => $product->get_id(),
					'installed'   => $installed,
					'new_version' => $result->version,
					'package'     => $result->package_url,
				] );

				$this->register_download_watch( $product, $result );

				$plugin_id = $product->get_id();
				$slug      = dirname( $plugin_id );
				if ( $slug === '.' || $slug === '' ) {
					$slug = $plugin_id;
				}

				$source = $product->get_update_source();

				$transient->response[ $plugin_id ] = (object) [
					'id'           => (string) ( $source['repository'] ?? $plugin_id ),
					'slug'         => $slug,
					'plugin'       => $plugin_id,
					'new_version'  => $result->version,
					'url'          => $result->details_url,
					'package'      => $result->package_url,
					'icons'        => [],
					'banners'      => [],
					'banners_rtl'  => [],
					'tested'       => '',
					'requires_php' => '',
					'compatibility'=> new \stdClass(),
				];

				do_action( 'interplay_services_update_available', $product, $result );
			}
		} catch ( \Throwable $e ) {
			$this->log_exception( 'inject_plugin_updates', $e );
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
	public function filter_themes_api( $result, string $action, $args ) {
		if ( $action !== 'theme_information' ) {
			return $result;
		}

		$slug    = is_object( $args ) ? (string) ( $args->slug ?? '' ) : '';
		$product = $slug !== '' ? $this->registry->find( $slug ) : null;

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
	public function filter_plugins_api( $result, string $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		$slug = is_object( $args ) ? (string) ( $args->slug ?? '' ) : '';
		if ( $slug === '' ) {
			return $result;
		}

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

	/**
	 * Public accessor for the latest UpdateResult for a product.
	 *
	 * Used by the admin Registered Products table to show the current
	 * GitHub release alongside the installed version. Returns null if
	 * the source can't be reached or the product has no driver.
	 */
	public function latest_for_product( ProductInterface $product ): ?UpdateResult {
		return $this->check_product( $product );
	}

	private function check_product( ProductInterface $product ): ?UpdateResult {
		// Don't fire remote checks during an in-flight upgrade. Between
		// upgrader_clear_destination (deletes old plugin folder) and the
		// final move_dir, autoloading any lazily-referenced class will fail
		// with "class not found". Skip the check; cached transients are fine.
		if ( $this->is_upgrade_in_progress() ) {
			return null;
		}

		$driver = $this->driver_for( $product );
		if ( $driver === null ) {
			return null;
		}

		try {
			return $driver->fetch_latest( $product );
		} catch ( \Throwable $e ) {
			$this->log_exception( 'check_product:' . $product->get_id(), $e );
			return null;
		}
	}

	private function is_upgrade_in_progress(): bool {
		return doing_action( 'upgrader_pre_install' )
			|| doing_action( 'upgrader_pre_download' )
			|| doing_action( 'upgrader_source_selection' )
			|| doing_action( 'upgrader_clear_destination' )
			|| doing_action( 'upgrader_install_package_result' )
			|| doing_action( 'upgrader_post_install' )
			|| doing_action( 'upgrader_process_complete' );
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function register_available_update( ProductInterface $product, array &$response ): void {
		$result = $this->check_product( $product );

		if ( $result === null ) {
			return;
		}

		$installed = $product->get_installed_version();
		if ( ! $result->is_update_available( $installed ) ) {
			Logger::instance()->debug( 'theme up-to-date', [
				'product'   => $product->get_id(),
				'installed' => $installed,
				'latest'    => $result->version,
			] );
			return;
		}

		Logger::instance()->info( 'theme update available', [
			'product'     => $product->get_id(),
			'installed'   => $installed,
			'new_version' => $result->version,
			'package'     => $result->package_url,
		] );

		$this->register_download_watch( $product, $result );

		$response[ $product->get_id() ] = [
			'theme'        => $product->get_id(),
			'new_version'  => $result->version,
			'url'          => $result->details_url,
			'package'      => $result->package_url,
			'requires'     => '',
			'requires_php' => '',
		];

		do_action( 'interplay_services_update_available', $product, $result );
	}

	private function register_download_watch( ProductInterface $product, UpdateResult $result ): void {
		if ( ! $result->requires_auth || $result->package_url === '' ) {
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

	private function log_exception( string $context, \Throwable $e ): void {
		Logger::instance()->exception( 'UpdateManager.' . $context, $e );
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
