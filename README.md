# WP Plugin Health Check

Audit your active plugins against WordPress.org — spot abandoned plugins, untested WordPress versions and PHP incompatibilities before they become a security problem.

## Why

Plugins are the #1 way WordPress sites get compromised, and the risk concentrates in plugins nobody maintains any more. WordPress tells you when an *update* exists, but it never tells you a plugin was **abandoned two years ago**, was **never tested on your WordPress version**, or **needs a newer PHP** than your server runs. This plugin checks all three, in one place.

## Features

- **Abandoned-plugin detection** — flags any active plugin not updated on WordPress.org for N months (default 24, configurable, `wphc_abandoned_months` filter).
- **WordPress compatibility** — warns when a plugin was only tested up to an older WordPress version than you run.
- **PHP compatibility** — critical alert when a plugin requires a newer PHP than the server runs.
- **Unknown plugins** — premium/custom plugins not on WordPress.org are listed as "Unknown" instead of silently skipped.
- **Cached API data** — WordPress.org responses are cached for 12 hours; a "Refresh data" button clears the cache on demand.
- **Weekly email digest** — emails the admin only when a plugin turns critical (toggle + address in Settings, or disable it entirely).

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Open **Tools → Plugin Health**.

## Uninstall

Deleting the plugin removes its settings, the weekly cron event and all cached WordPress.org responses.
