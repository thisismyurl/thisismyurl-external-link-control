<?php
/**
 * Per-domain ruleset for External Link Control.
 *
 * Stores a list of domain-specific overrides in `wp_options` under
 * `timu_elc_domain_rules` (autoload=no). A rule is `[ domain, dofollow,
 * target, sponsored, allowlist ]` where:
 *   - `domain`     — host string, normalised the same way as TIMU_ELC_Host
 *   - `dofollow`   — bool. If true, suppress nofollow on this domain even
 *                    when the global nofollow toggle is on. Use for
 *                    rel="me" / sameAs domains and intentional dofollows.
 *   - `target`     — '_blank' | '_self' | '' (empty means inherit global)
 *   - `sponsored`  — bool. Force rel="sponsored" on every link to this
 *                    domain (paid placements, affiliate networks).
 *   - `allowlist`  — bool. Convenience flag: when true, dofollow is
 *                    implied. Surfaces in the UI as the "rel=me / sameAs"
 *                    list a site owner cares about most.
 *
 * Subdomains inherit from the parent rule unless a more-specific rule
 * exists. linkedin.com applies to www.linkedin.com automatically; an
 * explicit jobs.linkedin.com rule wins for that subdomain.
 *
 * @package TIMU_ELC
 * @since 0.6123
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TIMU_ELC_Domain_Rules' ) ) {

	/**
	 * Domain rule store + matcher.
	 */
	class TIMU_ELC_Domain_Rules {

		const OPTION_KEY = 'timu_elc_domain_rules';

		/**
		 * In-memory cache of normalised rules, indexed by domain.
		 *
		 * @var array<string,array<string,mixed>>|null
		 */
		private static $rules_index = null;

		/**
		 * Return all rules as a list, in storage order.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function all() {
			$raw = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $raw ) ) {
				return array();
			}
			$out = array();
			foreach ( $raw as $rule ) {
				$normalised = self::normalise_rule( $rule );
				if ( null !== $normalised ) {
					$out[] = $normalised;
				}
			}
			return $out;
		}

		/**
		 * Replace the entire rule set. Used by the settings save handler
		 * and WP-CLI. Bypasses the normaliser caller-side; we always
		 * re-normalise here to keep storage tidy.
		 *
		 * @param array<int,array<string,mixed>> $rules Candidate rules.
		 * @return bool Whether the option was updated.
		 */
		public static function save( array $rules ) {
			$out = array();
			foreach ( $rules as $rule ) {
				$normalised = self::normalise_rule( $rule );
				if ( null !== $normalised ) {
					$out[] = $normalised;
				}
			}
			self::$rules_index = null;
			return (bool) update_option( self::OPTION_KEY, $out, false );
		}

		/**
		 * Find the rule that applies to a given external host.
		 *
		 * Precedence: exact host match > deepest matching parent host.
		 *
		 * @param string $host Already-normalised host.
		 * @return array<string,mixed>|null Rule or null when no match.
		 */
		public static function match( $host ) {
			if ( ! is_string( $host ) || '' === $host ) {
				return null;
			}

			$index = self::index();
			if ( empty( $index ) ) {
				return null;
			}

			if ( isset( $index[ $host ] ) ) {
				return $index[ $host ];
			}

			// Try shorter and shorter parent hosts.
			$parts = explode( '.', $host );
			while ( count( $parts ) > 2 ) {
				array_shift( $parts );
				$candidate = implode( '.', $parts );
				if ( isset( $index[ $candidate ] ) ) {
					return $index[ $candidate ];
				}
			}

			// Final attempt: bare two-label parent (e.g. linkedin.com).
			if ( count( $parts ) === 2 ) {
				$candidate = implode( '.', $parts );
				if ( isset( $index[ $candidate ] ) ) {
					return $index[ $candidate ];
				}
			}

			return null;
		}

		/**
		 * Build / return the host -> rule index for fast match() lookup.
		 *
		 * @return array<string,array<string,mixed>>
		 */
		private static function index() {
			if ( null !== self::$rules_index ) {
				return self::$rules_index;
			}
			$idx = array();
			foreach ( self::all() as $rule ) {
				$idx[ $rule['domain'] ] = $rule;
			}
			self::$rules_index = $idx;
			return self::$rules_index;
		}

		/**
		 * Reset the in-memory index. Used by tests and WP-CLI between calls.
		 *
		 * @return void
		 */
		public static function reset_cache() {
			self::$rules_index = null;
		}

		/**
		 * Coerce a candidate rule into the canonical shape, or return null
		 * if it's unusable (no domain, malformed values).
		 *
		 * @param mixed $rule Candidate.
		 * @return array<string,mixed>|null
		 */
		public static function normalise_rule( $rule ) {
			if ( ! is_array( $rule ) ) {
				return null;
			}

			$domain = isset( $rule['domain'] ) ? strtolower( trim( (string) $rule['domain'] ) ) : '';
			if ( '' === $domain ) {
				return null;
			}

			// Strip scheme + path if the user pasted a full URL.
			if ( false !== strpos( $domain, '://' ) ) {
				$parsed = wp_parse_url( $domain, PHP_URL_HOST );
				$domain = is_string( $parsed ) ? $parsed : '';
			}
			$domain = TIMU_ELC_Host::normalise_host( $domain );
			if ( '' === $domain ) {
				return null;
			}

			$target = isset( $rule['target'] ) ? (string) $rule['target'] : '';
			if ( ! in_array( $target, array( '', '_blank', '_self' ), true ) ) {
				$target = '';
			}

			return array(
				'domain'    => $domain,
				'dofollow'  => ! empty( $rule['dofollow'] ) || ! empty( $rule['allowlist'] ),
				'target'    => $target,
				'sponsored' => ! empty( $rule['sponsored'] ),
				'allowlist' => ! empty( $rule['allowlist'] ),
			);
		}
	}
}
