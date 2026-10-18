=== Detour ===
Contributors: centrevilletech
Tags: 404, redirect, broken-links, seo, gone
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart 404 recovery for WordPress. Catch every Not Found, redirect lost visitors to a destination that converts, and serve 410 Gone for dead pages.

== Description ==

Detour catches every 404 (Not Found) hit on your WordPress site and either redirects the visitor to a destination of your choice or returns 410 Gone for content that is permanently deleted. Every miss is logged, sortable, and one click away from becoming a permanent rule.

Built for high-traffic sites where a sloppy 404 log can drag the admin panel to a crawl.

= Per-URL rules =

* **Three match modes**: exact URL, prefix (with optional `{path}` substitution), and regular expression
* **Five status codes**: 301 Moved Permanently, 302 Found, 307 Temporary, 308 Permanent, and 410 Gone for permanently-deleted pages
* **Site-wide fallback** runs only when no rule matches, with its own configurable status code
* **Promote-to-rule** one-click button on the 404 URLs log so you can fix a broken URL the moment you see it
* **Object-cache-backed lookups** with versioned bust on every rule write, so high-volume 404 traffic costs at most one indexed query

= Logging and reporting =

* Sortable hit history that stays fast past 1M rows
* Per-IP and referrer deduplication so a single bot run does not flood your stats
* Optional weekly, daily, or monthly email digests with top broken URLs and trend analysis
* Admin-bar widget with today, yesterday, this month, and all-time redirect counts

= Engineered for high traffic =

* `BINARY(16)` hash-keyed lookups for both the rule table and the log table
* Indexed counters on hit_count and last_redirected for fast admin sorts
* Object-cache-backed rule lookups with a versioned bust strategy so writes invalidate cheaply

= Privacy and ethics =

* No tracking. No telemetry. No upgrade nags.
* No bundled commercial code, no affiliate links, no backlink injection in admin notices or email digests.
* GPLv2-or-later, all the way down.

== Heritage ==

Detour is a fork of "All 404 Redirect to Homepage" by wp-buy, also released under GPL-2.0-or-later. Notable changes from the upstream:

