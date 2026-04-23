<?php
/**
 * Download proxy: authenticated zip download for private repositories.
 *
 * WordPress's upgrader fetches package zips with a plain wp_remote_get,
 * which does not send any Authorization header. For private GitHub repos,
 * this results in a 404 / redirect-to-login response.
 *
 * This class hooks 'upgrader_pre_download' to intercept downloads whose
 * URL matches a known Interplay package URL, downloads the file itself
 * (with the stored GitHub token), writes it to a temp file, and hands
 * the path back to WordPress so the upgrader can proceed normally.
 *
 * This hook fires regardless of whether the upgrade originates from the
 * admin Updates screen or a programmatic upgrade call.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Updater;

use Interplay\Services\Http\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DownloadProxy {

	/** @var string[] Package URLs that require proxied download. */
	private array $watched_urls = [];

	public function __construct( private readonly Client $http ) {}

	public function register_hooks(): void {
		add_filter( 'upgrader_pre_download', [ $this, 'maybe_proxy_download' ], 10, 3 );
	}

	/**
	 * Register a URL that should be downloaded with authentication.
	 * Called by UpdateManager when it builds the update transient.
	 */
	public function watch( string $url ): void {
		if ( $url !== '' && ! in_array( $url, $this->watched_urls, true ) ) {
			$this->watched_urls[] = $url;
		}
	}

	/**
	 * Hook: 'upgrader_pre_download'
	 *
	 * @param  bool|string|\WP_Error $reply   Existing reply (false = proceed normally).
	 * @param  string                $package The package URL WordPress is about to download.
	 * @param  \WP_Upgrader          $upgrader
	 * @return bool|string|\WP_Error  Path to the downloaded temp file, or the original $reply.
	 */
	public function maybe_proxy_download( $reply, string $package, \WP_Upgrader $upgrader ) {
		// Only intervene for URLs we are watching.
		if ( ! $this->should_proxy( $package ) ) {
			return $reply;
		}

		// Download file with auth headers (the HTTP Client injects the token
		// automatically for github.com / api.github.com URLs).
		$response = $this->http->get( $package, [ 'stream' => true ] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'interplay_download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Interplay Services could not download the package: %s', 'interplay-services' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'interplay_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Interplay Services received HTTP %d while downloading the package.', 'interplay-services' ),
					$code
				)
			);
		}

		// Return the path to the streamed temp file that wp_remote_get wrote.
		$tmp_path = (string) wp_remote_retrieve_body( $response );

		if ( ! is_readable( $tmp_path ) ) {
			return new \WP_Error(
				'interplay_download_failed',
				__( 'Interplay Services: downloaded file is not readable.', 'interplay-services' )
			);
		}

		return $tmp_path;
	}

	private function should_proxy( string $url ): bool {
		if ( in_array( $url, $this->watched_urls, true ) ) {
			return true;
		}

		// Fallback: proxy any api.github.com zipball url whenever a token is set.
		if ( str_contains( $url, 'api.github.com' ) && get_option( 'interplay_services_github_token', '' ) !== '' ) {
			return true;
		}

		return false;
	}
}
