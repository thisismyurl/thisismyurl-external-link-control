# External Link Control by This Is My URL

Control outbound link behavior across your WordPress site — adding `nofollow`, `noopener noreferrer`, and `target="_blank"` at render time without touching stored content.

## Features

- Enable or disable external link filtering globally from one settings screen.
- Open external links in a new tab with `target="_blank"`.
- Add `rel="nofollow noopener noreferrer"` to external links automatically.
- Leaves your database content untouched — all changes happen during output rendering.
- Internal links are always left alone.

## How It Works

The plugin hooks into `the_content` filter and rewrites only external `http` and `https` links during rendering. No post content is stored differently; your editorial data stays clean.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Upload the plugin files to `/wp-content/plugins/thisismyurl-external-link-control/`.
2. Activate through the WordPress Plugins screen.
3. Go to **Tools > Link Control**.
4. Enable the options you want and save.

## Frequently Asked Questions

**Does this modify my post content in the database?**
No. All modifications happen at render time only. Your stored content is never rewritten.

**Does it affect internal links?**
No. Only external `http` and `https` links are affected.

**Is it compatible with page builders?**
Yes, as long as the page builder renders content through `the_content` filter.

## Versioning

This plugin uses the format `1.Yddd`:
- `Y` = last digit of the year
- `ddd` = Julian day number

## Standards

- Direct access protection with ABSPATH checks.
- Nonce and capability checks for admin actions.
- Escaping and sanitization aligned with WordPress coding standards.

---

## About This Is My URL

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), a WordPress development and technical SEO practice with more than 25 years of experience helping organizations build practical, maintainable web systems.

Christopher Ross ([@thisismyurl](https://profiles.wordpress.org/thisismyurl/)) is a WordCamp speaker, plugin developer, and WordPress practitioner based in Fort Erie, Ontario, Canada. Member of the WordPress community since 2007.

### More Resources

- **Plugin page:** [https://thisismyurl.com/external-link-control](https://thisismyurl.com/external-link-control)
- **WordPress.org profile:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **Other plugins:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **Website:** [thisismyurl.com](https://thisismyurl.com/)

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
