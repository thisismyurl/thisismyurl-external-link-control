# External Link Control by This Is My URL

[![CI](https://github.com/thisismyurl/thisismyurl-external-link-control/actions/workflows/ci.yml/badge.svg)](https://github.com/thisismyurl/thisismyurl-external-link-control/actions/workflows/ci.yml) [![WordPress Tested](https://img.shields.io/badge/WordPress-6.6%2B-blue)](https://wordpress.org/) [![License](https://img.shields.io/badge/License-GPL--2.0-blue)](LICENSE)


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

## Support and Contribute

### Ways to Support

I build these tools because WordPress sites in the wild keep hitting the same problems, and a focused plugin is usually the right fix. There's no tracking, no ads, and you don't need to pay to use these plugins.

If you find them helpful, here are some genuine ways to support the work:

- **Sponsor if it fits your budget:** You can sponsor the project through [GitHub Sponsors](https://github.com/sponsors/thisismyurl). Sponsorship helps, but it's always optional.
- **Contribute code or ideas:** Opening a pull request, reporting an issue, or testing edge cases is just as valuable as sponsorship. Helping me improve these plugins is a great way to contribute.
- **Share your experience:** A follow on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps others find this work.

### Report Issues and Questions

Found a bug? Want to suggest a feature? Just curious how something works?

- **File an issue:** Use the [Issues](../../issues) tab. Include your WordPress and PHP version, and steps to reproduce.
- **Start a discussion:** Use the [Discussions](../../discussions) tab for questions, ideas, or general conversation about the plugin.

### Contributing Code

Code contributions are welcome and genuinely valuable. Here's the workflow:

1. **Fork this repository** and clone it locally.
2. **Create a feature branch** with a clear name (e.g., `feature/improve-safety-check`).
3. **Make your changes** and test thoroughly on edge cases.
4. **Follow WordPress coding standards** — run `composer run lint:phpcs` before opening a PR.
5. **Open a pull request** with a clear description of what changed and why.

I review PRs thoughtfully and appreciate well-tested contributions. Contributing is never required, but it's genuinely helpful.

---


## About This Is My URL

This plugin supports the work I do at [This Is My URL](https://thisismyurl.com/wordpress-seo-services/), where I help WordPress teams build secure, performant, and maintainable sites.

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), a WordPress development and technical SEO practice. I'm Christopher Ross, a WordPress developer and technical SEO specialist working on the open web since 1996 and on WordPress since 2007.

### My Background

- **On the open web since 1996, on WordPress since 2007** — three decades of shipping production systems
- **WordPress contributor since 2007** — plugins published on .org, code shipped to media, education, and government deployments
- **Technical SEO practitioner** helping sites improve performance, security, and search visibility
- **Training specialist** at M.L. Campbell — building learning systems that ship, not slides that don't

I believe in straightforward solutions that work. No hype. No unnecessary complexity.

### Ways to Connect

- **WordPress.org profile:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **Website:** [thisismyurl.com](https://thisismyurl.com/)
- **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)


## Contributors

- **Christopher Ross** ([@thisismyurl](https://github.com/thisismyurl)) — author and maintainer
- **Contributors:** Thanks to everyone who's reported issues, tested edge cases, and contributed code

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).


---
*This project follows the [10 Core Pillars](PILLARS.md). Support quality work [here](https://github.com/sponsors/thisismyurl).*

