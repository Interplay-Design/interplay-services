<?php
/**
 * Shared slug resolution for updater install paths.
 *
 * Normalizes expected destination slugs for theme/plugin upgrades,
 * including GitHub zipball hash folder cleanup.
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InstallSlugResolver {

	/**
	 * Resolve the expected install folder slug for an upgrade.
	 *
	 * Prefers watched package metadata when available, then falls back
	 * to upgrader hook data. GitHub hash-style slugs are normalized.
	 *
	 * @param array<string,mixed>                  $hook_extra
	 * @param array<string,array<string,string>>   $watched_packages
	 */
	public function resolve_expected_slug( string $type, array $hook_extra, array $watched_packages ): string {
		$package = (string) ( $hook_extra['package'] ?? '' );
		if ( $package !== '' && isset( $watched_packages[ $package ] ) ) {
			$watched_type = (string) ( $watched_packages[ $package ]['type'] ?? '' );
			$watched_id   = (string) ( $watched_packages[ $package ]['id'] ?? '' );

			if ( $watched_type === $type && $watched_id !== '' ) {
				return $this->slug_from_product_id( $type, $watched_id );
			}
		}

		$slug = $this->slug_from_hook_extra( $type, $hook_extra );
		return $this->normalize_github_hash_slug( $slug );
	}

	private function slug_from_product_id( string $type, string $id ): string {
		if ( $type === 'theme' ) {
			return sanitize_title( $id );
		}

		if ( $type === 'plugin' ) {
			$slug = dirname( $id );
			if ( $slug === '.' || $slug === '' ) {
				$slug = sanitize_title( basename( $id, '.php' ) );
			}

			return $slug;
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $hook_extra
	 */
	private function slug_from_hook_extra( string $type, array $hook_extra ): string {
		if ( $type === 'theme' ) {
			return sanitize_title( (string) ( $hook_extra['theme'] ?? '' ) );
		}

		if ( $type === 'plugin' ) {
			$plugin = (string) ( $hook_extra['plugin'] ?? '' );
			$slug = dirname( $plugin );

			if ( $slug === '.' || $slug === '' ) {
				$slug = sanitize_title( basename( $plugin, '.php' ) );
			}

			return $slug;
		}

		return '';
	}

	/**
	 * Convert GitHub zipball-style slugs (repo-<sha>) to canonical repo slug.
	 */
	private function normalize_github_hash_slug( string $slug ): string {
		if ( $slug === '' ) {
			return '';
		}

		if ( preg_match( '/^(?:.+-)?([a-z0-9]+(?:-[a-z0-9]+)*)-[0-9a-f]{7,40}$/i', $slug, $m ) === 1 ) {
			return strtolower( (string) $m[1] );
		}

		return $slug;
	}
}