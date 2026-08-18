=== External Link Control ===
Contributors: thisismyurl
Donate link: https://github.com/sponsors/thisismyurl
Tags: external links, nofollow, target blank, seo, link management
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6190.1000
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage external link behavior across your WordPress site — nofollow, new tab, referrer control, per-domain rules, broken-link checking, and a full link inventory. No database rewrites.

== Description ==

External Link Control gives you one settings screen to manage how every external link on your site behaves. Changes happen at render time — your database is never rewritten.

= What it does =

**Global controls:**

* Enable or disable link filtering with a single master switch
* Open external links in a new tab (`target="_blank"`)
* Strip the referrer header on outbound clicks (`rel="noreferrer"`) — or turn it off to keep referral tracking working for affiliates and partners
* Add `rel="nofollow"` to protect link equity
* Add `rel="ugc"` to comment links, following Google's recommendation for user-generated content

**Per-domain overrides:**

* Mark individual domains as dofollow — useful for `rel="me"` profiles and intentional editorial endorsements
* Force `rel="sponsored"` on paid placements and affiliate networks
* Set a per-domain target (`_blank` or `_self`) that overrides the global setting
* Allowlist domains whose `rel` attribute you want left completely untouched

**Broken-link checker:**

* Weekly automated scan (WP-Cron) checks up to 200 external URLs per run
* Dashboard widget shows broken links with one-click Ignore and Recheck actions
* Broken-link count is surfaced on the Link Control settings page so you see it without hunting for the widget
* Admin notice fires only for genuinely broken links — 404 / 410 / dead hosts — not for bot-walled servers (LinkedIn, Time.com) that reject HEAD requests

**Link inventory:**

* Load a full list of every external domain found in your published content, sorted by link count
* Shows whether each domain already has a per-domain rule configured — useful for finding domains that need attention
* Powered by the existing REST endpoint (`GET /wp-json/timu-elc/v1/inventory`) with a 1-hour transient cache

**WP-CLI:**

* `wp elc audit` — list all external domains with link counts, formatted as a table, CSV, or JSON
* `wp elc rewrite --post_id=123 --dry-run` — preview exactly what the processor would change on a specific post

= On activation =

The master switch ships **OFF**. Activating the plugin does not silently rewrite external links on your site. Enable it from Tools > Link Control when you are ready.

= Outbound network activity (please read) =

This plugin makes outbound network requests on your site's behalf. Two features reach the public internet:

* **Weekly broken-link crawler.** Schedules `timu_elc_broken_link_check` on activation. Scans published posts for external URLs and issues HEAD (with GET fallback) requests to find broken links. Results go to the dashboard widget. Scan on demand with "Scan now." Deactivating the plugin unschedules the job.
* **REST endpoint `GET /wp-json/timu-elc/v1/inventory`.** Capability-gated (`manage_options`). Reads your post content only — no outbound requests.

= How it works =

The plugin filters `the_content`, `the_excerpt`, `widget_text_content`, `comment_text`, and `render_block` hooks. Links are rewritten at render time using `WP_HTML_Tag_Processor` (WP 6.2+) with a regex fallback for older cores.

Existing `rel` tokens on a link (for example, `rel="me"` or `rel="author"`) are always preserved. The plugin merges its tokens in rather than replacing what was there.

An object-cache layer (keyed on post ID + modification time + options hash) means the regex pass runs once per post per settings change, not on every page load.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate through the **Plugins** screen.
3. Go to **Tools > Link Control**.
4. Enable the master switch and configure the options you want.
5. Save.

== Frequently Asked Questions ==

= Does this modify my database? =

No. Every change is applied during rendering. Your saved post content is not touched.

= What links are affected? =

External links in post content, excerpts, widgets, FSE blocks (including navigation, query loop, social-link, and post-title blocks), and comment text. Internal links are always left alone.

= What is the difference between nofollow and noreferrer? =

**nofollow** is an SEO instruction that tells search engines not to pass PageRank through the link. **noreferrer** strips the HTTP Referer header so the destination site cannot see which page the visitor came from. The two are independent. You might turn noreferrer off if you rely on referral tracking (affiliate dashboards, partner analytics, editorial credit programs) while still using nofollow for SEO purposes.

= Why is there a separate "Strip Referrer" option? =

By default, when a link opens in a new tab, the plugin adds both `noopener` (always required for security) and `noreferrer`. Some site owners need referral tracking to work — for example, to see their traffic credited in a partner's analytics dashboard, or to track affiliate conversions. Unchecking "Strip Referrer" removes `noreferrer` while keeping `noopener`, so the security protection stays in place.

= What does the Allowlist column do in the per-domain table? =

Allowlist is a shortcut for marking a domain as "leave everything alone." It implies dofollow and prevents any rel tokens the plugin would normally add. Use it for identity-verification links (GitHub, Mastodon, LinkedIn) that should carry `rel="me"` exactly as you wrote them in the editor.

= What is the difference between Dofollow and Allowlist? =

**Dofollow** suppresses the global `nofollow` token but still lets the plugin add `noopener` and `noreferrer` on new-tab links. **Allowlist** skips the plugin entirely for that domain — no rel attributes are added or removed. Use Dofollow for editorial endorsements where you still want the security tokens. Use Allowlist for identity links where you need the `rel` attribute left exactly as written.

= Does Sponsored imply Nofollow? =

