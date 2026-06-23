# Changelog

All notable changes to **External Link Control by thisismyurl.com** are recorded here. The plugin uses a `x.Yddd` Julian-day version scheme: `x` is the release class (`0` = pre-release, `1` = full), `Y` is the last digit of the year, and `ddd` is the day of year (001-366).

## 0.6174.1642 — 2026-06-23

### Added
- Same-tab domain exceptions: a new "Same-Tab Domains" textarea in Tools > Link Control (one domain per line, e.g. `docs.example.com`). Domains listed here stay in the same tab even when the global Force New Tab setting is on. Rel attributes (nofollow, noopener, noreferrer) still apply to those domains — only `target="_blank"` is exempted. Stored as `target_same_tab_domains` in `timu_elc_options`; each entry is sanitized with `sanitize_text_field()` and lowercased.

---

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
