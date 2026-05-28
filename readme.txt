=== This Is My URL - External Link Control ===
Contributors: thisismyurl
Donate link: https://github.com/sponsors/thisismyurl
Tags: external links, nofollow, target blank, seo, link management
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6148
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Control external link behavior across your site by adding target and rel attributes at render time without modifying stored content.

== Description ==

External Link Control gives you a central setting screen for basic external link handling in WordPress.

The plugin currently supports:

* Enabling or disabling external link filtering globally
* Opening external links in a new tab with `target="_blank"`
* Adding `rel="nofollow noopener noreferrer"` to external links
* Leaving database content untouched by modifying links only during output

== How It Works ==

The plugin filters post content with `the_content` and updates matching external links during rendering.

* Internal links are left alone
* External `http` and `https` links can receive `target` and `rel` attributes
* Your saved post content is not rewritten in the database

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/thisismyurl-external-link-control` directory.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Tools > Link Control.
4. Enable the options you want and save your settings.

== Frequently Asked Questions ==

= Does this modify my database? =

No. The plugin changes matching links only while content is rendered.

= What links are affected? =

The current implementation targets external links in post content that begin with `http` or `https` and are outside your site URL.

= Can I disable the behavior at any time? =

Yes. Disable the master switch or deactivate the plugin and the rendered output returns to the original link markup.

== Support, Contributing & Sponsorship ==

= I want to support you =

I'm building these tools because WordPress developers and site owners deserve straightforward, practical solutions. There's no tracking, no ads, and you don't need to pay to use these plugins.

If they're helpful, here are genuine ways to support the work:

* **Sponsor this project:** Visit https://github.com/sponsors/thisismyurl if sponsorship fits your budget. Sponsorship helps, but it's always optional.
* **Contribute code or ideas:** Opening a pull request, reporting an issue, or testing edge cases is just as valuable as sponsorship. Helping me improve these plugins is a great way to contribute.
* **Share your experience:** A review on my [Google My Business profile](https://business.google.com/refer) or a follow on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps others find this work.

= I found a bug or have a feature idea =

* **File an issue on GitHub:** Visit https://github.com/thisismyurl/thisismyurl-external-link-control/issues and include your WordPress and PHP version.
* **Start a discussion:** Use the Discussions tab on GitHub for questions or ideas.

= I want to contribute code =

Code contributions are welcome and genuinely valuable:

1. Fork the repository on GitHub.
2. Create a feature branch (e.g., `feature/improve-safety`).
3. Make your changes and test thoroughly.
4. Follow WordPress coding standards.
5. Open a pull request with a clear description of what changed and why.

I review PRs thoughtfully and appreciate well-tested contributions. Contributing is never required, but it's genuinely helpful.


== Screenshots ==

1. The Tools > Link Control settings screen, with the master switch, force new tab, nofollow, and comment UGC toggles.
2. The per-domain rules table, showing dofollow allowlist, target override, and rel="sponsored" controls for individual domains.

== Changelog ==

= 1.6149 =
* New: WordPress 7.0 Abilities API support. The plugin registers a read-only ability, `thisismyurl-external-link-control/scan-external-links`, that returns the most recent broken-link scan — every broken or unverifiable link, its HTTP status, and the posts it appears on — for AI agents and REST clients. It reads stored results only and never starts a new scan, so it is instant and makes no outbound requests. Filter by post ID or status. Requires the `manage_options` capability.

= 1.6148 =
* Fix: the broken-link admin notice now stays dismissed. Earlier versions shipped no JavaScript, so WordPress's dismiss "X" only hid the notice for that page load and never told the server — it reappeared on the next screen. Dismissing now records which links were dismissed; the notice only returns when a later scan finds a link that was not in the dismissed set.
* Fix: far fewer false positives. A 401, 403, 405, 429, 451, or 999 response (login walls, bot protection, rate limits, servers that reject HEAD) is no longer reported as broken — these mean "the link exists, I just won't let an automated checker confirm it." Only 404, 410, and dead domains (a host that no longer resolves) count as broken. HEAD requests that fail now retry once with GET before any verdict, so HEAD-hostile servers stop showing up as broken.
* New: an ignore list. Use the "Ignore" button next to any link in the Broken Links dashboard widget to stop hearing about a URL or a whole host; manage it from the same widget ("Stop ignoring"). Ignored links are skipped during the scan, so they cost no request.
* New: a "Scan now" button in the dashboard widget — run the check on demand instead of waiting for the weekly cron.
* New: a "Could not verify" section in the widget lists links that answered but blocked the check, for transparency, without raising the notice.
* New: per-link "Recheck" button to re-test a single URL immediately.
* New: `timu_elc_broken_status_codes` filter to tune which HTTP status codes count as broken (defaults to 404 and 410).

= 1.6147 =
* Unified plugin versioning to the x.Yddd calendar-version scheme.
* Confirmed compatibility with WordPress 7.0.


= 1.6143 =
* First full release (class 1). The 0.6xxx line was pre-release on the `x.Yddd` scheme.
* Standardized the donation link to GitHub Sponsors.

= 0.6123 =
* New: per-domain rules table — override nofollow, target, and sponsored on a per-domain basis. Dedicated "allowlist" column for rel=me / sameAs profile domains.
* New: rel="ugc" automatically applied to external links inside comments (controlled by a new "Comment UGC" setting, on by default for fresh installs).
* New: per-link rel="sponsored" opt-in via a `data-rel-sponsored="1"` attribute on the editor side.
* New: filter coverage now includes `the_excerpt`, `widget_text_content`, `widget_block_content`, and the FSE `render_block` hook for navigation, query loop, post content, social link, and post title blocks.
* New: render-time object cache keyed on post ID + post_modified_gmt + options hash + content hash, so the link rewrite no longer recomputes on every render.
* New: WP-CLI commands `wp elc audit` (list external domains and counts) and `wp elc rewrite --dry-run` (preview a rewrite without touching the DB).
* New: filterable hook priority via `timu_elc_priority` (defaults to 99) and filterable block-type list via `timu_elc_block_types`.
* New: filterable internal/external decision via `timu_elc_is_external` so multisite siblings, staging mirrors, and first-party shorteners can be carved out cleanly.
* New: visually-hidden "(opens in new tab)" text appended whenever the plugin forces target="_blank", so screen reader users get the warning.
* Fix: switched from substring `strpos($url, $site_url)` to a proper host comparison via `wp_parse_url()`. Subdomains of the site host are correctly treated as internal; protocol-relative URLs (`//host/...`) are no longer silently skipped.
* Fix: `noopener noreferrer` is now always forced on any external link with target="_blank", regardless of whether the editor or the plugin set the target. Decoupled from the nofollow toggle.
* Fix: rel attribute merging preserves existing tokens (rel="me", rel="author", custom values) instead of overwriting them.
* Fix: anchor walking switched from a regex to `WP_HTML_Tag_Processor` (with regex fallback for WP < 6.2). Anchors inside `<code>`, `<pre>`, `<script>` (including JSON-LD), and `<style>` blocks are no longer mutated.
* Fix: header `Version` and readme `Stable tag` aligned on the `x.Yddd` Julian-day scheme. Plugin-row Donate link, settings-screen byline link, and Donate button all carry `rel="noopener noreferrer"`.
* Chore: minimum WP raised to 6.2; tested up to 6.7. Inline `style=""` attributes replaced with an enqueued admin stylesheet. Singleton instance guard. Unused `assets/js/admin.js` and `js/tracking.js` removed.

= 1.251231 =
* Pre-audit baseline with basic external link controls (master switch, force new tab, nofollow).
* Modifies links at render time without rewriting database content.

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.6148 =
Broken-link checker overhaul: notice dismissals now stick, an ignore list lets you mute links you do not care about, a "Scan now" button runs the check on demand, and false positives (403/405/429 and HEAD-hostile servers like LinkedIn) are no longer reported as broken. Only genuine 404/410/dead-domain links raise the notice.

= 0.6123 =
Major audit-driven overhaul. Adds per-domain rules, rel="ugc" on comments, rel="sponsored" support, FSE block coverage, render-time caching, accessibility text on forced new-tab links, and WP-CLI commands. Fixes a subdomain-misclassification bug in the internal-link detection, ensures `noopener noreferrer` always lands on `target="_blank"`, and stops mutating links inside `<code>` / `<pre>` / JSON-LD blocks. Minimum WP raised to 6.2.

== License ==

This plugin is licensed under the GPLv2 or later license.