Yes. Google treats `rel="sponsored"` as equivalent to `nofollow` for ranking purposes. You do not need to check both. Checking both is redundant but harmless.

= Can I use Sponsored and Allowlist on the same domain? =

No. Allowlist suppresses all rel processing for that domain, so the Sponsored flag would have no effect. Choose one or the other.

= Can I exclude individual posts from link processing? =

Yes, via the `timu_elc_should_filter` filter:

`add_filter( 'timu_elc_should_filter', function( $should, $post ) { return $post && 42 !== (int) $post->ID; }, 10, 2 );`

= Can I exclude specific block types from FSE processing? =

Yes, via the `timu_elc_block_types` filter. The filter receives the default list of block types the plugin processes and you can add or remove from it.

= Can I disable the broken-link checker without deactivating the plugin? =

The checker runs on a WP-Cron event (`timu_elc_broken_link_check`). You can remove it with `wp cron event delete timu_elc_broken_link_check` via WP-CLI, or unschedule it in code. Deactivating the plugin also cleans it up automatically.

= What does the "unverified" bucket mean in the dashboard widget? =

The checker classifies links as **broken** (genuine 404/410 or dead host), **unverified** (the server answered but blocked the check — bot wall, rate limit, login required), or **ok** (not stored). LinkedIn and similar sites that reject HEAD requests with 405 end up in the unverified bucket. The admin notice only fires for broken links, not unverified ones.

= Does the link inventory panel make outbound requests? =

No. The inventory reads your stored post content — no outbound HTTP requests. Results are cached in a transient for one hour.

= Will this work with page builders and classic themes? =

Yes. The plugin hooks into `the_content`, `the_excerpt`, and `widget_text_content` filters, which fire regardless of the active theme. FSE-specific block filtering is additive.

== Support, Contributing & Sponsorship ==

= I want to support you =

I build these tools because WordPress sites keep hitting the same problems, and a small, focused plugin is usually the right fix. They are free to use, with no tracking and no ads.

If one saves you time, the genuine ways to help:

* **Sponsor the work:** [GitHub Sponsors](https://github.com/sponsors/thisismyurl). Any amount helps.
* **Contribute code or ideas:** A pull request, a bug report, or a tested edge case is worth as much as a donation.
* **Share it:** A review on [WordPress.org](https://profiles.wordpress.org/thisismyurl/) or a note on LinkedIn goes further than you would expect.

== Screenshots ==

1. The Link Control settings page — global toggles, the per-domain rules table with Remove buttons, and the Add Row control.
2. The Link Inventory panel showing external domains found in published content, with rule status.
3. The broken-link status indicator in the settings sidebar.
4. The dashboard widget showing broken and unverified link buckets with Ignore and Recheck actions.

== Changelog ==

= 1.6190.1000 — 2026-07-09 =
* **New:** Strip Referrer toggle — control `rel="noreferrer"` independently of nofollow. Lets you keep referral analytics working while `noopener` (the security token) is always applied. Default is on for backward compatibility with existing installs.
* **New:** Link Inventory panel on the settings page. Loads every external domain found in your published content with link counts and a "has rule / no rule" indicator. Powered by the existing REST endpoint with its 1-hour transient cache.
* **New:** Broken-link count and last-scan time displayed in the settings sidebar — no more hunting for the dashboard widget to know your link health.
* **New:** Per-domain rules table gets a Remove button on every row and an Add Row button below the table. The old "leave the domain blank to delete it" pattern remains as the underlying save mechanism.
* **Improved:** Guidance note below the per-domain table clarifies that Sponsored implies nofollow and is incompatible with Allowlist.
* **Improved:** Documentation sidebar now lists WP-CLI commands.
* **Improved:** Label "Force New Tab" renamed to "Open in New Tab" for clarity.
* **Improved:** Label "Same-Tab Domains" renamed to "Same-Tab Exceptions" for clarity.
* **Improved:** Submit button label changed from "Update Link Settings" to "Save Link Settings."
* **Improved:** Admin JS and CSS now loaded only on the Link Control screen (previously CSS also loaded on plugins.php — still does for plugin-row styling, JS is new).

= 1.6158.1440 =
* Added WP 7 Abilities API registration (`thisismyurl-external-link-control/scan-external-links`) exposing broken-link scan results to REST/AI consumers.
* Added `timu_elc_should_filter` filter to allow per-post-type and per-post-ID exclusions.
* Added object-cache layer for the link processor (keyed on post ID + modification time + options hash).
* Added `timu_elc_a11y_new_tab_text` filter to customise or suppress the screen-reader "opens in new tab" notice.
* FSE `render_block` filter now covers `core/site-logo` and `core/post-title` link variants.
* Dashboard widget broken-link list now groups by verdict with separate "unverified" section.
* Admin stylesheet moved from inline styles to `assets/css/admin.css`.

= 1.6123.1330 =
* Initial public release. Global enable/disable switch, new-tab and nofollow toggles, comment UGC flag, same-tab exception list, per-domain rules table (dofollow / sponsored / allowlist / target), weekly broken-link checker with dashboard widget and admin notice, REST inventory endpoint, WP-CLI audit and rewrite commands, FSE block support.

== Upgrade Notice ==

= 1.6190.1000 =
Adds a separate Strip Referrer toggle. Existing installs keep the previous behavior (noreferrer on) — you will only notice a change if you uncheck the new option.
