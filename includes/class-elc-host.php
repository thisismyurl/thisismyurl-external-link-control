<?php
/**
 * Host-comparison helpers for External Link Control.
 *
 * Centralises the "is this URL internal to this site?" decision so the
 * link-processing code never falls back on the substring match that
 * shipped in 0.6112 (which mis-classified protocol-relative URLs and
 * subdomain edge cases).
 *
 * @package TIMU_ELC
 * @since 0.6123
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TIMU_ELC_Host' ) ) {

	/**
	 * Pure-function host helpers. All methods are static; no state.
	 */
	class TIMU_ELC_Host {

		/**
		 * Cached site host (lowercased, leading "www." stripped).
		 *
		 * @var string|null
		 */
		private static $site_host = null;

		/**
		 * Return the canonical site host, computed once per request.
		 *
		 * @return string
		 */
		public static function site_host() {
			if ( null !== self::$site_host ) {
				return self::$site_host;
			}

			$site_url = (string) get_site_url();
			$host     = wp_parse_url( $site_url, PHP_URL_HOST );
			self::$site_host = self::normalise_host( $host );

			return self::$site_host;
		}

		/**
		 * Normalise a hostname for comparison: lowercase, strip a single
		 * leading "www." label.
		 *
		 * @param string|null $host Raw host from wp_parse_url().
		 * @return string Normalised host, or empty string when input is unusable.
		 */
		public static function normalise_host( $host ) {
			if ( ! is_string( $host ) || '' === $host ) {
				return '';
			}

			$host = strtolower( $host );

			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			return $host;
		}

		/**
		 * Extract the host from any URL value seen in an href attribute.
		 *
		 * Handles:
		 *   - absolute http(s) URLs
		 *   - protocol-relative URLs (//host/path)
		 *   - leading whitespace
		 *
		 * Returns an empty string for relative paths, anchors, mailto:,
		 * tel:, javascript:, data:, etc. — those are never "external"
		 * for our purposes.
		 *
		 * @param string $url Raw href value.
		 * @return string Normalised host, or empty string.
		 */
		public static function host_for_href( $url ) {
			if ( ! is_string( $url ) ) {
				return '';
			}

			$url = trim( $url );

			if ( '' === $url ) {
				return '';
			}

			// Protocol-relative: //example.com/path. wp_parse_url handles it.
			if ( 0 === strpos( $url, '//' ) ) {
				$host = wp_parse_url( 'https:' . $url, PHP_URL_HOST );
				return self::normalise_host( $host );
			}

			// Absolute http(s).
			if ( preg_match( '#^https?://#i', $url ) ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				return self::normalise_host( $host );
			}

			// Anything else (relative, anchor, mailto:, tel:, etc.) is not external.
			return '';
		}

		/**
		 * Decide whether the given href points at an external host.
		 *
		 * Rules:
		 *   - hrefs without a host are internal (relative paths, anchors).
		 *   - hosts equal to the site host are internal.
		 *   - subdomains of the site host are internal (cdn.example.com is
		 *     internal when the site lives on example.com or www.example.com).
		 *
		 * Filterable via `timu_elc_is_external` so site owners can carve out
		 * staging mirrors, multisite siblings, or first-party shorteners.
		 *
		 * @param string $url Raw href value.
		 * @return bool
		 */
		public static function is_external( $url ) {
			$href_host = self::host_for_href( $url );

			if ( '' === $href_host ) {
				$external = false;
			} else {
				$site_host = self::site_host();
				$external  = ! self::hosts_match( $href_host, $site_host );
			}

			/**
			 * Filter the "is this URL external?" decision.
			 *
			 * @param bool   $external Default decision.
			 * @param string $url      Original href value.
			 * @param string $href_host Normalised host of the href (empty for non-URL hrefs).
			 */
			return (bool) apply_filters( 'timu_elc_is_external', $external, $url, $href_host );
		}

		/**
		 * Hosts match if equal, or if one is a sub-domain of the other.
		 *
		 * @param string $href_host Normalised host from href.
		 * @param string $site_host Normalised site host.
		 * @return bool
		 */
		private static function hosts_match( $href_host, $site_host ) {
			if ( '' === $site_host ) {
				return false;
			}

			if ( $href_host === $site_host ) {
				return true;
			}

			$suffix = '.' . $site_host;
			$len    = strlen( $suffix );

			return strlen( $href_host ) > $len && substr( $href_host, -$len ) === $suffix;
		}
	}
}
