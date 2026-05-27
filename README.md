# This Is My URL - External Link Control

[![CI](https://github.com/thisismyurl/thisismyurl-external-link-control/actions/workflows/ci.yml/badge.svg)](https://github.com/thisismyurl/thisismyurl-external-link-control/actions/workflows/ci.yml) [![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-blue)](https://wordpress.org/) [![License](https://img.shields.io/badge/License-GPL--2.0-blue)](LICENSE)

Control outbound link behaviour across your WordPress site — adding `nofollow`, `noopener noreferrer`, and `target="_blank"` at render time without touching stored content.

## Features

- Enable or disable external link filtering globally from one settings screen.
- Open external links in a new tab with `target="_blank"`.
- Add `rel="nofollow noopener noreferrer"` to external links automatically.
- Leaves your database content untouched — all changes happen during output rendering.
- Internal links are always left alone.

## How it works

The plugin hooks into the `the_content` filter and rewrites only external `http` and `https` links during rendering. No post content is stored differently; your editorial data stays clean.

## Requirements

- WordPress 6.2+
- PHP 7.4+

## Installation

1. Upload the plugin files to `/wp-content/plugins/thisismyurl-external-link-control/`.
2. Activate through the WordPress Plugins screen.
3. Go to **Tools > Link Control**.
4. Enable the options you want and save.

## Frequently asked questions

**Does this modify my post content in the database?**
No. All modifications happen at render time only. Your stored content is never rewritten.

**Does it affect internal links?**
No. Only external `http` and `https` links are affected.

**Is it compatible with page builders?**
Yes, as long as the page builder renders content through the `the_content` filter.

## Versioning

This plugin uses the format `1.Yddd`:

- `Y` = last digit of the year
- `ddd` = Julian day number

## Standards

- Direct-access protection with `ABSPATH` checks.
- Nonce and capability checks for admin actions.
- Escaping and sanitisation aligned with WordPress coding standards.

## Changelog

See [releases](../../releases) or [readme.txt](readme.txt).

---

## Support and donations

I build these tools because WordPress sites in the wild keep hitting the same problems, and a small, focused plugin is usually the right fix. They're free to use, with no tracking and no ads.

If one of them saves you time, here are the genuine ways to help:

- **Sponsor the work.** [GitHub Sponsors](https://github.com/sponsors/thisismyurl) is the simplest way, and the Sponsor button at the top of this repo lists it alongside Bitcoin, Dogecoin, PayPal, and Interac e-transfer. Any amount helps, and none of it is expected.
- **Contribute code or ideas.** A pull request, a bug report, or a tested edge case is worth as much as a donation. See [CONTRIBUTING.md](CONTRIBUTING.md) to get started.
- **Share it.** A note on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps other people find work that might save them the same afternoon.

### Report issues and questions

- **Found a bug or want a feature?** Open an issue on the [Issues](../../issues) tab. Include your WordPress and PHP versions and the steps to reproduce it.
- **Have a question?** Start a thread on the [Discussions](../../discussions) tab.

### Contributing code

Code contributions are welcome. The short version:

1. Fork the repository and clone your fork.
2. Create a branch with a clear name, like `feature/short-descriptive-name`.
3. Make your change and test it against the edge cases.
4. Run the coding-standards check before you open the pull request.
5. Open a pull request that explains what changed and why.

The full workflow and standards live in [CONTRIBUTING.md](CONTRIBUTING.md). Contributing is never required, but it is always appreciated.

## About This Is My URL

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), the WordPress development and technical SEO practice of Christopher Ross. I help teams build WordPress sites that stay secure, fast, and maintainable, and I write small, focused plugins like this one for the problems those sites keep running into.

### My background

- On the web since 1996, and in WordPress since 2007
- WordPress.org plugin developer with 19 plugins published since 2009
- Technical SEO practitioner focused on performance, security, and search visibility
- Lead instructor and curriculum architect at the M.L. Campbell Training Center, the Sherwin-Williams® international training facility for its industrial wood division

### Ways to connect

- **Website:** [thisismyurl.com](https://thisismyurl.com/)
- **WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## Contributors

- **Christopher Ross** ([@thisismyurl](https://github.com/thisismyurl)) — author and maintainer
- Thanks to everyone who has reported issues, tested edge cases, and contributed code

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).

---
*This project follows the [10 Core Pillars](PILLARS.md). Support quality work [here](https://github.com/sponsors/thisismyurl).*
