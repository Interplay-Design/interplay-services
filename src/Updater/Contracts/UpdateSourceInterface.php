<?php
/**
 * Contract: an update source driver.
 *
 * Drivers are responsible for fetching remote version data and
 * (optionally) providing a download URL. The UpdateManager asks
 * each driver to resolve the update for a given product.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Updater\Contracts;

use Interplay\Services\Registry\Contracts\ProductInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface UpdateSourceInterface {

	/**
	 * The driver identifier this source handles.
	 * Must match the 'driver' key in ProductInterface::get_update_source().
	 *
	 * @return string  e.g. 'github', 'interplay-api'
	 */
	public function driver_name(): string;

	/**
	 * Fetch the latest available release for $product.
	 *
	 * Returns an UpdateResult on success, or null if the check fails or
	 * no update is available.
	 *
	 * Implementations are expected to cache results using WordPress transients.
	 *
	 * @param  ProductInterface $product
	 * @return UpdateResult|null
	 */
	public function fetch_latest( ProductInterface $product ): ?UpdateResult;

	/**
	 * Whether this source requires a GitHub token (or similar credential)
	 * to operate.
	 *
	 * Used by the admin UI to surface a configuration warning.
	 *
	 * @return bool
	 */
	public function requires_credentials(): bool;

	/**
	 * Returns true if the source has the credentials it needs.
	 *
	 * @return bool
	 */
	public function has_credentials(): bool;
}
