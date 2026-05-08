<?php
/**
 * GitHub Releases update source driver.
 *
 * Fetches the latest published release from a GitHub repository.
 * Supports both public repos and private repos (via a stored PAT).
 *
 * Cache: each product's result is cached in a transient for 6 hours to
 * avoid hammering the GitHub API. The transient is force-cleared when the
 * admin manually triggers "Check for updates" on the Updates screen.
 *
 * Private repo download strategy
 * ───────────────────────────────
 * For private repos, the zip asset URL requires an Authorization header that
 * WordPress's upgrader cannot add by itself. This driver stores the download
 * URL in the transient; the DownloadProxy class intercepts the WordPress
 * upgrade and performs the authenticated download.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Updater\Sources;

use Interplay\Services\Http\Client;
use Interplay\Services\Registry\Contracts\ProductInterface;
use Interplay\Services\Updater\Contracts\UpdateResult;
use Interplay\Services\Updater\Contracts\UpdateSourceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubReleasesSource implements UpdateSourceInterface {

	private const CACHE_TTL  = 6 * HOUR_IN_SECONDS;
	private const API_BASE   = 'https://api.github.com/repos/';

	public function __construct( private readonly Client $http ) {}

	// ─── Interface ────────────────────────────────────────────────────────────

	public function driver_name(): string {
		return 'github';
	}

	public function requires_credentials(): bool {
		return true; // Intro repo is private; a PAT is needed.
	}

	public function has_credentials(): bool {
		return $this->github_token() !== '';
	}

	public function fetch_latest( ProductInterface $product ): ?UpdateResult {
		$source = $product->get_update_source();
		$repo   = (string) ( $source['repository'] ?? '' );

		if ( $repo === '' ) {
			return null;
		}

		$cache_key = 'interplay_update_' . md5( 'github_' . $repo );
		$cached    = get_transient( $cache_key );

		// Cache stores plain arrays so changes to the UpdateResult class shape
		// never poison the cache and force a clear across all sites.
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return $this->result_from_cache( $cached );
		}

		// Legacy: a previously-cached UpdateResult instance. Discard and refetch.
		if ( $cached instanceof UpdateResult ) {
			delete_transient( $cache_key );
		}

		$result = $this->query_github( $repo, $source );

		if ( $result !== null ) {
			set_transient( $cache_key, $this->result_to_cache( $result ), self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function result_from_cache( array $data ): UpdateResult {
		return new UpdateResult(
			version:       (string) ( $data['version'] ?? '' ),
			package_url:   (string) ( $data['package_url'] ?? '' ),
			details_url:   (string) ( $data['details_url'] ?? '' ),
			requires_auth: (bool) ( $data['requires_auth'] ?? false ),
			raw:           is_array( $data['raw'] ?? null ) ? $data['raw'] : [],
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function result_to_cache( UpdateResult $result ): array {
		return [
			'version'       => $result->version,
			'package_url'   => $result->package_url,
			'details_url'   => $result->details_url,
			'requires_auth' => $result->requires_auth,
			'raw'           => [], // skip raw payload to keep transients small
		];
	}

	// ─── Internals ────────────────────────────────────────────────────────────

	/**
	 * @param  string              $repo   'owner/name'
	 * @param  array<string,mixed> $source Source config from the product.
	 */
	private function query_github( string $repo, array $source ): ?UpdateResult {
		$url  = self::API_BASE . $repo . '/releases/latest';
		$data = $this->http->get_json( $url );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		$tag     = ltrim( (string) $data['tag_name'], 'v' );
		$zipball = $this->resolve_download_url( $data, $repo, $source );

		return new UpdateResult(
			version:       $tag,
			package_url:   $zipball,
			details_url:   (string) ( $data['html_url'] ?? '' ),
			requires_auth: $this->has_credentials(),
			raw:           $data,
		);
	}

	/**
	 * Determine the download URL.
	 *
	 * Priority:
	 *  1. Named asset matching 'asset_name' in the product source config.
	 *  2. First zip asset found in the release.
	 *  3. GitHub's auto-generated zipball_url.
	 *
	 * @param  array<string,mixed> $release_data Raw GitHub API response.
	 * @param  string              $repo
	 * @param  array<string,mixed> $source
	 * @return string
	 */
	private function resolve_download_url( array $release_data, string $repo, array $source ): string {
		$asset_name = $source['asset_name'] ?? null;
		$assets     = (array) ( $release_data['assets'] ?? [] );

		if ( $asset_name !== null ) {
			foreach ( $assets as $asset ) {
				if ( isset( $asset['name'] ) && $asset['name'] === $asset_name ) {
					return (string) ( $asset['browser_download_url'] ?? '' );
				}
			}
		}

		// Fall back to first zip asset.
		foreach ( $assets as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( str_ends_with( strtolower( $name ), '.zip' ) ) {
				return (string) ( $asset['browser_download_url'] ?? '' );
			}
		}

		// Final fallback: GitHub auto-generated zipball via the API endpoint
		// (requires auth header, handled by DownloadProxy).
		return (string) ( $release_data['zipball_url'] ?? '' );
	}

	private function github_token(): string {
		// Delegate to the HTTP client so the constant / env / option chain is
		// authoritative in one place.
		return $this->http->github_token();
	}

	// ─── Cache invalidation ───────────────────────────────────────────────────

	/**
	 * Delete all cached update results.
	 * Called when the user forces a re-check via the Updates admin screen.
	 */
	public function bust_cache(): void {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_interplay_update_%'
			    OR option_name LIKE '_transient_timeout_interplay_update_%'"
		);
	}
}
