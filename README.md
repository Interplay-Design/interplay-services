# Interplay Services

Interplay Services is the shared service layer for Interplay WordPress products. It centralizes product registration, update delivery, GitHub-backed release checks, and the foundation for future license enforcement.

## What It Does

- Registers Interplay-managed products in one registry.
- Injects native WordPress theme updates for the Intro theme.
- Injects native WordPress plugin updates for Interplay Services itself.
- Supports GitHub Releases as the current update source.
- Supports private-repo downloads through a download proxy when authentication is required.
- Exposes a settings page for GitHub token management, license-key storage, and product visibility.

## Current Managed Products

- `intro` theme
- `interplay-services/interplay-services.php` plugin

## Repository Model

- Intro is currently distributed from a private GitHub repository.
- Interplay Services is intended to update from the public GitHub repository at `interplaydesign/interplay-services`.
- The updater expects GitHub Releases to be the source of truth for published versions.

## Installation

Standard plugin install:

1. Copy the plugin to `wp-content/plugins/interplay-services/`.
2. Activate **Interplay Services** in wp-admin.

Theme-driven install via Intro:

1. Set `INTRO_INTERPLAY_SERVICES_ZIP_URL` in `wp-config.php` to a public release zip.
2. Activate the Intro theme.
3. Intro will attempt to install and activate the plugin automatically.
4. If `wp-content/mu-plugins/` is writable, Intro will also create a small MU loader so the plugin is always loaded when present.

## Configuration

### GitHub token

For private GitHub repositories, set a fine-grained PAT in one of these ways:

- WordPress admin: `Settings > Interplay Services`
- `wp-config.php`: `INTERPLAY_SERVICES_GITHUB_TOKEN`
- environment variable: `INTERPLAY_SERVICES_GITHUB_TOKEN`

For public repositories, a token is not required for downloading public release assets.

### License key

License enforcement is not active in this beta, but the plugin already supports sourcing a license key from:

- WordPress admin
- `INTERPLAY_SERVICES_LICENSE_KEY` constant
- `INTERPLAY_SERVICES_LICENSE_KEY` environment variable

## Release Process

To publish an update that WordPress can detect:

1. Bump the plugin version in `interplay-services.php`.
2. Commit and push to `main`.
3. Create a GitHub Release in `interplaydesign/interplay-services`.
4. Attach a plugin zip asset if you want a stable package filename; otherwise the updater can fall back to GitHub's generated source archive.

## Architecture Overview

- `interplay-services.php`: plugin bootstrap and constants
- `src/Plugin.php`: lightweight service container and boot sequence
- `src/Registry/`: managed product registration
- `src/Updater/`: update orchestration, source drivers, and download proxy
- `src/Admin/SettingsPage.php`: settings UI and product visibility
- `src/License/`: current license-key storage and status handling

## Notes

- The self-update path is wired through WordPress's native `update_plugins` transient and `plugins_api` modal integration.
- Public GitHub releases work without the authenticated download proxy.
- Private GitHub release assets still use the proxy path when a PAT is configured.