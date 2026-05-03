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
					return self::rewrite_anchor_tag( $matches[0], $matches[1], $options );
				},
				$content
			);
		}

		/**
		 * Rewrite a single `<a>` opening tag.
		 *
		 * Decisions are layered:
		 *   - skip internal links entirely
		 *   - if "force new tab" is on AND the link has no target, set _blank
		 *   - if the resulting tag has target=_blank from any source
		 *     (existing markup or our own injection), force `noopener noreferrer`
		 *     onto rel — this is decoupled from the nofollow setting so the
		 *     reverse-tabnabbing protection always lands on _blank links
		 *   - if "nofollow" is on, add nofollow to the rel token list
		 *
		 * Existing rel tokens are preserved; new tokens are merged in. We
		 * never replace a rel attribute the editor wrote (e.g. rel="me").
		 *
		 * @param string             $link_html Original `<a ...>` opening tag.
		 * @param string             $url       href value.
		 * @param array<string,int>  $options   Plugin options snapshot.
		 * @return string Possibly-rewritten opening tag.
		 */
		private static function rewrite_anchor_tag( $link_html, $url, $options ) {
			if ( ! TIMU_ELC_Host::is_external( $url ) ) {
				return $link_html;
			}

			$has_target = (bool) preg_match( '/\btarget\s*=/i', $link_html );

			if ( ! empty( $options['new_tab'] ) && ! $has_target ) {
				$link_html  = str_replace( '<a ', '<a target="_blank" ', $link_html );
				$has_target = true;
			}

			$tag_target_blank = (bool) preg_match( '/\btarget\s*=\s*["\']?_blank["\']?/i', $link_html );

			$rel_to_add = array();

			if ( $tag_target_blank ) {
				// Reverse-tabnabbing protection. Always applied on any _blank,
				// regardless of the nofollow setting.
				$rel_to_add[] = 'noopener';
				$rel_to_add[] = 'noreferrer';
			}

			if ( ! empty( $options['nofollow'] ) ) {
				$rel_to_add[] = 'nofollow';
			}

			if ( empty( $rel_to_add ) ) {
				return $link_html;
			}

			return self::merge_rel_tokens( $link_html, $rel_to_add );
		}

		/**
		 * Merge tokens into a tag's `rel=` attribute, preserving existing
		 * tokens (rel="me", rel="author", ACF-driven custom rel values, etc.).
		 *
		 * If no `rel=` exists, one is inserted with the requested tokens.
		 *
		 * @param string        $link_html Original `<a ...>` opening tag.
		 * @param array<string> $tokens    Tokens to ensure are present.
		 * @return string
		 */
		private static function merge_rel_tokens( $link_html, $tokens ) {
			$tokens = array_values( array_unique( array_filter( array_map( 'strtolower', $tokens ) ) ) );

			if ( empty( $tokens ) ) {
				return $link_html;
			}

			if ( preg_match( '/\brel\s*=\s*(["\'])(.*?)\1/i', $link_html, $m ) ) {
				$existing = preg_split( '/\s+/', strtolower( trim( $m[2] ) ), -1, PREG_SPLIT_NO_EMPTY );
				$existing = is_array( $existing ) ? $existing : array();
				$merged   = array_values( array_unique( array_merge( $existing, $tokens ) ) );
				$replace  = 'rel=' . $m[1] . implode( ' ', $merged ) . $m[1];

				return preg_replace( '/\brel\s*=\s*(["\'])(.*?)\1/i', $replace, $link_html, 1 );
			}

			$attr = ' rel="' . esc_attr( implode( ' ', $tokens ) ) . '"';

			return preg_replace( '/^<a\b/i', '<a' . $attr, $link_html, 1 );
		}
	}
}
