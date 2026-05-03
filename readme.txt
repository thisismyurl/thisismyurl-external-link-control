=== External Link Control ===
Contributors: thisismyurl
Tags: external links, nofollow, target blank, seo, link management
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.6123
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

* **File an issue on GitHub:** Visit https://github.com/thisismyurl/[plugin-name]/issues and include your WordPress and PHP version.
* **Start a discussion:** Use the Discussions tab on GitHub for questions or ideas.

= I want to contribute code =

Code contributions are welcome and genuinely valuable:

1. Fork the repository on GitHub.
2. Create a feature branch (e.g., `feature/improve-safety`).
3. Make your changes and test thoroughly.
4. Follow WordPress coding standards.
5. Open a pull request with a clear description of what changed and why.

I review PRs thoughtfully and appreciate well-tested contributions. Contributing is never required, but it's genuinely helpful.


== Changelog ==

= 0.6123 =
* Align plugin header version and readme Stable tag on the `x.Yddd` Julian-day scheme.
* No functional changes; release-engineering hygiene for the audit cycle on 2026-05-03.

= 1.251231 =
* Pre-audit baseline with basic external link controls (master switch, force new tab, nofollow).
* Modifies links at render time without rewriting database content.

= 1.0.0 =
* Initial release

== License ==

This plugin is licensed under the GPLv2 or later license.
