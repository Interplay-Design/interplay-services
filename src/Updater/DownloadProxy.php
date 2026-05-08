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

	/** @var array<string,array<string,string>> Package URLs keyed to update metadata. */
	private array $watched_packages = [];

	public function __construct( private readonly Client $http ) {}

	public function register_hooks(): void {
		add_filter( 'upgrader_pre_download', [ $this, 'maybe_proxy_download' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'normalize_source_selection' ], 10, 4 );
	}

	/**
	 * Register a URL that should be downloaded with authentication.
	 * Called by UpdateManager when it builds the update transient.
	 */
	public function watch( string $url, array $meta = [] ): void {
		if ( $url === '' ) {
			return;
		}

		$this->watched_packages[ $url ] = [
			'type' => (string) ( $meta['type'] ?? '' ),
			'id'   => (string) ( $meta['id'] ?? '' ),
		];
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
		$tmp_file = wp_tempnam( basename( (string) wp_parse_url( $package, PHP_URL_PATH ) ) ?: 'interplay-package.zip' );
		if ( ! $tmp_file ) {
			return new \WP_Error(
				'interplay_download_failed',
				__( 'Interplay Services could not create a temporary file for download.', 'interplay-services' )
			);
		}

		$response = $this->http->get(
			$package,
			[
				'stream'   => true,
				'filename' => $tmp_file,
			]
		);

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

		// For streamed requests WordPress stores the temp file path in
		// $response['filename'] (body is usually empty).
		$tmp_path = '';
		if ( is_array( $response ) && isset( $response['filename'] ) ) {
			$tmp_path = (string) $response['filename'];
		}

		if ( $tmp_path === '' ) {
			$tmp_path = (string) wp_remote_retrieve_body( $response );
		}

		if ( ! is_readable( $tmp_path ) ) {
			return new \WP_Error(
				'interplay_download_failed',
				__( 'Interplay Services: downloaded file is not readable.', 'interplay-services' )
			);
		}

		return $tmp_path;
	}

	private function should_proxy( string $url ): bool {
		if ( isset( $this->watched_packages[ $url ] ) ) {
			return true;
		}

		// Fallback: proxy any api.github.com zipball url whenever a token is set.
		if ( str_contains( $url, 'api.github.com' ) && get_option( 'interplay_services_github_token', '' ) !== '' ) {
			return true;
		}

		return false;
	}

	/**
	 * Hook: 'upgrader_source_selection'
	 *
	 * Normalize extracted GitHub zipball directories back to the canonical
	 * theme/plugin slug so updates install into the expected target folder.
	 *
	 * @param string       $source
	 * @param string       $remote_source
	 * @param \WP_Upgrader $upgrader
	 * @param array        $hook_extra
	 * @return string|\WP_Error
	 */
	public function normalize_source_selection( $source, string $remote_source, \WP_Upgrader $upgrader, array $hook_extra ) {
		if ( ! is_string( $source ) || $source === '' ) {
			return $source;
		}

		$type = (string) ( $hook_extra['type'] ?? '' );
		$expected = '';

		if ( $type === 'theme' ) {
			$expected = sanitize_title( (string) ( $hook_extra['theme'] ?? '' ) );
		} elseif ( $type === 'plugin' ) {
			$plugin = (string) ( $hook_extra['plugin'] ?? '' );
			$expected = dirname( $plugin );
			if ( $expected === '.' ) {
				$expected = '';
			}
		}

		if ( $expected === '' ) {
			return $source;
		}

		$current = basename( untrailingslashit( $source ) );
		if ( $current === $expected ) {
			return $source;
		}

		$normalized = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $expected;

		if ( file_exists( $normalized ) ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				WP_Filesystem();
			}
			if ( $wp_filesystem && method_exists( $wp_filesystem, 'delete' ) ) {
				$wp_filesystem->delete( $normalized, true );
			}
		}

		if ( @rename( $source, $normalized ) ) {
			return $normalized;
		}

		return $source;
	}
}
