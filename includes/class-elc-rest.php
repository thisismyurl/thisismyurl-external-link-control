<?php
/**
 * REST API endpoint: GET /timu-elc/v1/inventory
 *
 * Returns a capability-gated inventory of every external domain found
 * across published post content — domain name, link count, and a sample
 * of post IDs where the domain appears.
 *
 * Route:      GET /wp-json/timu-elc/v1/inventory
 * Capability: manage_options
 *
 * Query params:
 *   - limit (int, default 100): max number of domains to return.
 *   - orderby (string: count|domain, default count): sort column.
 *   - order  (string: asc|desc, default desc): sort direction.
 *
 * @package TIMU_ELC
 * @since   0.6124
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the /timu-elc/v1/inventory REST route.
 *
 * Hooked on rest_api_init with a late priority so all post types are
 * registered before the query runs.
 *
 * @return void
 */
function timu_elc_register_rest_routes() {
    register_rest_route(
        'timu-elc/v1',
        '/inventory',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'timu_elc_rest_inventory',
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'args' => array(
                'limit'   => array(
                    'default'           => 100,
                    'sanitize_callback' => function ( $v ) {
                        $v = (int) $v;
                        return max( 1, min( 500, $v ) );
                    },
                ),
                'orderby' => array(
                    'default'           => 'count',
                    'sanitize_callback' => function ( $v ) {
                        return in_array( $v, array( 'count', 'domain' ), true ) ? $v : 'count';
                    },
                ),
                'order'   => array(
                    'default'           => 'desc',
                    'sanitize_callback' => function ( $v ) {
                        return 'asc' === strtolower( (string) $v ) ? 'asc' : 'desc';
                    },
                ),
            ),
        )
    );
}
add_action( 'rest_api_init', 'timu_elc_register_rest_routes' );

/**
 * Inventory callback.
 *
 * Queries all published post content for external links using a simple
 * pattern-based approach (DOMDocument is too slow on full table scans;
 * regex over href gives us domain extraction at acceptable speed and is
 * accurate enough for an inventory report).
 *
 * Results are cached in a transient for 1 hour so repeated calls during
 * an audit session don't hammer the database.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response
 */
function timu_elc_rest_inventory( WP_REST_Request $request ) {
    $limit   = (int) $request->get_param( 'limit' );
    $orderby = (string) $request->get_param( 'orderby' );
    $order   = (string) $request->get_param( 'order' );

    $cache_key = 'timu_elc_inventory_' . md5( $limit . $orderby . $order );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return rest_ensure_response( $cached );
    }

    /** @var \wpdb $wpdb */
    global $wpdb;

    // Pull published post content in batches to avoid OOM on large sites.
    $batch_size = 200;
    $offset     = 0;
    $home_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

    /** @var array<string, array{count: int, post_ids: int[]}> $domains */
    $domains = array();

    do {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type   NOT IN ('revision', 'attachment', 'nav_menu_item')
                   AND post_content LIKE %s
                 LIMIT %d OFFSET %d",
                '%href=%',
                $batch_size,
                $offset
            )
        );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            break;
        }

        foreach ( $rows as $row ) {
            if ( ! is_object( $row ) || ! isset( $row->post_content, $row->ID ) ) {
                continue;
            }
            $post_id = (int) $row->ID;
            $html    = (string) $row->post_content;

            // Extract href values.
            preg_match_all(
                '/href\s*=\s*["\']([^"\']+)["\']/',
                $html,
                $matches
            );

            if ( empty( $matches[1] ) || ! is_array( $matches[1] ) ) {
                continue;
            }

            foreach ( $matches[1] as $url ) {
                $url = trim( (string) $url );
                if ( '' === $url || '#' === $url[0] || 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) ) {
                    continue;
                }

                $parsed = wp_parse_url( $url );
                if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
                    // Relative URL — internal.
                    continue;
                }

                $host = strtolower( (string) $parsed['host'] );
                // Strip www. for normalisation.
                $host = (string) preg_replace( '/^www\./i', '', $host );

                // Skip internal links.
                $home_bare = (string) preg_replace( '/^www\./i', '', strtolower( $home_host ) );
                if ( $host === $home_bare ) {
                    continue;
                }

                if ( ! isset( $domains[ $host ] ) ) {
                    $domains[ $host ] = array(
                        'count'    => 0,
                        'post_ids' => array(),
                    );
                }
                $domains[ $host ]['count']++;
                if ( count( $domains[ $host ]['post_ids'] ) < 5 && ! in_array( $post_id, $domains[ $host ]['post_ids'], true ) ) {
                    $domains[ $host ]['post_ids'][] = $post_id;
                }
            }
        }

        $offset += $batch_size;
    } while ( count( $rows ) === $batch_size );

    // Sort.
    if ( 'domain' === $orderby ) {
        if ( 'asc' === $order ) {
            ksort( $domains );
        } else {
            krsort( $domains );
        }
    } else {
        uasort(
            $domains,
            function ( $a, $b ) use ( $order ) {
                return 'asc' === $order ? $a['count'] - $b['count'] : $b['count'] - $a['count'];
            }
        );
    }

    // Build response, capped at $limit domains.
    $items = array();
    $i     = 0;
    foreach ( $domains as $domain => $data ) {
        if ( $i >= $limit ) {
            break;
        }
        $items[] = array(
            'domain'       => $domain,
            'link_count'   => $data['count'],
            'sample_posts' => $data['post_ids'],
        );
        $i++;
    }

    $response = array(
        'domain_count' => count( $domains ),
        'returned'     => count( $items ),
        'domains'      => $items,
    );

    set_transient( $cache_key, $response, HOUR_IN_SECONDS );
    return rest_ensure_response( $response );
}
