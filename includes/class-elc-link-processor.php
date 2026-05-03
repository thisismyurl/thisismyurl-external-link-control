<?php
/**
 * Link-processing pipeline for External Link Control.
 *
 * Owns the actual transformation (regex match -> rel/target rewrite)
 * and a thin object-cache wrapper so the regex does not run on every
 * render of the same post.
 *
 * @package TIMU_ELC
 * @since 0.6123
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TIMU_ELC_Link_Processor' ) ) {

	/**
	 * Stateless processor with a memoised options snapshot.
	 */
	class TIMU_ELC_Link_Processor {

		const CACHE_GROUP   = 'timu_elc';
		const CACHE_VERSION = 1;

		/**
		 * Options snapshot for the current request.
		 *
		 * @var array<string,int>|null
		 */
		private $options;

		/**
		 * Constructor.
		 *
		 * @param array<string,int>|null $options Options array; if null, pulled from wp_options.
		 */
		public function __construct( $options = null ) {
			$this->options = is_array( $options ) ? $options : (array) get_option( 'timu_elc_options', array() );
		}

		/**
		 * Are we enabled at all?
		 *
		 * @return bool
		 */
		public function enabled() {
			return ! empty( $this->options['enabled'] );
		}

		/**
		 * Process a chunk of HTML, applying external-link rules.
		 *
		 * Cheap path: when caching is viable (we have a post in scope and
		 * an object cache available), check the cache before paying for
		 * regex traversal. Cache key is composed of:
		 *
		 *   - the cache namespace version (bump to invalidate everywhere)
		 *   - the post ID + post_modified_gmt (invalidates on save)
		 *   - the options hash (invalidates when settings change)
		 *   - a short hash of the content itself (invalidates if a filter
		 *     before priority 99 mutates the content for one render)
		 *
		 * @param string       $content Raw HTML.
		 * @param WP_Post|null $post    Post in scope, if known.
		 * @return string
		 */
		public function process( $content, $post = null ) {
			if ( ! is_string( $content ) || '' === $content ) {
				return $content;
			}

			if ( ! $this->enabled() ) {
				return $content;
			}

			$cache_key = $this->cache_key( $content, $post );

			if ( null !== $cache_key ) {
				$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
				if ( false !== $cached && is_string( $cached ) ) {
					return $cached;
				}
			}

			$processed = $this->transform( $content );

			if ( null !== $cache_key ) {
				wp_cache_set( $cache_key, $processed, self::CACHE_GROUP, HOUR_IN_SECONDS );
			}

			return $processed;
		}

		/**
		 * Build the cache key for a given content + post.
		 *
		 * Returns null when caching is not safe (no post context — we
		 * have no good invalidation signal, and a generic key risks
		 * cross-page bleed).
		 *
		 * @param string       $content Raw HTML.
		 * @param WP_Post|null $post    Post in scope.
		 * @return string|null
		 */
		private function cache_key( $content, $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				return null;
			}

			$options_hash = md5( wp_json_encode( $this->options ) );
			$content_hash = md5( $content );

			return sprintf(
				'v%d:%d:%s:%s:%s',
				self::CACHE_VERSION,
				(int) $post->ID,
				(string) $post->post_modified_gmt,
				$options_hash,
				$content_hash
			);
		}

		/**
		 * Run the actual rewrite. No caching.
		 *
		 * @param string $content Raw HTML.
		 * @return string
		 */
		public function transform( $content ) {
			$options = $this->options;

			return preg_replace_callback(
				'/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>/i',
				static function ( $matches ) use ( $options ) {
					$link_html = $matches[0];
					$url       = $matches[1];

					if ( ! TIMU_ELC_Host::is_external( $url ) ) {
						return $link_html;
					}

					if ( ! empty( $options['new_tab'] ) && false === strpos( $link_html, 'target=' ) ) {
						$link_html = str_replace( '<a ', '<a target="_blank" ', $link_html );
					}
					if ( ! empty( $options['nofollow'] ) && false === strpos( $link_html, 'rel=' ) ) {
						$link_html = str_replace( '<a ', '<a rel="nofollow noopener noreferrer" ', $link_html );
					}
					return $link_html;
				},
				$content
			);
		}
	}
}