* Added a per-URL rules engine (a feature locked behind upstream's commercial "Pro" tier), with three match types and five status codes including 410 Gone
* Schema overhaul on the log table: real `BINARY(16)` URL hash column with a generated index, dedicated `count` index so admin sort-by-hits is a 10-row index lookup instead of a full-table filesort, and removal of a duplicate prefix index that wasted writes
* Removed bundled commercial JavaScript that was being redistributed without a license
* Removed promotional surface: upsell banners, "More Plugins" plugin-action links, "UPGRADE" admin notices, an empty plugin-row dismiss notice, and outbound backlinks in email digests
* Removed a parallel cron-scheduling implementation that was never wired up, an unused fake-data referrer fallback, a never-called option-migration helper, and a raw-SQL helper that referenced a non-existent global

== Installation ==

1. Upload the `detour` folder to `/wp-content/plugins/`, or install via the WordPress plugin directory.
2. Activate **Detour** in *Plugins → Installed Plugins*.
3. Configure the redirect target under *Settings → Detour*.
4. Add per-URL rules under *Settings → Detour → Rules*.

On activation Detour creates its log and rules tables and runs an idempotent schema migration. Re-activation is safe; the migration short-circuits via an option flag.

== Frequently Asked Questions ==

= Will it interfere with my existing redirect plugin? =

Detour fires on the WordPress `wp` hook when a request is a 404. If a redirect plugin handles the request earlier (for example, a server-level rewrite or a higher-priority hook), Detour will not see it. Generally, only one 404 handler should be active at a time.

= What happens if I delete the plugin? =

Detour leaves its tables (`{$prefix}redirects_404` and `{$prefix}detour_rules`) and options in place when you deactivate. Uninstalling cleans up an `.htaccess` marker block but does not drop the tables; that decision is left to you.

= Why do I need 410 Gone when 301 redirects work? =

The 404 status tells search engines "this page does not exist (yet)." A 301 tells them "the page moved here." A 410 tells them "the page existed and is permanently gone, do not look for it again." Returning 301 to your homepage for content that no longer exists is bad SEO hygiene because it dilutes link equity and confuses crawlers about your site structure. 410 is the technically correct response.

= Does the rules table scale? =

Yes. Exact-match rules use a `BINARY(16)` MD5 hash column with its own BTREE index, so any single URL lookup is one indexed query regardless of how many exact-match rules you have. Prefix and regex rule lists are loaded once per request from the WordPress object cache (busted versionally on writes), so for typical sites the entire rule evaluation is in-memory.

= Where do I see what 404s have been hitting my site? =

*Settings → Detour → 404 URLs*. Sortable by URL, hit count, or last seen.

== Screenshots ==

1. The Options tab: configure the site-wide 404 destination, redirect type, image fallback, and email digest schedule.
2. The Rules tab: per-URL rules with three match types and five status codes including 410 Gone.
3. The Add Rule modal: pick a match type and status, supply source and destination, optional notes.
4. The 404 URLs log: sortable hit history with a one-click "Make rule" button on every row.

== Changelog ==

= 1.5.0 =
* Fixed: Add, Edit, Delete and Toggle on the Rules tab did nothing. The 1.2.0 prefix rename changed the AJAX action names in the JavaScript but not in PHP.
* Fixed: prefix and regex rules never matched a site-rooted path such as `/old/` because they were tested against the full URL. They now test the path (with query string) first, then the full URL.
* Exact rules now match a full URL or a site-rooted path, with or without the trailing slash, and fall back to a lowercase path. A rule without a query string also matches requests that have one, and passes that query string on to the destination so campaign tags survive.
* Exact rules now apply even when the old URL still resolves, such as a leftover archive page. Prefix rules, regex rules and the site-wide default still act only on 404s.
* Regex destinations accept `$1`-style backreferences.
* New WP-CLI commands: `wp detour import <file.csv>` (with `--dry-run` and `--replace`), `wp detour export`, and `wp detour test <url>`.

= 1.4.0 =
* UI rebrand: dropped "404" from headings, navigation, form labels, the admin-bar item, and email digest titles. Replaced with brand-forward labels ("Detour", "Activity", "Default Redirect", "Missing Image Fallback", etc.). Kept "404" where it is the technically meaningful term in body copy.
* Added wp.org icon and banner assets (128/256 icons, 772x250 / 1544x500 banners).

= 1.3.0 =
* Coding-style pass against the WordPress-Extra and Universal PHPCS rulesets. Tabs everywhere, K&R braces, paren and bracket spacing, Yoda conditions, and strict comparisons (`===`/`!==`) wherever the right-hand side is a literal. Net: ~4,800 sniff violations cleaned up, no behavior changes.

= 1.2.0 =
* Refactored every PHP identifier (functions, classes, constants) onto a unified `detour_` / `Detour_` / `DETOUR_` prefix for stronger isolation. Existing options, table names, and stored data are unchanged.
* Renamed source files: `centct-fixes.php` to `detour-fixes.php`, `centct-rules.php` to `detour-rules.php`.
* Added a one-time legacy-cron cleanup so sites upgrading from 1.1 reschedule their email digests under the new hook names.
* Added a wp.org-compliant `readme.txt`.

= 1.1.0 =
* New per-URL rule engine: exact, prefix (with optional `{path}` substitution), and regex match types, with 301/302/307/308/410 support.
* New "Rules" tab in the admin with create, edit, delete, toggle, and search.
* New "Make rule" button on the 404 URLs log to promote a logged 404 directly into a rule.
* Removed an empty "dismiss this message" admin-row notice carried over from upstream.
* Removed an entire dead parallel cron-scheduling implementation that was never wired up.
* Removed a fake-data referrer fallback used in test emails.
* Removed a `data_bind()` helper that referenced a non-existent global.
* Removed twelve "operation succeeded" `error_log` calls polluting production logs.
* Removed a duplicate `add_action` registration.
* Throttled the `.htaccess` re-read that previously ran on every admin pageview.

= 1.0.0 =
* Initial release. Forked from "All 404 Redirect to Homepage" by wp-buy under GPL-2.0-or-later, with bundled commercial code removed, promotional surface stripped out, and a high-performance schema migration applied to the 404 log table (`BINARY(16)` URL hash key, indexed counters, removal of a duplicate prefix index).

== Upgrade Notice ==

= 1.2.0 =
Adds a wp.org-compliant readme and unifies internal prefixes onto `detour_*`. Existing data and settings are preserved.

= 1.1.0 =
Adds a per-URL rules engine. Existing site-wide redirect settings continue to work as a fallback when no rule matches.
