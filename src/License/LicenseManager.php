<?php
/**
 * License manager (stub).
 *
 * In the beta, licenses are not enforced — all registered products are
 * treated as unlicensed-but-permitted. The framework is wired up so that
 * enforcement can be switched on by implementing validate() against the
 * Interplay licensing API.
 *
 * Planned architecture:
 *  - Each site registers a license key (per-domain).
 *  - LicenseManager validates the key on a schedule (once every 24 h).
 *  - An invalid / expired key suppresses update delivery and can optionally
 *    lock features in dependent products.
 *  - Feature flags returned by the license API are stored in a WP option
 *    and surfaced to products via LicenseManager::feature_enabled().
 *
 * @package InterplayServices
 */

namespace Interplay\Services\License;

use Interplay\Services\Registry\Contracts\ProductInterface;
use Interplay\Services\Registry\ProductRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LicenseManager {

	// Option keys.
	public const OPTION_LICENSE_KEY    = 'interplay_services_license_key';
	public const OPTION_LICENSE_STATUS = 'interplay_services_license_status';
	public const OPTION_FEATURES       = 'interplay_services_license_features';

	// Status constants.
	public const STATUS_UNKNOWN   = 'unknown';
	public const STATUS_VALID     = 'valid';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_INVALID   = 'invalid';

	public function __construct( private readonly ProductRegistry $registry ) {}

	// ─── Public API ───────────────────────────────────────────────────────────

	/**
	 * The stored license key, or an empty string if none is set.
	 */
	public function get_license_key(): string {
		if ( defined( 'INTERPLAY_SERVICES_LICENSE_KEY' ) && constant( 'INTERPLAY_SERVICES_LICENSE_KEY' ) !== '' ) {
			return (string) constant( 'INTERPLAY_SERVICES_LICENSE_KEY' );
		}

		$env = getenv( 'INTERPLAY_SERVICES_LICENSE_KEY' );
		if ( is_string( $env ) && $env !== '' ) {
			return $env;
		}

		return (string) get_option( self::OPTION_LICENSE_KEY, '' );
	}

	/**
	 * Last known license status.
	 */
	public function get_status(): string {
		return (string) get_option( self::OPTION_LICENSE_STATUS, self::STATUS_UNKNOWN );
	}

	/**
	 * Whether updates should be unblocked for the given product.
	 *
	 * In beta: always returns true.
	 * Post-beta: returns true only if the license is valid and covers the product.
	 */
	public function updates_permitted( ProductInterface $product ): bool {
		if ( ! $product->requires_license() ) {
			return true;
		}

		// TODO: check license status and product entitlements once the
		//       Interplay licensing API endpoint is live.
		return true;
	}

	/**
	 * Whether a named feature flag is enabled via the license.
	 *
	 * In beta: always returns false (no feature flags yet).
	 *
	 * @param string $flag  Feature flag identifier.
	 */
	public function feature_enabled( string $flag ): bool {
		$features = (array) get_option( self::OPTION_FEATURES, [] );
		return isset( $features[ $flag ] ) && (bool) $features[ $flag ];
	}

	/**
	 * Validate the stored license key against the Interplay API.
	 * No-op in beta; will be implemented when the API endpoint is ready.
	 *
	 * @return bool  True if validation succeeded.
	 */
	public function validate(): bool {
		// TODO: POST to https://api.interplay.design/v1/licenses/validate
		//       with the license key and site domain.
		//       Store the returned status + feature flags.
		return true;
	}
}
