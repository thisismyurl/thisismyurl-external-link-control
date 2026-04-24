=== External Link Control ===
Contributors: thisismyurl
Tags: external links, nofollow, target blank, seo, link management
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.251231
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

== Changelog ==

= 1.251231 =
* Current release with basic external link controls
* Adds support for opening external links in a new tab
* Adds support for applying `nofollow noopener noreferrer`
* Keeps modifications at render time rather than rewriting content

= 1.0.0 =
* Initial release

== License ==

This plugin is licensed under the GPLv2 or later license.
