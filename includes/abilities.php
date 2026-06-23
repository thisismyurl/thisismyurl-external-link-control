<?php
/**
 * WP 7 Abilities API registration for This Is My URL - External Link Control.
 *
 * Exposes the broken-link checker's most recent scan as a discoverable,
 * REST/AI-invokable read-only report. The ability reads the stored results
 * of the last scan; it does not start a scan, so it returns instantly and
 * makes no outbound HTTP requests of its own.
 *
 * @package TIMU_ELC
 * @since   1.6149
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API unavailable (WordPress < 6.9).
		}

		wp_register_ability(
			'thisismyurl-external-link-control/scan-external-links',
			array(
				'label'               => __( 'Scan external links', 'thisismyurl-external-link-control' ),
				'description'         => __( 'Returns the most recent external-link scan: every broken or unverifiable link the weekly checker found, with its HTTP status and the posts it appears on. Reads stored results only — it reports the last scan\'s state, it does not start a new scan. Optionally filter to one post or to a single status bucket.', 'thisismyurl-external-link-control' ),
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Optional: return only links that appear on this post ID.', 'thisismyurl-external-link-control' ),
						),
						'status'  => array(
							'type'        => 'string',
							'enum'        => array( 'broken', 'unverified', 'all' ),
							'description' => __( 'Optional: filter by verdict. "broken" = gone (404/410) or dead host; "unverified" = answered but blocked the check (bot wall, rate limit, login, server error); "all" = both. Defaults to "all". Note: links that returned OK are not retained by the checker, so they never appear here.', 'thisismyurl-external-link-control' ),
						),
						'limit'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 500,
							'description' => __( 'Optional: maximum number of links to return. Defaults to 200. The summary counts always reflect the full result set, not the truncated list.', 'thisismyurl-external-link-control' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'links', 'summary' ),
					'properties'           => array(
						'links'   => array(
							'type'        => 'array',
							'description' => __( 'The links the last scan flagged, filtered by the input arguments.', 'thisismyurl-external-link-control' ),
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'url', 'post_id', 'status_code', 'is_broken', 'bucket' ),
								'properties'           => array(
									'url'         => array(
										'type'        => 'string',
										'description' => __( 'The external URL that was checked (lowercased, query/fragment stripped).', 'thisismyurl-external-link-control' ),
									),
									'post_id'     => array(
										'type'        => array( 'integer', 'null' ),
										'description' => __( 'The first post ID the URL appears on, or null if no post is recorded. See post_ids for the full list.', 'thisismyurl-external-link-control' ),
									),
									'post_ids'    => array(
										'type'        => 'array',
										'description' => __( 'Every post ID the URL appears on.', 'thisismyurl-external-link-control' ),
										'items'       => array( 'type' => 'integer' ),
									),
									'status_code' => array(
										'type'        => 'integer',
										'description' => __( 'The HTTP status code from the last check, or 0 when the request errored before a status was returned (e.g. DNS failure, timeout).', 'thisismyurl-external-link-control' ),
									),
									'message'     => array(
										'type'        => 'string',
										'description' => __( 'The checker\'s human-readable note for this link (HTTP reason phrase or the network error message).', 'thisismyurl-external-link-control' ),
									),
									'is_broken'   => array(
										'type'        => 'boolean',
										'description' => __( 'True when the link is genuinely gone (404/410 or a dead host). False for unverifiable links that merely blocked the check.', 'thisismyurl-external-link-control' ),
									),
									'bucket'      => array(
										'type'        => 'string',
										'enum'        => array( 'broken', 'unverified' ),
										'description' => __( 'Which bucket the checker placed this link in.', 'thisismyurl-external-link-control' ),
									),
								),
								'additionalProperties' => false,
							),
						),
						'summary' => array(
							'type'                 => 'object',
							'required'             => array( 'broken', 'unverified', 'total', 'returned', 'urls_checked', 'has_run' ),
							'description'          => __( 'Counts and timing for the last scan. Counts reflect the full result set, independent of the post_id/status/limit filters.', 'thisismyurl-external-link-control' ),
							'properties'           => array(
								'broken'       => array(
									'type'        => 'integer',
									'description' => __( 'Number of broken links found in the last scan.', 'thisismyurl-external-link-control' ),
								),
								'unverified'   => array(
									'type'        => 'integer',
									'description' => __( 'Number of unverifiable links found in the last scan.', 'thisismyurl-external-link-control' ),
								),
								'total'        => array(
									'type'        => 'integer',
									'description' => __( 'Broken plus unverified — every problem link the last scan retained.', 'thisismyurl-external-link-control' ),
								),
								'returned'     => array(
									'type'        => 'integer',
									'description' => __( 'Number of links in this response after the post_id/status/limit filters.', 'thisismyurl-external-link-control' ),
								),
								'urls_checked' => array(
									'type'        => 'integer',
									'description' => __( 'Total external URLs the last scan checked.', 'thisismyurl-external-link-control' ),
								),
								'checked_at'   => array(
									'type'        => array( 'integer', 'null' ),
									'description' => __( 'Unix timestamp of the last scan, or null if no scan has run yet.', 'thisismyurl-external-link-control' ),
								),
								'has_run'      => array(
									'type'        => 'boolean',
									'description' => __( 'False when no scan has ever run (the weekly cron has not fired and no manual scan was triggered).', 'thisismyurl-external-link-control' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input = array() ) {
					if ( ! class_exists( 'ELC_Link_Checker' ) ) {
						return new WP_Error(
							'timu_elc_unavailable',
							__( 'The External Link Control link checker is not available.', 'thisismyurl-external-link-control' ),
							array( 'status' => 500 )
						);
					}

					$input = is_array( $input ) ? $input : array();

					$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
					$status  = isset( $input['status'] ) ? (string) $input['status'] : 'all';
					if ( ! in_array( $status, array( 'broken', 'unverified', 'all' ), true ) ) {
						$status = 'all';
					}
					$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 200;
					$limit = max( 1, min( 500, $limit ) );

					// Single source of truth for the last scan's stored results.
					$checker = new ELC_Link_Checker();
					$report  = $checker->get_results();

					$links = $report['links'];

					if ( 'all' !== $status ) {
						$links = array_values(
							array_filter(
								$links,
								static function ( $link ) use ( $status ) {
									return $status === $link['bucket'];
								}
							)
						);
					}

					if ( $post_id > 0 ) {
						$links = array_values(
							array_filter(
								$links,
								static function ( $link ) use ( $post_id ) {
									return in_array( $post_id, $link['post_ids'], true );
								}
							)
						);
					}

					$total_after_filter = count( $links );
					if ( count( $links ) > $limit ) {
						$links = array_slice( $links, 0, $limit );
					}

					// Surface the first post ID alongside the full list, so simple
					// callers get a usable scalar without indexing into post_ids.
					$links = array_map(
						static function ( $link ) {
							$link['post_id'] = ! empty( $link['post_ids'] ) ? (int) $link['post_ids'][0] : null;
							return $link;
						},
						$links
					);

					return array(
						'links'   => $links,
						'summary' => array(
							'broken'       => (int) $report['broken_count'],
							'unverified'   => (int) $report['unverified_count'],
							'total'        => (int) $report['broken_count'] + (int) $report['unverified_count'],
							'returned'     => count( $links ),
							'urls_checked' => (int) $report['checked'],
							'checked_at'   => $report['checked_at'] > 0 ? (int) $report['checked_at'] : null,
							'has_run'      => $report['checked_at'] > 0,
						),
					);
				},
				'permission_callback' => static function (): bool {
					// Mirrors the cap the checker's admin notice, dashboard widget,
					// AJAX handlers, and the plugin's REST inventory route all use.
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}
);
