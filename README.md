# External Link Control

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/) [![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

Controls how external links behave: it adds `rel="noopener noreferrer"`, optional `target="_blank"`, or custom rel attributes based on rules you set.

## What it does

- Adds `rel="noopener noreferrer"` to external links on output
- Opens external links in a new tab, if you want that
- Lets you set custom rel attributes on external links
- Excludes specific domains with an allowlist
- Runs on post and page content, widgets, and any template tag that uses `the_content`

The attributes are applied at render time, not written to the database. Your saved content stays exactly as you wrote it, so turning the plugin off leaves no leftover markup behind.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Upload to `/wp-content/plugins/thisismyurl-external-link-control/`.
2. Activate through the Plugins screen.
3. Configure via **Settings > External Link Control**.

## Versioning

Versions follow `X.Yjjj.hhmm` — year, Julian day, 24-hour time of the build.

## About

External Link Control is built and maintained by [Christopher Ross](https://thisismyurl.com/). I build focused WordPress tools for problems that keep showing up across real sites. No tracking, no ads, no upsells.

**WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/) · **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl) · **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
