<?php
/**
 * Product registry.
 *
 * Central catalogue of every product managed by Interplay Services.
 * The Update Manager and License Manager both query this registry.
 *
 * Products are registered programmatically (via load_defaults or third-party
 * code calling ::register()), not from a database, so the registry is always
 * in sync with the running code.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Registry;

use Interplay\Services\Http\Client;
use Interplay\Services\Registry\Contracts\ProductInterface;
use Interplay\Services\Registry\Products\IntroTheme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductRegistry {

	/** @var array<string, ProductInterface> Keyed by product ID. */
	private array $products = [];

	public function __construct( private readonly Client $http ) {}

	// ─── Registration ─────────────────────────────────────────────────────────

	/**
	 * Register a product.
	 *
	 * Multiple calls with the same ID overwrite the previous registration —
	 * allowing child themes or mu-plugins to override default definitions.
	 */
	public function register( ProductInterface $product ): void {
		$this->products[ $product->get_id() ] = $product;
	}

	/**
	 * Register all products shipped with this plugin.
	 * Called once on 'plugins_loaded'.
	 *
	 * Third parties (mu-plugins, client plugins) may call ::register()
	 * before or after this method using the 'interplay_services_registry_loaded'
	 * action hook.
	 */
	public function load_defaults(): void {
		$this->register( new IntroTheme() );

		/**
		 * Fires after the default product list has been registered.
		 *
		 * Use this hook to register additional Interplay-managed products.
		 *
		 * @param ProductRegistry $registry The registry instance.
		 */
		do_action( 'interplay_services_registry_loaded', $this );
	}

	// ─── Queries ──────────────────────────────────────────────────────────────

	/**
	 * @return ProductInterface[]  All registered products.
	 */
	public function all(): array {
		return array_values( $this->products );
	}

	/**
	 * @return ProductInterface[]  Only products of the given type.
	 */
	public function of_type( string $type ): array {
		return array_values(
			array_filter( $this->products, fn( $p ) => $p->get_type() === $type )
		);
	}

	/**
	 * Find a product by ID, or return null.
	 */
	public function find( string $id ): ?ProductInterface {
		return $this->products[ $id ] ?? null;
	}
}
