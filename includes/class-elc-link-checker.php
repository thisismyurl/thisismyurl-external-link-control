<?php
/**
 * Broken-link cron: weekly checks on external link inventory.
 *
 * Scans published post content for external URLs, issues HEAD requests (with a
 * GET fallback for HEAD-hostile servers), and sorts the results into two
 * buckets:
 *
 *   - broken     — the link is genuinely gone (404 / 410) or its host no longer
 *                  resolves (a dead domain). These drive the admin notice and
 *                  the headline count.
 *   - unverified — the server answered, but in a way that means "I exist, I'm
 *                  just not letting a bot confirm it": 401 / 403 / 405 / 429 /
 *                  451 / 999, every other non-2xx/3xx, and transient network
 *                  errors (timeouts, TLS handshake failures). These are shown
 *                  for transparency but never raise the notice. LinkedIn
 *                  (405 to HEAD) and time.com (403 to bots) live here.
 *
 * Two site-owner controls sit on top of the scan:
 *
 *   - an ignore list (`timu_elc_broken_link_ignored`) of URLs or bare hosts the
 *     owner has chosen to stop hearing about; ignored entries are skipped at
 *     collection time so they cost no request and never surface; and
 *   - a dismissed-set fingerprint stored on the results option, so once the
 *     notice is dismissed it stays dismissed until a *later* scan finds a URL
 *     that wasn't in the dismissed set — not every time the same links recur.
 *
 * @package TIMU_ELC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ELC_Link_Checker manages the weekly broken-link cron event, the admin
 * notice, the dashboard widget, the ignore list, and the AJAX actions that
 * back the widget's buttons.
 */
class ELC_Link_Checker {

	/**
	 * WP cron event hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'timu_elc_broken_link_check';

	/**
	 * Option key that stores the last check's results.
	 *
	 * @var string
	 */
	const RESULTS_OPTION = 'timu_elc_broken_link_results';

	/**
	 * Option key that stores the owner's ignore list (URLs and bare hosts).
	 *
	 * @var string
	 */
	const IGNORE_OPTION = 'timu_elc_broken_link_ignored';

	/**
	 * Shared nonce action for every widget/notice AJAX action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'timu_elc_link_actions';

	/**
	 * Maximum external URLs to check per run (prevents timeout).
	 *
	 * @var int
	 */
	const MAX_URLS_PER_RUN = 200;

	/**
	 * HTTP timeout in seconds for each request.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 10;

	/**
	 * Initialise hooks.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_check' ) );
		add_action( 'admin_init', array( $this, 'schedule_if_needed' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_admin_notice' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_timu_elc_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
		add_action( 'wp_ajax_timu_elc_ignore_link', array( $this, 'ajax_ignore_link' ) );
		add_action( 'wp_ajax_timu_elc_unignore_link', array( $this, 'ajax_unignore_link' ) );
		add_action( 'wp_ajax_timu_elc_recheck_link', array( $this, 'ajax_recheck_link' ) );
		add_action( 'wp_ajax_timu_elc_scan_now', array( $this, 'ajax_scan_now' ) );
	}

	/**
	 * Schedule the weekly cron event if not already scheduled.
	 */
	public function schedule_if_needed() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule cron on plugin deactivation. Call from deactivation hook.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * The scan
	 * ------------------------------------------------------------------- */

	/**
	 * Run the scan. Invoked by the cron event and by the "Scan now" action.
	 */
	public function run_check() {
		$urls = $this->collect_external_urls();
		if ( empty( $urls ) ) {
			// Nothing left to check — clear stale broken results so the notice
			// doesn't outlive the content that produced it.
			$this->store_results( array(), array(), 0 );
			return;
		}

		$broken     = array();
		$unverified = array();

		foreach ( $urls as $url => $post_ids ) {
			$result = $this->check_url( $url );

			if ( 'ok' === $result['verdict'] ) {
				continue;
			}

			$entry = array(
				'status'   => $result['status'],
				'message'  => $result['message'],
				'post_ids' => $post_ids,
			);

			if ( 'broken' === $result['verdict'] ) {
				$broken[ $url ] = $entry;
			} else {
				$unverified[ $url ] = $entry;
			}
		}

		$this->store_results( $broken, $unverified, count( $urls ) );
	}

