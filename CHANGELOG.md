# Changelog

All notable changes to **External Link Control by thisismyurl.com** are recorded here. The plugin uses a `x.Yddd` Julian-day version scheme: `x` is the release class (`0` = pre-release, `1` = full), `Y` is the last digit of the year, and `ddd` is the day of year (001-366).

## [1.6190.1000] — 2026-07-09

### Added
- **noreferrer toggle** — Strip Referrer on new-tab links is now a user-controlled setting (previously hardcoded ON). Turn it off when you need affiliate tracking, partner analytics, or editorial-credit referral headers to reach the destination. `noopener` is always applied regardless.
- **Link Inventory panel** — new postbox on the settings page lists every external domain found in published content, the number of links pointing to it, and whether a domain rule already covers it. Backed by the existing REST endpoint (`/timu-elc/v1/inventory`) with a 1-hour transient cache.
- **Domain rules Remove button** — each row in the rules table now has a Remove button; an Add Row button below the table adds blank rows without a page reload.
- **Broken-link sidebar status** — the settings sidebar now shows how many broken links were found on the last scan and when that scan ran (or prompts you to run one for the first time).
- **Sponsored/nofollow guidance** — an inline note explains that Sponsored already implies nofollow and the two should not be combined with Allowlist.
- **WP-CLI reference sidebar** — inline command examples for the scanner, dismiss, and reset commands alongside the quick-links.

### Improved
- `readme.txt` rewritten to WP.org plugin directory submission quality: structured Description, 13-item FAQ, Screenshots stub, Changelog, and Upgrade Notice sections.
- Admin stylesheet split out of inline HTML into `assets/css/admin.css`; admin JS extended with domain-rules and inventory behaviour.

## 1.6158 — 2026-06-23

WordPress.org directory submission compliance pass. All 5 gate-blocking issues resolved.

### Fixed
- **B1** Removed `GitHub Plugin URI` and `Primary Branch` header lines from the main plugin file. These are GitHub Updater development metadata and must not be present in a .org-distributed package.
- **B2** Renamed `ELC_Link_Checker` class to `TIMU_ELC_Link_Checker` for prefix uniqueness. The `ELC_` prefix is too short and non-unique across 60,000+ plugins; the rest of the codebase already uses `TIMU_ELC_*`.
- **D3** Registered a custom `timu_elc_weekly` cron schedule via `cron_schedules`. WordPress does not include a built-in `weekly` interval; `wp_schedule_event` was silently falling back to the closest registered interval.
- **B5** Corrected `Tested up to` in `readme.txt` from an unreleased version number to `6.7` (the highest actually-tested released version).
- **Header** Added `License URI` line to the main plugin file header.

---

## 1.6148 — 2026-05-27

Broken-link checker overhaul. Addresses the un-dismissable notice, false positives on bot-blocked hosts, and the lack of any way to mute links the owner does not care about.

### Fixed
- **The dismiss button now persists.** The plugin shipped no JavaScript, so the core `is-dismissible` "X" only removed the notice from the DOM for the current page load — the AJAX dismiss endpoint that flips the stored flag was never called, and the notice returned on the next admin screen. A small `assets/js/admin.js` now records the dismissal server-side.
- **Dismissals are remembered per link set, not globally.** The results option now stores a `dismissed_urls` fingerprint instead of a single boolean. The notice only reappears when a later scan finds a broken URL that was not in the dismissed set — recurring known-broken links stay quiet until they are fixed or a genuinely new break appears. The scan no longer blindly resets the dismissed flag on every run.
- **Far fewer false positives.** Classification now sorts results into `broken` (404, 410, or a dead domain whose host no longer resolves) versus `unverified` (401/403/405/429/451/999, other non-2xx/3xx, and transient network errors such as timeouts and TLS failures). Only `broken` raises the notice. LinkedIn (405 to HEAD) and time.com (403 to bots) no longer count as broken.
- **HEAD-hostile servers get a GET retry.** A HEAD that returns 400/403/405/406/501 or errors is retried once with `GET` (asking for a single byte via `Range`) before any verdict, so servers and CDNs that reject HEAD stop registering as broken.

### Added
- **Ignore list** (`timu_elc_broken_link_ignored`). An "Ignore" action on each link in the dashboard widget mutes a URL or a whole host; ignored entries are skipped at collection time, so they cost no request and never surface. A "Stop ignoring" action and an "Ignored" section round out management from the same widget.
- **"Scan now" button** in the dashboard widget. Schedules the check to run immediately (spawns cron; falls back to an inline run when `DISABLE_WP_CRON` is set) instead of waiting for the weekly event.
- **"Could not verify" section** in the widget surfaces the `unverified` bucket for transparency without alarming.
- **Per-link "Recheck"** re-tests a single URL on demand and moves it to the correct bucket.
- **`timu_elc_broken_status_codes` filter** to tune which HTTP codes count as broken (defaults to `[404, 410]`).

