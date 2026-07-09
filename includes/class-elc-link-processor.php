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
		 * Per-call context: extra rel tokens forced on every external link
		 * processed by this instance (e.g., 'ugc' when called from comment_text).
		 *
		 * @var array<string>
		 */
		private $forced_rel = array();

		/**
		 * Constructor.
		 *
		 * @param array<string,int>|null $options Options array; if null, pulled from wp_options.
		 */
		public function __construct( $options = null ) {
			$this->options = is_array( $options ) ? $options : (array) get_option( 'timu_elc_options', array() );
		}

		/**
		 * Force one or more rel tokens onto every external link this
		 * processor touches. Used by the comment_text path to add `ugc`.
		 *
		 * @param array<string> $tokens Rel tokens.
		 * @return self
		 */
		public function force_rel( array $tokens ) {
			$this->forced_rel = array_values(
				array_unique(
					array_merge(
						$this->forced_rel,
						array_map( 'strtolower', array_filter( $tokens, 'is_string' ) )
					)
				)
			);
			return $this;
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
			$options    = $this->options;
			$forced_rel = $this->forced_rel;

			// Pre-pass: extract regions whose link content must NOT be
			// modified — code samples, pre-formatted text, scripts (which
			// includes JSON-LD via type="application/ld+json"), and inline
			// styles. Replace each with a placeholder, transform the
			// remainder, then restore. This is more robust than trying to
			// teach a single regex to look-behind across nested tags.
			$skipped  = array();
			$pattern  = '#<(code|pre|script|style)\b[^>]*>.*?</\1>#is';
			$prepared = preg_replace_callback(
				$pattern,
				static function ( $m ) use ( &$skipped ) {
					$idx                = count( $skipped );
					$skipped[ '__TIMU_ELC_SKIP_' . $idx . '__' ] = $m[0];
					return '__TIMU_ELC_SKIP_' . $idx . '__';
				},
				$content
			);

			if ( null === $prepared ) {
				// preg_replace_callback returned null on malformed input;
				// fall back to direct regex transform on the original.
				$prepared = $content;
				$skipped  = array();
			}

			$transformed = self::transform_anchors( $prepared, $options, $forced_rel );
			$transformed = self::inject_a11y_new_tab_notice( $transformed );

			if ( ! empty( $skipped ) ) {
				$transformed = strtr( $transformed, $skipped );
			}

			return $transformed;
		}

		/**
		 * Append a visually-hidden "(opens in new tab)" notice immediately
		 * before `</a>` for any anchor where we forced target="_blank".
		 *
		 * Anchors are marked during transform_anchors() with a
		 * data-timu-elc-forced-blank="1" attribute; this method consumes
		 * the marker (it would otherwise leak into the final HTML).
		 *
		 * Filterable via `timu_elc_a11y_new_tab_text` so site owners can
		 * customise the wording (e.g. translate, shorten, swap to an icon).
		 * Returning an empty string suppresses injection entirely.
		 *
		 * @param string $html Already-transformed HTML.
		 * @return string
		 */
		private static function inject_a11y_new_tab_notice( $html ) {
			if ( false === strpos( $html, 'data-timu-elc-forced-blank' ) ) {
				return $html;
			}

			$notice = (string) apply_filters(
				'timu_elc_a11y_new_tab_text',
				/* translators: hidden text appended to external links forced to open in a new tab. */
				__( '(opens in new tab)', 'thisismyurl-external-link-control' )
			);

			return preg_replace_callback(
				'#<a\b([^>]*\bdata-timu-elc-forced-blank=["\']1["\'][^>]*)>(.*?)</a>#is',
				static function ( $m ) use ( $notice ) {
					$attrs = preg_replace( '/\s*data-timu-elc-forced-blank=["\']1["\']/i', '', $m[1] );
					$inner = $m[2];
					if ( '' !== $notice ) {
						$inner .= '<span class="screen-reader-text"> ' . esc_html( $notice ) . '</span>';
					}
					return '<a' . $attrs . '>' . $inner . '</a>';
				},
				$html
			);
		}

		/**
		 * Build the same-tab exception list from options.
		 *
		 * Returns a flat array of lowercase normalised hostnames that must
		 * never receive target="_blank" even when the global new_tab toggle
		 * is on. An empty array means no exceptions.
		 *
		 * @param array<string,mixed> $options Options snapshot.
		 * @return array<string>
		 */
		private static function same_tab_hosts( $options ) {
			$raw = isset( $options['target_same_tab_domains'] ) ? (string) $options['target_same_tab_domains'] : '';
			if ( '' === $raw ) {
				return array();
			}
			$parts = explode( ',', $raw );
			$hosts = array();
			foreach ( $parts as $part ) {
				$clean = strtolower( trim( $part ) );
				if ( '' !== $clean ) {
					$hosts[] = $clean;
				}
			}
			return $hosts;
		}

		/**
		 * Walk anchor tags in HTML and rewrite each one. Uses
		 * WP_HTML_Tag_Processor (WP 6.2+) when available so we get a
		 * proper attribute parser instead of a regex; falls back to the
		 * regex path on older cores.
		 *
		 * @param string             $html       HTML chunk.
		 * @param array<string,int>  $options    Options snapshot.
		 * @param array<string>      $forced_rel Tokens forced by caller.
		 * @return string
		 */
		private static function transform_anchors( $html, $options, $forced_rel ) {
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$same_tab = self::same_tab_hosts( $options );
				$tags = new WP_HTML_Tag_Processor( $html );
				while ( $tags->next_tag( 'a' ) ) {
					$href = $tags->get_attribute( 'href' );
					if ( ! is_string( $href ) || ! TIMU_ELC_Host::is_external( $href ) ) {
						continue;
					}

					$existing_target = $tags->get_attribute( 'target' );
					$existing_rel    = $tags->get_attribute( 'rel' );

					$href_host = TIMU_ELC_Host::host_for_href( $href );
					$rule      = class_exists( 'TIMU_ELC_Domain_Rules' ) ? TIMU_ELC_Domain_Rules::match( $href_host ) : null;

					// Check same-tab exception list before applying target="_blank".
					// A match exempts this link from new-tab behaviour only; rel
					// attributes (nofollow, noopener, noreferrer) still apply.
					$in_same_tab_list = ! empty( $same_tab ) && in_array( $href_host, $same_tab, true );

					$target  = $existing_target;
					$we_set_blank = false;
					if ( null === $existing_target || '' === $existing_target ) {
						if ( $rule && '' !== $rule['target'] ) {
							$target = $rule['target'];
							$tags->set_attribute( 'target', $target );
							$we_set_blank = ( '_blank' === $target );
						} elseif ( ! empty( $options['new_tab'] ) && ! $in_same_tab_list ) {
							$target = '_blank';
							$tags->set_attribute( 'target', '_blank' );
							$we_set_blank = true;
						}
					}

					if ( $we_set_blank ) {
						// Mark the tag so the post-pass can inject screen-reader text
						// after </a> for accessibility ("opens in new tab" warning).
						$tags->set_attribute( 'data-timu-elc-forced-blank', '1' );
					}

					$rel_to_add = array();

					if ( '_blank' === $target ) {
						$rel_to_add[] = 'noopener';
						// noreferrer is user-togglable; missing key = ON for existing installs.
						if ( ! isset( $options['noreferrer'] ) || ! empty( $options['noreferrer'] ) ) {
							$rel_to_add[] = 'noreferrer';
						}
					}

					$apply_nofollow = ! empty( $options['nofollow'] );
					if ( $rule && ! empty( $rule['dofollow'] ) ) {
						$apply_nofollow = false;
					}
					if ( $apply_nofollow ) {
						$rel_to_add[] = 'nofollow';
					}

					if ( $rule && ! empty( $rule['sponsored'] ) ) {
						$rel_to_add[] = 'sponsored';
					}

					if ( ! empty( $forced_rel ) ) {
						$rel_to_add = array_merge( $rel_to_add, $forced_rel );
					}

					$data_sponsored = $tags->get_attribute( 'data-rel-sponsored' );
					if ( '1' === (string) $data_sponsored ) {
						$rel_to_add[] = 'sponsored';
					}

					if ( empty( $rel_to_add ) ) {
						continue;
					}

					$existing_tokens = is_string( $existing_rel )
						? preg_split( '/\s+/', strtolower( trim( $existing_rel ) ), -1, PREG_SPLIT_NO_EMPTY )
						: array();
					$existing_tokens = is_array( $existing_tokens ) ? $existing_tokens : array();
					$rel_to_add      = array_map( 'strtolower', $rel_to_add );
					$merged          = array_values( array_unique( array_merge( $existing_tokens, $rel_to_add ) ) );
					$tags->set_attribute( 'rel', implode( ' ', $merged ) );
				}
				return $tags->get_updated_html();
			}

			// Fallback for WP < 6.2.
			$same_tab = self::same_tab_hosts( $options );
			return preg_replace_callback(
				'/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>/i',
				static function ( $matches ) use ( $options, $forced_rel, $same_tab ) {
					return self::rewrite_anchor_tag( $matches[0], $matches[1], $options, $forced_rel, $same_tab );
				},
				$html
			);
		}

		/**
		 * Rewrite a single `<a>` opening tag.
		 *
		 * Decisions are layered:
		 *   - skip internal links entirely
		 *   - if "force new tab" is on AND the link has no target AND the
		 *     domain is not in the same-tab exception list, set _blank
		 *   - if the resulting tag has target=_blank from any source
		 *     (existing markup or our own injection), force `noopener noreferrer`
		 *     onto rel — this is decoupled from the nofollow setting so the
		 *     reverse-tabnabbing protection always lands on _blank links
		 *   - if "nofollow" is on, add nofollow to the rel token list
		 *
		 * Existing rel tokens are preserved; new tokens are merged in. We
		 * never replace a rel attribute the editor wrote (e.g. rel="me").
		 *
		 * @param string             $link_html  Original `<a ...>` opening tag.
		 * @param string             $url        href value.
		 * @param array<string,int>  $options    Plugin options snapshot.
		 * @param array<string>      $forced_rel Tokens forced by caller (e.g. 'ugc' on comment_text).
		 * @param array<string>      $same_tab   Lowercased hostnames exempt from target="_blank".
		 * @return string Possibly-rewritten opening tag.
		 */
		private static function rewrite_anchor_tag( $link_html, $url, $options, $forced_rel = array(), $same_tab = array() ) {
			if ( ! TIMU_ELC_Host::is_external( $url ) ) {
				return $link_html;
			}

			$href_host        = TIMU_ELC_Host::host_for_href( $url );
			$rule             = class_exists( 'TIMU_ELC_Domain_Rules' ) ? TIMU_ELC_Domain_Rules::match( $href_host ) : null;
			$has_target       = (bool) preg_match( '/\btarget\s*=/i', $link_html );
			$in_same_tab_list = ! empty( $same_tab ) && in_array( $href_host, $same_tab, true );

			// Per-domain target override beats the global new-tab toggle.
			// Same-tab exception list exempts from the global toggle only;
			// an explicit per-domain rule still takes effect.
			if ( $rule && '' !== $rule['target'] && ! $has_target ) {
				$link_html  = str_replace( '<a ', '<a target="' . esc_attr( $rule['target'] ) . '" ', $link_html );
				$has_target = true;
			} elseif ( ! empty( $options['new_tab'] ) && ! $has_target && ! $in_same_tab_list ) {
				$link_html  = str_replace( '<a ', '<a target="_blank" ', $link_html );
				$has_target = true;
			}

			$tag_target_blank = (bool) preg_match( '/\btarget\s*=\s*["\']?_blank["\']?/i', $link_html );

			$rel_to_add = array();

			if ( $tag_target_blank ) {
				// noopener always applied on _blank (reverse-tabnabbing).
				// noreferrer is user-togglable; missing key = ON for existing installs.
				$rel_to_add[] = 'noopener';
				if ( ! isset( $options['noreferrer'] ) || ! empty( $options['noreferrer'] ) ) {
					$rel_to_add[] = 'noreferrer';
				}
			}

			$apply_nofollow = ! empty( $options['nofollow'] );
			if ( $rule && ! empty( $rule['dofollow'] ) ) {
				// Domain rule wins: explicitly dofollow / allowlist suppresses the global nofollow.
				$apply_nofollow = false;
			}
			if ( $apply_nofollow ) {
				$rel_to_add[] = 'nofollow';
			}

			if ( $rule && ! empty( $rule['sponsored'] ) ) {
				$rel_to_add[] = 'sponsored';
			}

			if ( ! empty( $forced_rel ) ) {
				$rel_to_add = array_merge( $rel_to_add, $forced_rel );
			}

			// Per-link sponsored opt-in via `data-rel-sponsored="1"` on the editor side.
			// Lets editorial flag a paid placement without exposing the plugin
			// settings UI to non-admins.
			if ( preg_match( '/\bdata-rel-sponsored\s*=\s*["\']1["\']/i', $link_html ) ) {
				$rel_to_add[] = 'sponsored';
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