	/**
	 * Persist results, carrying the dismissed-set fingerprint forward.
	 *
	 * The dismissed set is intersected with the *current* broken set: a URL
	 * that was dismissed and is still broken stays dismissed; a URL that is no
	 * longer broken drops out of the fingerprint (so if it breaks again later
	 * it is treated as new). A broken URL that was never dismissed is, by
	 * definition, not in the fingerprint, so the notice will show for it.
	 *
	 * @param array<string,array<string,mixed>> $broken     Broken URL map.
	 * @param array<string,array<string,mixed>> $unverified Unverified URL map.
	 * @param int                                $checked    URLs checked.
	 * @return void
	 */
	private function store_results( $broken, $unverified, $checked ) {
		$previous       = get_option( self::RESULTS_OPTION );
		$dismissed_urls = $this->dismissed_urls( is_array( $previous ) ? $previous : array() );

		// Keep only fingerprints that are still broken.
		$dismissed_urls = array_values( array_intersect( $dismissed_urls, array_keys( $broken ) ) );

		update_option(
			self::RESULTS_OPTION,
			array(
				'checked_at'     => time(),
				'checked'        => (int) $checked,
				'broken'         => $broken,
				'unverified'     => $unverified,
				'dismissed_urls' => $dismissed_urls,
			),
			false
		);
	}

	/**
	 * Issue a HEAD request, falling back to GET when the server is HEAD-hostile,
	 * and classify the outcome.
	 *
	 * @param string $url URL to check.
	 * @return array{status:int,message:string,verdict:string}
	 */
	private function check_url( $url ) {
		$args = array(
			'timeout'     => self::REQUEST_TIMEOUT,
			'user-agent'  => 'Mozilla/5.0 (compatible; TIMU-ELC-LinkChecker/1.1; +https://thisismyurl.com/)',
			'sslverify'   => true,
			'redirection' => 3,
		);

		$response = wp_remote_head( $url, $args );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// HEAD-hostile servers (and a few proxies) answer HEAD with a method or
		// generic-client error even though GET is fine. Retry once with GET,
		// asking for a single byte so we don't pull the whole body.
		if ( is_wp_error( $response ) || in_array( $status, array( 400, 403, 405, 406, 501 ), true ) ) {
			$get_args            = $args;
			$get_args['headers'] = array( 'Range' => 'bytes=0-0' );
			$get                 = wp_remote_get( $url, $get_args );

			if ( ! is_wp_error( $get ) ) {
				$response = $get;
				$status   = (int) wp_remote_retrieve_response_code( $get );
			} elseif ( ! is_wp_error( $response ) ) {
				// Keep the HEAD response if only the GET retry errored.
				$status = (int) wp_remote_retrieve_response_code( $response );
			} else {
				$response = $get;
				$status   = 0;
			}
		}

		return $this->classify( $response, $status );
	}

	/**
	 * Classify a checked URL into ok / broken / unverified.
	 *
	 * @param array|WP_Error $response The (final) HTTP response or error.
	 * @param int            $status   The (final) HTTP status code (0 on error).
	 * @return array{status:int,message:string,verdict:string}
	 */
	private function classify( $response, $status ) {
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();

			// A host that no longer resolves, or actively refuses the
			// connection, is a dead link. A timeout or TLS failure is
			// unverifiable — the host may simply be slow or fussy.
			$dead = (bool) preg_match(
				'/could ?n.?t resolve host|name or service not known|getaddrinfo|cURL error 6|cURL error 7|connection refused|no address associated/i',
				$message
			);

			return array(
				'status'  => 0,
				'message' => $message,
				'verdict' => $dead ? 'broken' : 'unverified',
			);
		}

		$message = (string) wp_remote_retrieve_response_message( $response );

		if ( $status >= 200 && $status < 400 ) {
			return array(
				'status'  => $status,
				'message' => $message,
				'verdict' => 'ok',
			);
		}