### Notes
- The dashboard widget's JS and the shared admin CSS now enqueue admin-wide for `manage_options` users (the notice can appear on any screen). The asset is small and capability-gated.
- All AJAX actions (dismiss, ignore, unignore, recheck, scan-now) are nonce-verified (`timu_elc_link_actions`) and capability-gated.

## 1.6147 — 2026-05-27

### Changed
- Unified plugin versioning to the `x.Yddd` calendar-version scheme.
- Confirmed compatibility with WordPress 6.7.

## 1.6143 — 2026-05-23

### Changed
- Promoted to a full release (class 1). The `0.6xxx` line was pre-release on the `x.Yddd` scheme.
- Standardized the donation link to GitHub Sponsors (`https://github.com/sponsors/thisismyurl`).

## 0.6123 — 2026-05-03

Audit-driven overhaul. Closes 22 of the 30 issues filed under `audit-2026-05-03` on the same day; the 8 deferred items are listed at the bottom.

### Added
- Per-domain rules table (dofollow, target, sponsored, allowlist) with subdomain inheritance.
- `rel="ugc"` automatically on external links inside comments (toggle: "Comment UGC", on by default for fresh installs).
- Per-link `rel="sponsored"` opt-in via `data-rel-sponsored="1"` attribute.
- Filter coverage for `the_excerpt`, `widget_text_content`, `widget_block_content`, and `render_block` (navigation, query loop, post content, social link, post title blocks).
- Render-time object cache keyed on post ID + `post_modified_gmt` + options hash + content hash.
- Filterable hook priority via `timu_elc_priority` (default 99).
- Filterable internal/external decision via `timu_elc_is_external`.
- Filterable block-type list via `timu_elc_block_types`.
- Filterable a11y notice text via `timu_elc_a11y_new_tab_text`.
- Visually-hidden "(opens in new tab)" notice on every external link forced to `_blank` by the plugin.
- WP-CLI: `wp elc audit` (host distribution survey) and `wp elc rewrite <id> --dry-run` (transformation preview).
- `.distignore` to keep the GitHub-updater files out of the wordpress.org zip.

### Changed
- Anchor walking switched to `WP_HTML_Tag_Processor` (WP 6.2+) with regex fallback for older cores.
- Anchors inside `<code>`, `<pre>`, `<script>` (including JSON-LD), and `<style>` are now left untouched.
- Internal-link detection uses proper host comparison via `wp_parse_url` and lowercased / `www.`-stripped normalisation. Subdomains of the site host are correctly internal.
- Protocol-relative URLs (`//host/...`) are no longer silently skipped.
- `noopener noreferrer` is forced on any external `_blank`, regardless of pre-existing markup. Decoupled from the nofollow toggle.
- `rel` token merging preserves existing tokens (`rel="me"`, `rel="author"`, plugin/editor-set values).
- Plugin instance is now a singleton.
- Inline `style=""` attributes replaced by an enqueued `assets/css/admin.css` (only loads on `plugins.php` and the plugin's settings screen).
- Donate link in the plugin row + byline + settings page now carry `rel="noopener noreferrer"`.
- Minimum WP raised to 6.2; tested up to 6.7.

### Removed
- Unused `assets/js/admin.js` and `js/tracking.js` (the link-test UI they presumed has not been built; the WP-CLI `audit` command covers the user-facing audit need until then).

### Fixed
- Plugin header `Version` (was `0.6112`) and `readme.txt` `Stable tag` (was `1.251231`) now agree on `0.6123`.
- `readme.txt` "File an issue" link no longer contains the literal `[plugin-name]` placeholder.
- `readme.txt` now has the previously-missing `== Screenshots ==` and `== Upgrade Notice ==` sections.

### Deferred to a later release
- `#26` exclude post types / post IDs / categories from filter — needs a settings UI design pass; backlog.
- `#28` REST endpoint `/timu-elc/v1/inventory` — out of scope for this audit cycle.
- `#29` broken-link cron with weekly HEAD checks — non-trivial; needs cron scheduling, status-code grading, dismissible admin notice. Backlog.
- The audit issues for the standalone WP_HTML_Tag_Processor exclusion-regions implementation (`#31`) and the duplicate ugc/sponsored issue (`#24`) ship together with this release.

### Notes
- Versioning continues on the `x.Yddd` Julian-day scheme (today: `Y=6`, `ddd=123`).
