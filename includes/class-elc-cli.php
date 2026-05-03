<?php
/**
 * WP-CLI commands for External Link Control.
 *
 * Registered under `wp elc`. The `audit` command surveys the site for
 * external links and groups them by host; the `rewrite` command (with
 * `--dry-run`) shows what the link processor would change for a given
 * post or post type.
 *
 * @package TIMU_ELC
 * @since 0.6123
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

if ( ! class_exists( 'TIMU_ELC_CLI' ) ) {

	/**
	 * Manage and audit external link rewriting.
	 */
	class TIMU_ELC_CLI {

		/**
		 * Survey published posts for external links and report the host
		 * distribution.
		 *
		 * ## OPTIONS
		 *
		 * [--post_type=<post_type>]
		 * : Post type(s) to scan. Comma-separated; defaults to "post,page".
		 *
		 * [--per_page=<n>]
		 * : Batch size for paging through posts. Defaults to 200.
		 *
		 * [--limit=<n>]
		 * : Maximum number of posts to scan. Defaults to no limit.
		 *
		 * [--format=<format>]
		 * : Output format: table, csv, json, count. Defaults to table.
		 *
		 * ## EXAMPLES
		 *
		 *   wp elc audit
		 *   wp elc audit --post_type=post --limit=500 --format=csv
		 *
		 * @when after_wp_load
		 *
		 * @param array<int,string>     $args       Positional args (unused).
		 * @param array<string,string>  $assoc_args Flag args.
		 * @return void
		 */
		public function audit( $args, $assoc_args ) {
			unset( $args );

			$post_types = isset( $assoc_args['post_type'] )
				? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['post_type'] ) ) )
				: array( 'post', 'page' );
			$per_page   = max( 1, (int) ( isset( $assoc_args['per_page'] ) ? $assoc_args['per_page'] : 200 ) );
			$limit      = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
			$format     = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

			$by_host = array();
			$scanned = 0;
			$paged   = 1;

			$progress = WP_CLI\Utils\make_progress_bar( 'Scanning posts', 0 );

			while ( true ) {
				$query = new WP_Query(
					array(
						'post_type'              => $post_types,
						'post_status'            => 'publish',
						'posts_per_page'         => $per_page,
						'paged'                  => $paged,
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'fields'                 => 'all',
						'suppress_filters'       => true,
					)
				);

				if ( ! $query->have_posts() ) {
					break;
				}

				foreach ( $query->posts as $post ) {
					$scanned++;
					$progress->tick();

					$content = (string) $post->post_content;
					if ( '' === $content || false === strpos( $content, '<a ' ) ) {
						continue;
					}

					if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $m ) ) {
						foreach ( $m[1] as $href ) {
							if ( ! TIMU_ELC_Host::is_external( $href ) ) {
								continue;
							}
							$host = TIMU_ELC_Host::host_for_href( $href );
							if ( '' === $host ) {
								continue;
							}
							if ( ! isset( $by_host[ $host ] ) ) {
								$by_host[ $host ] = 0;
							}
							$by_host[ $host ]++;
						}
					}

					if ( $limit > 0 && $scanned >= $limit ) {
						break 2;
					}
				}

				$paged++;
			}

			$progress->finish();

			arsort( $by_host );

			$rows = array();
			foreach ( $by_host as $host => $count ) {
				$rule    = TIMU_ELC_Domain_Rules::match( $host );
				$rows[] = array(
					'host'      => $host,
					'count'     => $count,
					'rule'      => $rule ? 'yes' : 'no',
					'dofollow'  => $rule && ! empty( $rule['dofollow'] ) ? 'yes' : 'no',
					'sponsored' => $rule && ! empty( $rule['sponsored'] ) ? 'yes' : 'no',
					'target'    => $rule && '' !== $rule['target'] ? $rule['target'] : '-',
				);
			}

			WP_CLI\Utils\format_items(
				$format,
				$rows,
				array( 'host', 'count', 'rule', 'dofollow', 'sponsored', 'target' )
			);

			WP_CLI::success(
				sprintf(
					/* translators: 1: number of posts scanned, 2: number of unique external hosts found. */
					_x( 'Scanned %1$d posts. Found %2$d unique external hosts.', 'wp-cli output', 'thisismyurl-external-link-control' ),
					$scanned,
					count( $by_host )
				)
			);
		}

		/**
		 * Show what the link processor would change for one post,
		 * without persisting anything.
		 *
		 * ## OPTIONS
		 *
		 * <post_id>
		 * : Post ID to preview.
		 *
		 * [--dry-run]
		 * : Currently the only mode supported. Included for forward
		 *   compatibility and to make the no-op intent explicit at the
		 *   call site.
		 *
		 * ## EXAMPLES
		 *
		 *   wp elc rewrite 42 --dry-run
		 *
		 * @when after_wp_load
		 *
		 * @param array<int,string>     $args       Positional args.
		 * @param array<string,string>  $assoc_args Flag args.
		 * @return void
		 */
		public function rewrite( $args, $assoc_args ) {
			$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
			if ( $post_id <= 0 ) {
				WP_CLI::error( 'Provide a numeric post ID as the first argument.' );
			}

			$dry_run = isset( $assoc_args['dry-run'] );
			if ( ! $dry_run ) {
				WP_CLI::warning( 'Only --dry-run is currently supported. The plugin transforms at render time and never writes to wp_posts.' );
			}

			$post = get_post( $post_id );
			if ( ! ( $post instanceof WP_Post ) ) {
				WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
			}

			$processor = new TIMU_ELC_Link_Processor();
			if ( ! $processor->enabled() ) {
				WP_CLI::warning( 'Plugin is disabled (master switch off). Output below assumes it were enabled.' );
				$processor = new TIMU_ELC_Link_Processor( array( 'enabled' => 1, 'new_tab' => 1, 'nofollow' => 1 ) );
			}

			$before = (string) $post->post_content;
			$after  = $processor->transform( $before );

			if ( $before === $after ) {
				WP_CLI::success( 'No changes — the processor would leave this post untouched.' );
				return;
			}

			WP_CLI::log( '--- BEFORE ---' );
			WP_CLI::log( $before );
			WP_CLI::log( '--- AFTER ---' );
			WP_CLI::log( $after );
			WP_CLI::success( sprintf( 'Dry run complete. Post %d would be rewritten at render time only; the database is untouched.', $post_id ) );
		}
	}

	WP_CLI::add_command( 'elc', 'TIMU_ELC_CLI' );
}
