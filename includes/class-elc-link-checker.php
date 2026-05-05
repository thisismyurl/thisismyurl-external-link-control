<?php
/**
 * Broken-link cron: weekly HEAD checks on external link inventory.
 *
 * Scans post content for external URLs, issues HEAD requests, and surfaces
 * 4xx/5xx results in a WP admin notice + a dismissible dashboard widget.
 *
 * @package TIMU_ELC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ELC_Link_Checker manages the weekly broken-link HEAD-check cron event.
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
	 * Maximum external URLs to HEAD-check per run (prevents timeout).
	 *
	 * @var int
	 */
	const MAX_URLS_PER_RUN = 200;

	/**
	 * HTTP timeout in seconds for each HEAD request.
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
		add_action( 'wp_ajax_timu_elc_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
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

	/**
	 * Run the HEAD-check scan. Invoked by the cron event.
	 */
	public function run_check() {
		$urls = $this->collect_external_urls();
		if ( empty( $urls ) ) {
			return;
		}

		$broken = array();

		foreach ( $urls as $url => $post_ids ) {
			$response = wp_remote_head(
				$url,
				array(
					'timeout'    => self::REQUEST_TIMEOUT,
					'user-agent' => 'Mozilla/5.0 (compatible; TIMU-ELC-LinkChecker/1.0; +https://thisismyurl.com/)',
					'sslverify'  => true,
					'redirection' => 3,
				)
			);

			if ( is_wp_error( $response ) ) {
				$broken[ $url ] = array(
					'status'   => 0,
					'message'  => $response->get_error_message(),
					'post_ids' => $post_ids,
				);
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			// 4xx and 5xx are broken; treat 0 as unknown error.
			if ( $status >= 400 || 0 === $status ) {
				$broken[ $url ] = array(
					'status'   => $status,
					'message'  => wp_remote_retrieve_response_message( $response ),
					'post_ids' => $post_ids,
				);
			}
		}

		// Persist results with the run timestamp.
		update_option(
			self::RESULTS_OPTION,
			array(
				'checked_at' => time(),
				'checked'    => count( $urls ),
				'broken'     => $broken,
				'dismissed'  => false,
			),
			false
		);
	}

	/**
	 * Collect up to MAX_URLS_PER_RUN unique external URLs from published posts.
	 *
	 * @return array<string, int[]> URL => [ post_id, ... ]
	 */
	private function collect_external_urls() {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

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

	/**
	 * Show an admin notice when broken links are found (unless dismissed).
	 */
	public function maybe_render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$results = get_option( self::RESULTS_OPTION );
		if ( ! is_array( $results ) || empty( $results['broken'] ) || ! empty( $results['dismissed'] ) ) {
			return;
		}

		$count    = count( $results['broken'] );
		$when     = human_time_diff( (int) $results['checked_at'], time() );
		$nonce    = wp_create_nonce( 'timu_elc_dismiss_notice' );
		$dismiss  = esc_url( admin_url( 'admin-ajax.php?action=timu_elc_dismiss_notice&_wpnonce=' . $nonce ) );

		printf(
			'<div class="notice notice-warning is-dismissible timu-elc-broken-notice" data-dismiss-url="%s"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_attr( $dismiss ),
			esc_html(
				sprintf(
					/* translators: %d: number of broken links found. */
					_n( 'External Link Control found %d broken link', 'External Link Control found %d broken links', $count, 'thisismyurl-external-link-control' ),
					$count
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: human-readable time delta, e.g. "2 days". */
					__( '%s ago.', 'thisismyurl-external-link-control' ),
					$when
				)
			),
			esc_url( admin_url( 'index.php#timu-elc-broken-links' ) ),
			esc_html__( 'View broken links', 'thisismyurl-external-link-control' )
		);
	}

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

		if ( ! is_array( $results ) ) {
			echo '<p>' . esc_html__( 'No scan has been run yet. A scan runs automatically every week.', 'thisismyurl-external-link-control' ) . '</p>';
			return;
		}

		$checked_at = isset( $results['checked_at'] ) ? (int) $results['checked_at'] : 0;
		$broken     = isset( $results['broken'] ) && is_array( $results['broken'] ) ? $results['broken'] : array();
		$checked    = isset( $results['checked'] ) ? (int) $results['checked'] : 0;

		echo '<p>';
		printf(
			/* translators: 1: number of URLs checked, 2: human-readable date. */
			esc_html__( 'Last scan: %1$d URLs checked on %2$s.', 'thisismyurl-external-link-control' ),
			(int) $checked,
			esc_html( wp_date( get_option( 'date_format' ), $checked_at ) )
		);
		echo '</p>';

		if ( empty( $broken ) ) {
			echo '<p>' . esc_html__( 'No broken links found.', 'thisismyurl-external-link-control' ) . '</p>';
			return;
		}

		echo '<p><strong>';
		printf(
			/* translators: %d: number of broken links. */
			esc_html( _n( '%d broken link found:', '%d broken links found:', count( $broken ), 'thisismyurl-external-link-control' ) ),
			(int) count( $broken )
		);
		echo '</strong></p>';
		echo '<ul id="timu-elc-broken-links">';

		foreach ( array_slice( $broken, 0, 20 ) as $url => $info ) {
			$status    = (int) $info['status'];
			$post_ids  = is_array( $info['post_ids'] ) ? $info['post_ids'] : array();
			$post_edit = ! empty( $post_ids )
				? admin_url( 'post.php?post=' . (int) $post_ids[0] . '&action=edit' )
				: '';

			printf(
				'<li><code>%s</code> &mdash; HTTP %s%s</li>',
				esc_html( $url ),
				$status ? esc_html( (string) $status ) : esc_html__( 'error', 'thisismyurl-external-link-control' ),
				$post_edit ? sprintf( ' (<a href="%s">%s</a>)', esc_url( $post_edit ), esc_html__( 'first post', 'thisismyurl-external-link-control' ) ) : ''
			);
		}

		if ( count( $broken ) > 20 ) {
			echo '<li><em>' . esc_html(
				sprintf(
					/* translators: %d: number of additional broken links not shown. */
					__( '…and %d more.', 'thisismyurl-external-link-control' ),
					count( $broken ) - 20
				)
			) . '</em></li>';
		}

		echo '</ul>';
	}

	/**
	 * AJAX: dismiss the admin notice by flagging results as dismissed.
	 */
	public function ajax_dismiss_notice() {
		check_ajax_referer( 'timu_elc_dismiss_notice' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		$results = get_option( self::RESULTS_OPTION );
		if ( is_array( $results ) ) {
			$results['dismissed'] = true;
			update_option( self::RESULTS_OPTION, $results, false );
		}

		wp_send_json_success();
	}
}
