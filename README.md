# Interplay Services

Interplay Services is the shared service layer for Interplay WordPress products. It operates as a must-use (MU) plugin, centralizing product registration, update delivery, GitHub-backed release checks, and the foundation for future license enforcement.

## What It Does

- Registers Interplay-managed products in one system-wide registry.
- Injects native WordPress theme updates for the Intro theme.
- Supports GitHub Releases as the current update source.
- Supports private-repo downloads through a download proxy when authentication is required.
- Exposes a settings page for GitHub token management, license-key storage, and product visibility.

## Current Managed Products

- `intro` theme

## Deployment Model

Interplay Services is deployed as a must-use plugin in `wp-content/mu-plugins/interplay-services/`. This means:

- The service layer is always loaded; it cannot be accidentally deactivated.
- Intro theme automatically installs and updates Interplay Services when needed.
- MU plugin updates are managed through custom Interplay deployment mechanisms.

## Installation

When Intro is activated, it automatically:

1. Detects if Interplay Services is installed.
2. Downloads and extracts the MU plugin to `wp-content/mu-plugins/interplay-services/`.
3. The plugin loads on the next page load (no activation step required).

### Manual Install

If needed, manually place the Interplay Services folder in `wp-content/mu-plugins/`:

```
wp-content/mu-plugins/interplay-services/
  └── interplay-services.php
  └── src/
  └── ...
```

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