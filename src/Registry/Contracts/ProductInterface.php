<?php
/**
 * Contract: a product managed by Interplay Services.
 *
 * Every product — whether a theme, plugin, or future artifact type — must
 * implement this interface so the registry, updater, and license manager
 * can handle it uniformly.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Registry\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ProductInterface {

	/**
	 * Stable machine identifier for this product.
	 * For themes this matches the theme folder name / stylesheet slug.
	 * For plugins it should match the plugin file slug (folder/file.php).
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable display name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Product type: 'theme' | 'plugin' | 'service'
	 *
	 * @return string
	 */
	public function get_type(): string;

	/**
	 * Currently installed version string.
	 *
	 * @return string
	 */
	public function get_installed_version(): string;

	/**
	 * Whether this product supports license-gated access.
	 *
	 * @return bool
	 */
	public function requires_license(): bool;

	/**
	 * Return the update source configuration array.
	 *
	 * Must contain at least:
	 *   'driver'     => 'github' | 'interplay-api' | ...
	 *   'repository' => 'owner/repo'   (for github)
	 *
	 * Additional keys are driver-specific.
	 *
	 * @return array<string,mixed>
	 */
	public function get_update_source(): array;
}
