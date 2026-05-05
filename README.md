# Detour

**Catch every 404 and route lost visitors to a destination that converts.**

Detour is a WordPress plugin that intercepts 404 (Not Found) requests and redirects them to a destination of your choice: your homepage, a campaign page, or any URL, instead of letting visitors bounce. Every miss is logged, sortable, and exportable so you can see exactly which broken links are eating your SEO.

## Features

- **301 or 302 redirects** to any URL, configurable per-site
- **Sortable hit history** (by URL, hit count, or last seen) that stays fast past 1M rows
- **Per-IP + referrer deduplication** so a single bot run does not flood your stats
- **Weekly email digests** with top broken URLs and trend analysis
- **Image fallback**: replace missing `.gif`/`.jpg`/`.png` requests with a chosen image
- **Admin-bar widget** with today / yesterday / month / total redirect counts
- **Engineered for high traffic**: `BINARY(16)` hash-keyed lookups, indexed counters, deduplicated indexes

## Installation

1. Upload the `detour` folder to `wp-content/plugins/`
2. Activate **Detour** in *Plugins → Installed Plugins*
3. Configure the redirect target under *Settings → Detour*

On activation Detour creates its log table and runs an idempotent schema migration to add high-performance indexes. Re-activation is safe; the migration short-circuits via an option flag.

## Requirements

- WordPress 5.0+
- PHP 7.4+ (tested through PHP 8.3)
- MySQL 5.7+ / MariaDB 10.5+ (uses `BINARY(16)` generated columns)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Attribution

Detour is a fork of [All 404 Redirect to Homepage](https://wordpress.org/plugins/all-404-redirect-to-homepage/) by wp-buy, originally released under GPL-2.0-or-later.

Notable changes from upstream:

- **Schema overhaul**: added a real `BINARY(16)` URL hash column with a generated index for fixed-width equality lookups, added a `count` index so admin "sort by hits" no longer triggers a full-table filesort, removed a duplicate prefix index that was wasting writes
- **Removed bundled commercial code**: `canvasjs.min.js` was redistributed in the original without a commercial license; replaced with no-chart numeric stats
- **Removed promotional surface**: upsell banners on the settings page, "More Plugins" plugin-action links, "UPGRADE" admin notices, and outbound backlinks in email digests
- **Renamed and rebranded**: display name, menu titles, error messages, and text domain
- **Drop-in for existing installs**: table name (`{$prefix}redirects_404`), option keys (`p404_redirect_options`, `options-404-redirect-group`, etc.) and stored data are unchanged

Maintained by [Centreville Tech LLC](https://centrevilletech.com).
