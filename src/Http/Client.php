<?php
/**
 * Authenticated HTTP client.
 *
 * Thin wrapper around wp_remote_get / wp_remote_post that:
 *  - injects a User-Agent identifying the plugin
 *  - handles GitHub token auth transparently for github.com requests
 *  - provides consistent error-normalisation
 *
 * Future: swap to a proper interface + adapter if we add other HTTP
 * backends (streams, Guzzle, etc.).
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Http;

use Interplay\Services\Log\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Client {

	// Default timeout for remote requests (seconds).
	private const TIMEOUT = 15;

	// Hosts we recognise as GitHub. Match must be exact or a subdomain.
	private const GITHUB_HOSTS = [ 'github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com' ];

	/**
	 * Perform a GET request.
	 *
	 * @param  string               $url     Fully-qualified URL.
	 * @param  array<string,mixed>  $args    wp_remote_get args (merged with defaults).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get( string $url, array $args = [] ) {
		$args = $this->merge_defaults( $url, $args );
		return wp_remote_get( $url, $args );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param  string               $url
	 * @param  array<string,mixed>  $args
	 * @return array<string,mixed>|\WP_Error
	 */
	public function post( string $url, array $args = [] ) {
		$args = $this->merge_defaults( $url, $args );
		return wp_remote_post( $url, $args );
	}

	/**
	 * Convenience wrapper: decode a JSON GET response.
	 *
	 * Returns null on any error (network, HTTP 4xx/5xx, invalid JSON).
	 *
	 * @param  string               $url
	 * @param  array<string,mixed>  $args
	 * @return mixed|null
	 */
	public function get_json( string $url, array $args = [] ) {
		$response = $this->get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log_error( $response->get_error_message(), $url );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$this->log_error( "HTTP {$code}", $url );
			return null;
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->log_error( 'JSON decode failed: ' . json_last_error_msg(), $url );
			return null;
		}

		return $decoded;
	}

	// ─── Internals ────────────────────────────────────────────────────────────

	/**
	 * Build merged request args, injecting auth for GitHub requests.
	 *
	 * @param  string               $url
	 * @param  array<string,mixed>  $args
	 * @return array<string,mixed>
	 */
	private function merge_defaults( string $url, array $args ): array {
		$defaults = [
			'timeout'    => self::TIMEOUT,
			'user-agent' => $this->user_agent(),
			'headers'    => [],
		];

		$args            = \wp_parse_args( $args, $defaults );
		$args['headers'] = array_merge(
			(array) ( $defaults['headers'] ?? [] ),
			(array) ( $args['headers']    ?? [] )
		);

		if ( $this->is_github_url( $url ) ) {
			$token = $this->github_token();
			if ( $token !== '' ) {
				$args['headers']['Authorization'] = 'token ' . $token;
			}
			// GitHub requires a specific Accept header for release assets.
			$args['headers']['Accept'] = 'application/vnd.github.v3+json';
		}

		return $args;
	}

	private function is_github_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' ) {
			return false;
		}

		foreach ( self::GITHUB_HOSTS as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Public so other components can check token availability without
	 * recreating the constant/env/option lookup chain.
	 */
	public function github_token(): string {
		if ( defined( 'INTERPLAY_SERVICES_GITHUB_TOKEN' ) && constant( 'INTERPLAY_SERVICES_GITHUB_TOKEN' ) !== '' ) {
			return (string) constant( 'INTERPLAY_SERVICES_GITHUB_TOKEN' );
		}

		$env = getenv( 'INTERPLAY_SERVICES_GITHUB_TOKEN' );
		if ( is_string( $env ) && $env !== '' ) {
			return $env;
		}

		return (string) get_option( 'interplay_services_github_token', '' );
	}

	private function user_agent(): string {
		global $wp_version;
		return sprintf(
			'InterplayServices/%s; WordPress/%s; PHP/%s',
			\INTERPLAY_SERVICES_VERSION,
			$wp_version ?? '?',
			PHP_VERSION
		);
	}

	private function log_error( string $message, string $url ): void {
		Logger::instance()->warn( 'HTTP error: ' . $message, [ 'url' => $url ] );
	}
}