		/**
		 * Filter the status codes that count as genuinely broken.
		 *
		 * Defaults to 404 (Not Found) and 410 (Gone) — the two codes that mean
		 * the resource is definitively no longer there. Everything else that
		 * isn't a 2xx/3xx is treated as "unverified" rather than broken, so
		 * bot-blocking (403), method restrictions (405), auth gates (401),
		 * rate limits (429), and server errors (5xx) don't raise false alarms.
		 *
		 * @since 1.6148
		 *
		 * @param int[] $codes Broken status codes.
		 */
		$broken_codes = (array) apply_filters( 'timu_elc_broken_status_codes', array( 404, 410 ) );

		return array(
			'status'  => $status,
			'message' => $message,
			'verdict' => in_array( $status, array_map( 'intval', $broken_codes ), true ) ? 'broken' : 'unverified',
		);
	}

	/**
	 * Collect up to MAX_URLS_PER_RUN unique external URLs from published posts,
	 * skipping anything on the ignore list.
	 *
	 * @return array<string, int[]> URL => [ post_id, ... ]
	 */
	private function collect_external_urls() {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$ignored   = $this->get_ignored();

		$paged    = 1;
		$all_urls = array();
		$done     = false;

		while ( ! $done && count( $all_urls ) < self::MAX_URLS_PER_RUN ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'any',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'paged'          => $paged,
					'no_found_rows'  => false,
					'fields'         => 'all',
				)
			);

			if ( ! $query->have_posts() ) {
				break;
			}

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				preg_match_all( '/href=["\'](?P<url>https?:\/\/[^"\'>\s]+)["\']/', $post->post_content, $matches );

				foreach ( $matches['url'] as $raw_url ) {
					$parsed_host = wp_parse_url( $raw_url, PHP_URL_HOST );

					// Skip internal links.
					if ( ! $parsed_host || $parsed_host === $site_host ) {
						continue;
					}

					// Normalise to scheme+host+path (strip query/fragment for deduplication).
					$normalised = strtolower( $raw_url );
					$normalised = preg_replace( '/[?#].*$/', '', $normalised );

					// Skip anything the owner has chosen to ignore.
					if ( $this->matches_ignore( $normalised, $ignored ) ) {
						continue;
					}

					if ( ! isset( $all_urls[ $normalised ] ) ) {
						$all_urls[ $normalised ] = array();
					}

					if ( ! in_array( (int) $post->ID, $all_urls[ $normalised ], true ) ) {
						$all_urls[ $normalised ][] = (int) $post->ID;
					}

					if ( count( $all_urls ) >= self::MAX_URLS_PER_RUN ) {
						$done = true;
						break 2;
					}
				}
			}

			if ( $paged >= $query->max_num_pages ) {
				$done = true;
			}

			++$paged;
		}

		return $all_urls;
	}

	/* ---------------------------------------------------------------------
	 * The ignore list
	 * ------------------------------------------------------------------- */

	/**
	 * Return the ignore list — a flat array of normalised URLs and bare hosts.
	 *
	 * @return string[]
	 */
	public function get_ignored() {
		$raw = get_option( self::IGNORE_OPTION, array() );
		return is_array( $raw ) ? array_values( array_unique( array_filter( array_map( 'strval', $raw ) ) ) ) : array();
	}

	/**
	 * Does a normalised URL match any ignore entry?
	 *
	 * An entry matches when it equals the URL exactly, or when it is a bare
	 * host (no slash) that equals the URL's host with any leading `www.`
	 * stripped from both sides.
	 *
	 * @param string   $url     Normalised (lowercased, query-stripped) URL.
	 * @param string[] $ignored Ignore list.
	 * @return bool
	 */
	private function matches_ignore( $url, $ignored ) {
		if ( empty( $ignored ) ) {
			return false;
		}

		$url_host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$url_host = preg_replace( '/^www\./', '', strtolower( $url_host ) );

		foreach ( $ignored as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			// Exact-URL match.
			if ( $entry === $url ) {
				return true;
			}
			// Bare-host match (entry carries no path).
			if ( false === strpos( $entry, '/' ) ) {
				$entry_host = preg_replace( '/^www\./', '', $entry );
				if ( '' !== $entry_host && $entry_host === $url_host ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Normalise a single ignore candidate. A full URL is lowercased and has its
	 * query/fragment stripped; a bare host is lowercased and trimmed.
	 *
	 * @param string $candidate Raw user input.
	 * @return string Normalised entry, or '' if unusable.
	 */
	private function normalise_ignore_entry( $candidate ) {
		$candidate = strtolower( trim( (string) $candidate ) );
		if ( '' === $candidate ) {
			return '';
		}
		if ( 0 === strpos( $candidate, 'http://' ) || 0 === strpos( $candidate, 'https://' ) ) {
			return (string) preg_replace( '/[?#].*$/', '', $candidate );
		}
		// Treat anything else as a bare host; strip a stray trailing slash.
		return rtrim( $candidate, '/' );
	}

	/* ---------------------------------------------------------------------
	 * The admin notice
	 * ------------------------------------------------------------------- */

	/**
	 * Show an admin notice when undismissed broken links exist.
	 */
	public function maybe_render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$results = get_option( self::RESULTS_OPTION );
		if ( ! is_array( $results ) ) {
			return;
		}

		$undismissed = $this->undismissed_broken( $results );
		if ( empty( $undismissed ) ) {
			return;
		}

		$count = count( $undismissed );
		$when  = isset( $results['checked_at'] ) ? human_time_diff( (int) $results['checked_at'], time() ) : '';

		printf(
			'<div class="notice notice-warning is-dismissible timu-elc-broken-notice"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of broken links found. */
					_n( 'External Link Control found %d broken link', 'External Link Control found %d broken links', $count, 'thisismyurl-external-link-control' ),
					$count
				)
			),
			$when
				? esc_html(
					sprintf(
						/* translators: %s: human-readable time delta, e.g. "2 days". */
						__( '(last scan %s ago).', 'thisismyurl-external-link-control' ),
						$when
					)
				)
				: '',
			esc_url( admin_url( 'index.php#timu-elc-broken-links' ) ),
			esc_html__( 'Review them', 'thisismyurl-external-link-control' )
		);
	}

	/**
	 * The broken URLs that are neither ignored nor in the dismissed set.
	 *
	 * @param array<string,mixed> $results Stored results option.
	 * @return string[] Undismissed broken URLs.
	 */
	private function undismissed_broken( $results ) {
		$broken = isset( $results['broken'] ) && is_array( $results['broken'] ) ? array_keys( $results['broken'] ) : array();
		if ( empty( $broken ) ) {
			return array();
		}

		$dismissed = $this->dismissed_urls( $results );
		$ignored   = $this->get_ignored();

		$out = array();
		foreach ( $broken as $url ) {
			if ( in_array( $url, $dismissed, true ) ) {
				continue;
			}
			if ( $this->matches_ignore( $url, $ignored ) ) {
				continue;
			}
			$out[] = $url;
		}
		return $out;
	}

	/**
	 * Read the dismissed-URL fingerprint, tolerating the legacy boolean shape.
	 *
	 * Before 1.6148 the option stored `dismissed => true|false`. A legacy
	 * `true` means "the owner dismissed everything that was broken then", so we
	 * treat every currently-broken URL as dismissed for that one transition.
	 *
	 * @param array<string,mixed> $results Stored results option.
	 * @return string[]
	 */
	private function dismissed_urls( $results ) {
		if ( isset( $results['dismissed_urls'] ) && is_array( $results['dismissed_urls'] ) ) {
			return array_values( array_filter( array_map( 'strval', $results['dismissed_urls'] ) ) );
		}
		if ( ! empty( $results['dismissed'] ) && isset( $results['broken'] ) && is_array( $results['broken'] ) ) {
			return array_keys( $results['broken'] );
		}
		return array();
	}

	/* ---------------------------------------------------------------------
	 * The dashboard widget
	 * ------------------------------------------------------------------- */

	/**
	 * Register the broken-links dashboard widget.
	 */
	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'timu_elc_broken_links',
			__( 'External Link Control: Broken Links', 'thisismyurl-external-link-control' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget content.
	 */
	public function render_dashboard_widget() {
		$results = get_option( self::RESULTS_OPTION );

		echo '<div class="timu-elc-widget" id="timu-elc-broken-links">';

		if ( ! is_array( $results ) ) {
			echo '<p>' . esc_html__( 'No scan has run yet. A scan runs automatically every week.', 'thisismyurl-external-link-control' ) . '</p>';
			$this->render_scan_button();
			echo '</div>';
			return;
		}

		$checked_at = isset( $results['checked_at'] ) ? (int) $results['checked_at'] : 0;
		$broken     = isset( $results['broken'] ) && is_array( $results['broken'] ) ? $results['broken'] : array();
		$unverified = isset( $results['unverified'] ) && is_array( $results['unverified'] ) ? $results['unverified'] : array();
		$checked    = isset( $results['checked'] ) ? (int) $results['checked'] : 0;

		echo '<p class="timu-elc-last-scan">';
		printf(
			/* translators: 1: number of URLs checked, 2: human-readable date. */
			esc_html__( 'Last scan: %1$d URLs checked on %2$s.', 'thisismyurl-external-link-control' ),
			(int) $checked,
			esc_html( $checked_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checked_at ) : '—' )
		);
		echo '</p>';

		$this->render_scan_button();

		if ( empty( $broken ) ) {
			echo '<p class="timu-elc-clear">' . esc_html__( 'No broken links found.', 'thisismyurl-external-link-control' ) . '</p>';
		} else {
			echo '<h4>';
			printf(
				/* translators: %d: number of broken links. */
				esc_html( _n( '%d broken link', '%d broken links', count( $broken ), 'thisismyurl-external-link-control' ) ),
				(int) count( $broken )
			);
			echo '</h4>';
			$this->render_link_list( $broken, 'broken' );
		}

		if ( ! empty( $unverified ) ) {
			echo '<h4 class="timu-elc-unverified-head">' . esc_html__( 'Could not verify', 'thisismyurl-external-link-control' ) . '</h4>';
			echo '<p class="description">' . esc_html__( 'These answered, but blocked the check (bot protection, rate limit, login wall, or a server error). They are most likely fine.', 'thisismyurl-external-link-control' ) . '</p>';
			$this->render_link_list( $unverified, 'unverified' );
		}

		$this->render_ignored_list();

		echo '</div>';
	}

	/**
	 * Render the "Scan now" button.
	 */
	private function render_scan_button() {
		printf(
			'<p class="timu-elc-actions"><button type="button" class="button button-secondary timu-elc-scan-now">%s</button> <span class="timu-elc-status" role="status" aria-live="polite"></span></p>',
			esc_html__( 'Scan now', 'thisismyurl-external-link-control' )
		);
	}

	/**
	 * Render a list of checked links with per-row actions.
	 *
	 * @param array<string,array<string,mixed>> $items  URL => info map.
	 * @param string                            $bucket 'broken' or 'unverified'.
	 * @return void
	 */
	private function render_link_list( $items, $bucket ) {
		echo '<ul class="timu-elc-link-list timu-elc-' . esc_attr( $bucket ) . '">';

		$shown = 0;
		foreach ( $items as $url => $info ) {
			if ( $shown >= 25 ) {
				break;
			}
			$shown++;

			$status   = isset( $info['status'] ) ? (int) $info['status'] : 0;
			$post_ids = isset( $info['post_ids'] ) && is_array( $info['post_ids'] ) ? $info['post_ids'] : array();
			$edit     = ! empty( $post_ids ) ? admin_url( 'post.php?post=' . (int) $post_ids[0] . '&action=edit' ) : '';

			printf(
				'<li data-url="%s"><code>%s</code> <span class="timu-elc-code">HTTP %s</span>%s <span class="timu-elc-row-actions">%s<button type="button" class="button-link timu-elc-ignore">%s</button></span></li>',
				esc_attr( $url ),
				esc_html( $url ),
				$status ? esc_html( (string) $status ) : esc_html__( 'error', 'thisismyurl-external-link-control' ),
				$edit ? sprintf( ' (<a href="%s">%s</a>)', esc_url( $edit ), esc_html__( 'edit post', 'thisismyurl-external-link-control' ) ) : '',
				sprintf( '<button type="button" class="button-link timu-elc-recheck">%s</button> ', esc_html__( 'Recheck', 'thisismyurl-external-link-control' ) ),
				esc_html__( 'Ignore', 'thisismyurl-external-link-control' )
			);
		}

		if ( count( $items ) > $shown ) {
			echo '<li class="timu-elc-more"><em>' . esc_html(
				sprintf(
					/* translators: %d: number of additional links not shown. */
					__( '…and %d more.', 'thisismyurl-external-link-control' ),
					count( $items ) - $shown
				)
			) . '</em></li>';
		}

		echo '</ul>';
	}

	/**
	 * Render the ignore list with "Stop ignoring" actions.
	 */
	private function render_ignored_list() {
		$ignored = $this->get_ignored();
		if ( empty( $ignored ) ) {
			return;
		}

		echo '<h4 class="timu-elc-ignored-head">' . esc_html__( 'Ignored', 'thisismyurl-external-link-control' ) . '</h4>';
		echo '<ul class="timu-elc-link-list timu-elc-ignored">';
		foreach ( $ignored as $entry ) {
			printf(
				'<li data-url="%s"><code>%s</code> <span class="timu-elc-row-actions"><button type="button" class="button-link timu-elc-unignore">%s</button></span></li>',
				esc_attr( $entry ),
				esc_html( $entry ),
				esc_html__( 'Stop ignoring', 'thisismyurl-external-link-control' )
			);
		}
		echo '</ul>';
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	/**
	 * Enqueue the widget/notice JS (and the shared admin CSS) wherever the
	 * notice or widget can appear. The notice can show on any admin screen, so
	 * the script loads admin-wide for capable users; it is tiny.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		unset( $hook );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$base = plugin_dir_url( dirname( __FILE__ ) );

		wp_enqueue_style(
			'timu-elc-admin',
			$base . 'assets/css/admin.css',
			array(),
			'1.6148'
		);

		wp_enqueue_script(
			'timu-elc-admin',
			$base . 'assets/js/admin.js',
			array(),
			'1.6148',
			true
		);

		wp_localize_script(
			'timu-elc-admin',
			'timuElc',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'scanning'  => __( 'Scanning… this can take a minute. Refresh to see results.', 'thisismyurl-external-link-control' ),
					'scanError' => __( 'Could not start the scan. Please try again.', 'thisismyurl-external-link-control' ),
					'rechecking' => __( 'Rechecking…', 'thisismyurl-external-link-control' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX actions
	 * ------------------------------------------------------------------- */

	/**
	 * Shared guard: verify nonce + capability, or die.
	 *
	 * @return void
	 */
	private function verify_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'thisismyurl-external-link-control' ) ), 403 );
		}
	}

	/**
	 * Read and normalise the `url` parameter from the request.
	 *
	 * @return string Normalised URL/host, or '' if absent.
	 */
	private function request_url() {
		$raw = isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitized
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return '';
		}
		// URLs are stored lowercased + query-stripped; mirror that here.
		$lower = strtolower( $raw );
		if ( 0 === strpos( $lower, 'http://' ) || 0 === strpos( $lower, 'https://' ) ) {
			return (string) preg_replace( '/[?#].*$/', '', $lower );
		}
		return rtrim( $lower, '/' );
	}

	/**
	 * AJAX: dismiss the notice by fingerprinting the current broken set.
	 */
	public function ajax_dismiss_notice() {
		$this->verify_request();

		$results = get_option( self::RESULTS_OPTION );
		if ( is_array( $results ) ) {
			// Dismiss exactly what is broken now; a later scan that finds a new
			// URL will not be in this set, so the notice returns for it.
			$results['dismissed_urls'] = isset( $results['broken'] ) && is_array( $results['broken'] )
				? array_keys( $results['broken'] )
				: array();
			unset( $results['dismissed'] ); // Retire the legacy boolean.
			update_option( self::RESULTS_OPTION, $results, false );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: add a URL/host to the ignore list and drop it from results.
	 */
	public function ajax_ignore_link() {
		$this->verify_request();

		$entry = $this->normalise_ignore_entry( $this->request_url() );
		if ( '' === $entry ) {
			wp_send_json_error( array( 'message' => __( 'No URL supplied.', 'thisismyurl-external-link-control' ) ) );
		}

		$ignored = $this->get_ignored();
		if ( ! in_array( $entry, $ignored, true ) ) {
			$ignored[] = $entry;
			update_option( self::IGNORE_OPTION, array_values( $ignored ), false );
		}

		// Remove it from the live result buckets and the dismissed fingerprint.
		$results = get_option( self::RESULTS_OPTION );
		if ( is_array( $results ) ) {
			foreach ( array( 'broken', 'unverified' ) as $bucket ) {
				if ( isset( $results[ $bucket ] ) && is_array( $results[ $bucket ] ) ) {
					foreach ( array_keys( $results[ $bucket ] ) as $url ) {
						if ( $this->matches_ignore( $url, array( $entry ) ) ) {
							unset( $results[ $bucket ][ $url ] );
						}
					}
				}
			}
			if ( isset( $results['dismissed_urls'] ) && is_array( $results['dismissed_urls'] ) ) {
				$results['dismissed_urls'] = array_values(
					array_filter(
						$results['dismissed_urls'],
						function ( $url ) use ( $entry ) {
							return ! $this->matches_ignore( (string) $url, array( $entry ) );
						}
					)
				);
			}
			update_option( self::RESULTS_OPTION, $results, false );
		}

		wp_send_json_success( array( 'entry' => $entry ) );
	}

	/**
	 * AJAX: remove a URL/host from the ignore list.
	 */
	public function ajax_unignore_link() {
		$this->verify_request();

		$entry   = $this->normalise_ignore_entry( $this->request_url() );
		$ignored = $this->get_ignored();
		$ignored = array_values(
			array_filter(
				$ignored,
				static function ( $e ) use ( $entry ) {
					return $e !== $entry;
				}
			)
		);
		update_option( self::IGNORE_OPTION, $ignored, false );

		wp_send_json_success( array( 'entry' => $entry ) );
	}

	/**
	 * AJAX: recheck a single URL now and report the new verdict.
	 */
	public function ajax_recheck_link() {
		$this->verify_request();

		$url = $this->request_url();
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'No URL supplied.', 'thisismyurl-external-link-control' ) ) );
		}

		$result = $this->check_url( $url );

		// Move the URL into its correct bucket in the stored results.
		$results = get_option( self::RESULTS_OPTION );
		if ( is_array( $results ) ) {
			$post_ids = array();
			foreach ( array( 'broken', 'unverified' ) as $bucket ) {
				if ( isset( $results[ $bucket ][ $url ]['post_ids'] ) ) {
					$post_ids = (array) $results[ $bucket ][ $url ]['post_ids'];
				}
				unset( $results[ $bucket ][ $url ] );
			}

			if ( 'ok' !== $result['verdict'] ) {
				$target = ( 'broken' === $result['verdict'] ) ? 'broken' : 'unverified';
				if ( ! isset( $results[ $target ] ) || ! is_array( $results[ $target ] ) ) {
					$results[ $target ] = array();
				}
				$results[ $target ][ $url ] = array(
					'status'   => $result['status'],
					'message'  => $result['message'],
					'post_ids' => $post_ids,
				);
			}
			update_option( self::RESULTS_OPTION, $results, false );
		}

		wp_send_json_success(
			array(
				'url'     => $url,
				'status'  => $result['status'],
				'verdict' => $result['verdict'],
			)
		);
	}

	/**
	 * AJAX: run a scan now.
	 *
	 * A full scan makes up to MAX_URLS_PER_RUN external requests, which is too
	 * slow to block a browser request on. Instead we schedule the cron event to
	 * fire immediately and spawn the cron runner; the JS tells the owner to
	 * refresh shortly. On environments with WP-Cron disabled we fall back to
	 * running the scan inline.
	 */
	public function ajax_scan_now() {
		$this->verify_request();

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$this->run_check();
			wp_send_json_success( array( 'mode' => 'inline' ) );
		}

		wp_schedule_single_event( time(), self::CRON_HOOK );
		spawn_cron();
		wp_send_json_success( array( 'mode' => 'scheduled' ) );
	}
}
