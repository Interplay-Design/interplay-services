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
use Interplay\Services\Log\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DownloadProxy {

	/** @var array<string,array<string,string>> Package URLs keyed to update metadata. */
	private array $watched_packages = [];

	/** Most-recently-proxied package URL in the current request, used to correlate
	 *  the download with the source_selection rename so we can map back to product
	 *  metadata even when hook_extra is missing fields. */
	private string $last_proxied_url = '';

	private InstallSlugResolver $slug_resolver;

	public function __construct( private readonly Client $http ) {
		$this->slug_resolver = new InstallSlugResolver();
	}

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
	public function maybe_proxy_download( $reply, $package, $upgrader ) {
		$log        = Logger::instance();
		$should     = is_string( $package ) && $this->should_proxy( $package );
		$package_s  = is_string( $package ) ? $package : '<' . gettype( $package ) . '>';

		$log->debug( 'pre_download enter', [
			'package'      => $package_s,
			'should_proxy' => $should,
			'watched_keys' => array_keys( $this->watched_packages ),
		] );

		if ( ! is_string( $package ) || ! $should ) {
			return $reply;
		}

		// Remember the URL so the upgrader_source_selection callback can map
		// the extracted directory back to product metadata when hook_extra
		// doesn't carry the package URL.
		$this->last_proxied_url = $package;

		// Download file with auth headers (the HTTP Client injects the token
		// automatically for github.com / api.github.com URLs).
		$base_name = basename( (string) wp_parse_url( $package, PHP_URL_PATH ) );
		if ( $base_name === '' ) {
			$base_name = 'interplay-package.zip';
		}

		$tmp_file = wp_tempnam( $base_name );
		if ( ! $tmp_file ) {
			$log->error( 'wp_tempnam returned false', [ 'package' => $package ] );
			return new \WP_Error(
				'interplay_download_failed',
				__( 'Interplay Services could not create a temporary file for download.', 'interplay-services' )
			);
		}

		$log->info( 'download starting', [ 'package' => $package, 'tmp' => $tmp_file ] );
		$start = microtime( true );

		$response = $this->http->get(
			$package,
			[
				'timeout'   => 300,
				'stream'    => true,
				'filename'  => $tmp_file,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_file );
			$log->error( 'download wp_error', [
				'package' => $package,
				'message' => $response->get_error_message(),
				'code'    => $response->get_error_code(),
			] );
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
			@unlink( $tmp_file );
			$log->error( 'download non-2xx', [ 'package' => $package, 'http_code' => $code ] );
			return new \WP_Error(
				'interplay_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Interplay Services received HTTP %d while downloading the package.', 'interplay-services' ),
					$code
				)
			);
		}

		// Streamed responses have an empty body; the file is at $tmp_file.
		// (We pass it explicitly so we don't rely on $response['filename'].)
		clearstatcache( true, $tmp_file );

		$size = is_readable( $tmp_file ) ? (int) filesize( $tmp_file ) : 0;
		if ( ! is_readable( $tmp_file ) || $size === 0 ) {
			@unlink( $tmp_file );
			$log->error( 'download empty or unreadable', [
				'package' => $package,
				'tmp'     => $tmp_file,
				'size'    => $size,
			] );
			return new \WP_Error(
				'interplay_download_failed',
				__( 'Interplay Services: downloaded file is empty or unreadable.', 'interplay-services' )
			);
		}

		$log->info( 'download complete', [
			'package' => $package,
			'bytes'   => $size,
			'ms'      => (int) ( ( microtime( true ) - $start ) * 1000 ),
		] );

		return $tmp_file;
	}

	private function should_proxy( string $url ): bool {
		if ( $url === '' ) {
			return false;
		}

		if ( isset( $this->watched_packages[ $url ] ) ) {
			return true;
		}

		// Fallback: proxy any api.github.com zipball URL whenever a token is
		// available (constant / env / option). Without auth, a private repo
		// zipball will return 404 / redirect-to-login.
		if ( str_contains( $url, 'api.github.com' ) && $this->http->github_token() !== '' ) {
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
	public function normalize_source_selection( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		try {
			return $this->do_normalize_source_selection( $source, $remote_source, $upgrader, $hook_extra );
		} catch ( \Throwable $e ) {
			Logger::instance()->exception( 'DownloadProxy.normalize_source_selection', $e );
			return $source;
		}
	}

	private function do_normalize_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		$log = Logger::instance();

		$log->debug( 'source_selection enter', [
			'source'       => is_string( $source ) ? $source : '<' . gettype( $source ) . '>',
			'hook_extra'   => $hook_extra,
			'last_proxied' => $this->last_proxied_url,
			'watched_keys' => array_keys( $this->watched_packages ),
		] );

		if ( ! is_string( $source ) || $source === '' ) {
			return $source;
		}

		if ( ! is_array( $hook_extra ) ) {
			$hook_extra = [];
		}

		$type = (string) ( $hook_extra['type'] ?? '' );

		if ( $this->last_proxied_url !== '' && empty( $hook_extra['package'] ) ) {
			$hook_extra['package'] = $this->last_proxied_url;
		}

		$expected = $this->slug_resolver->resolve_expected_slug( $type, $hook_extra, $this->watched_packages );
		$log->debug( 'resolved expected slug', [ 'type' => $type, 'expected' => $expected ] );

		if ( $expected === '' ) {
			$log->warn( 'source_selection: expected slug empty, leaving source untouched', [
				'type'       => $type,
				'hook_extra' => $hook_extra,
			] );
			return $source;
		}

		$source_no_trailing = untrailingslashit( $source );
		$current            = basename( $source_no_trailing );
		if ( $current === $expected ) {
			return $source;
		}

		$parent     = trailingslashit( dirname( $source_no_trailing ) );
		$normalized = $parent . $expected;

		if ( file_exists( $normalized ) ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();
			}
			if ( $wp_filesystem && method_exists( $wp_filesystem, 'delete' ) ) {
				$wp_filesystem->delete( $normalized, true );
			}
		}

		$renamed = @rename( $source_no_trailing, $normalized );

		if ( $renamed ) {
			$log->info( 'source folder renamed', [
				'from' => $current,
				'to'   => $expected,
			] );
			return substr( $source, -1 ) === '/' ? trailingslashit( $normalized ) : $normalized;
		}

		$log->error( 'source folder rename FAILED', [
			'from' => $source_no_trailing,
			'to'   => $normalized,
		] );
		return $source;
	}
}
