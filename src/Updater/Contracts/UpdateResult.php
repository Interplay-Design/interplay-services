<?php
/**
 * Value object: the result of a remote update check.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Updater\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UpdateResult {

	public function __construct(
		/** Latest version string, e.g. '1.2.3'. */
		public readonly string $version,

		/** URL to download the zip. Empty string if unavailable. */
		public readonly string $package_url,

		/** URL to the release page / changelog. */
		public readonly string $details_url,

		/** Whether the package_url requires authenticated download. */
		public readonly bool $requires_auth,

		/** Raw payload from the remote source (for future use / logging). */
		public readonly array $raw = [],
	) {}

	/**
	 * Compare this result against an installed version.
	 *
	 * @param  string $installed_version  Currently installed version string.
	 * @return bool   True if the remote version is strictly newer.
	 */
	public function is_update_available( string $installed_version ): bool {
		return version_compare( $this->version, $installed_version, '>' );
	}
}
