<?php
/**
 * Plugin bootstrap / lightweight service locator.
 *
 * Plugin::instance() returns the single Plugin object.
 * Services are registered via ::register() and resolved via ::make().
 *
 * Keeping this intentionally lean: no full DI container is needed right now,
 * but the pattern makes it trivial to swap to one later (e.g. PHP-DI).
 *
 * @package InterplayServices
 */

namespace Interplay\Services;

use Interplay\Services\Admin\SettingsPage;
use Interplay\Services\Registry\ProductRegistry;
use Interplay\Services\Updater\UpdateManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	// ─── Singleton ────────────────────────────────────────────────────────────

	private static ?self $instance = null;

	private function __construct() {}
	private function __clone() {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ─── Service container ────────────────────────────────────────────────────

	/** @var array<string, callable|object> */
	private array $bindings = [];

	/** @var array<string, object> */
	private array $resolved = [];

	/**
	 * Bind a service factory.
	 *
	 * Pass a Closure: it will be called once (singleton) when first resolved.
	 * Pass an already-constructed object: it is stored as-is.
	 *
	 * @param string         $abstract Identifier (typically the FQCN).
	 * @param callable|object $factory  Closure or object instance.
	 */
	public function register( string $abstract, $factory ): void {
		$this->bindings[ $abstract ] = $factory;
	}

	/**
	 * Resolve a service.
	 *
	 * @template T of object
	 * @param  class-string<T> $abstract
	 * @return T
	 * @throws \RuntimeException If the abstract is not registered.
	 */
	public function make( string $abstract ): object {
		if ( isset( $this->resolved[ $abstract ] ) ) {
			return $this->resolved[ $abstract ]; // @phpstan-ignore-line
		}

		if ( ! isset( $this->bindings[ $abstract ] ) ) {
			throw new \RuntimeException(
				sprintf( 'Interplay Services: no binding registered for "%s".', $abstract )
			);
		}

		$factory = $this->bindings[ $abstract ];

		$instance = is_callable( $factory ) ? $factory( $this ) : $factory;
		$this->resolved[ $abstract ] = $instance;

		return $instance; // @phpstan-ignore-line
	}

	// ─── Boot ─────────────────────────────────────────────────────────────────

	/**
	 * Register core services and wire WordPress hooks.
	 * Called once from the main plugin file after the autoloader is loaded.
	 */
	public function boot(): void {
		$this->bind_services();

		// Defer hook registration until WordPress is fully initialised.
		add_action( 'plugins_loaded', [ $this, 'init' ], 5 );
	}

	/**
	 * Bind service factories into the container.
	 * This runs before 'plugins_loaded' — do not call WordPress functions here.
	 */
	private function bind_services(): void {
		$plugin = $this;

		$this->register( Http\Client::class, fn() => new Http\Client() );

		$this->register(
			Registry\ProductRegistry::class,
			fn( Plugin $p ) => new Registry\ProductRegistry( $p->make( Http\Client::class ) )
		);

		$this->register(
			License\LicenseManager::class,
			fn( Plugin $p ) => new License\LicenseManager( $p->make( Registry\ProductRegistry::class ) )
		);

		$this->register(
			Updater\UpdateManager::class,
			fn( Plugin $p ) => new Updater\UpdateManager(
				$p->make( Registry\ProductRegistry::class ),
				$p->make( Http\Client::class )
			)
		);

		$this->register(
			Admin\SettingsPage::class,
			fn( Plugin $p ) => new Admin\SettingsPage(
				$p->make( Registry\ProductRegistry::class ),
				$p->make( License\LicenseManager::class ),
				$p->make( Http\Client::class )
			)
		);
	}

	/**
	 * Initialise services that need WordPress to be loaded.
	 * Fires on 'plugins_loaded'.
	 */
	public function init(): void {
		// Load product definitions (registers Intro theme etc.)
		$this->make( Registry\ProductRegistry::class )->load_defaults();

		// Wire update checks.
		$this->make( Updater\UpdateManager::class )->register_hooks();

		// Wire admin UI.
		if ( is_admin() ) {
			$this->make( Admin\SettingsPage::class )->register_hooks();
		}
	}
}
