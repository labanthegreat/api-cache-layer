<?php
/**
 * WP-CLI commands for API Cache Layer.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
	return;
}

/**
 * Manage the API Cache Layer plugin via WP-CLI.
 *
 * Provides commands for flushing, warming, inspecting, and managing
 * the REST API cache from the command line.
 *
 * ## EXAMPLES
 *
 *     # Flush all cached responses
 *     wp acl flush
 *
 *     # Flush a specific route
 *     wp acl flush --route=/wp/v2/posts
 *
 *     # Warm the cache
 *     wp acl warm
 *
 *     # Show cache statistics
 *     wp acl stats
 *
 *     # Show analytics for last 7 days
 *     wp acl analytics --period=7d
 *
 *     # List cached entries
 *     wp acl list
 *
 * @since 2.0.0
 * @package API_Cache_Layer
 */
class ACL_CLI {

	/**
	 * Cache manager instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Manager
	 */
	private Cache_Manager $cache_manager;

	/**
	 * Analytics instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Analytics
	 */
	private Cache_Analytics $analytics;

	/**
	 * Cache warmer instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Warmer
	 */
	private Cache_Warmer $warmer;

	/**
	 * Cache rules instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Rules
	 */
	private Cache_Rules $rules;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param Cache_Manager   $cache_manager Cache manager instance.
	 * @param Cache_Analytics $analytics     Analytics instance.
	 * @param Cache_Warmer    $warmer        Cache warmer instance.
	 * @param Cache_Rules     $rules         Cache rules instance.
	 */
	public function __construct(
		Cache_Manager $cache_manager,
		Cache_Analytics $analytics,
		Cache_Warmer $warmer,
		Cache_Rules $rules
	) {
		$this->cache_manager = $cache_manager;
		$this->analytics     = $analytics;
		$this->warmer        = $warmer;
		$this->rules         = $rules;
	}

	/**
	 * Flush all cached REST API responses or a specific route.
	 *
	 * ## OPTIONS
	 *
	 * [--route=<route>]
	 * : Flush only caches matching this route pattern.
	 *
	 * [--tag=<tag>]
	 * : Flush all caches with this tag.
	 *
	 * ## EXAMPLES
	 *
	 *     # Flush everything
	 *     wp acl flush
	 *
	 *     # Flush specific route
	 *     wp acl flush --route=/wp/v2/posts
	 *
	 *     # Flush by tag
	 *     wp acl flush --tag=posts
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function flush( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['tag'] ) ) {
			$count = $this->rules->invalidate_by_tag( $assoc_args['tag'] );
			\WP_CLI::success( sprintf( 'Flushed %d entries with tag "%s".', $count, $assoc_args['tag'] ) );
			return;
		}

		if ( ! empty( $assoc_args['route'] ) ) {
			$this->cache_manager->invalidate_by_route( $assoc_args['route'] );
			\WP_CLI::success( sprintf( 'Flushed caches matching route: %s', $assoc_args['route'] ) );
			return;
		}

		$count = $this->cache_manager->flush_all();
		\WP_CLI::success( sprintf( 'Flushed %d cached REST API responses.', $count ) );
	}

	/**
	 * Warm the cache by pre-populating responses for registered REST routes.
	 *
	 * ## OPTIONS
	 *
	 * [--routes=<routes>]
	 * : Comma-separated list of specific routes to warm.
	 *
	 * [--batch-size=<size>]
	 * : Number of routes to warm per batch. Default: 10.
	 *
	 * [--dry-run]
	 * : Show which routes would be warmed without actually warming them.
	 *
	 * ## EXAMPLES
	 *
	 *     # Warm all warmable routes
	 *     wp acl warm
	 *
	 *     # Warm specific routes
	 *     wp acl warm --routes=/wp/v2/posts,/wp/v2/pages
	 *
	 *     # Dry run
	 *     wp acl warm --dry-run
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function warm( array $args, array $assoc_args ): void {
		$batch_size = absint( $assoc_args['batch-size'] ?? 10 );

		if ( ! empty( $assoc_args['routes'] ) ) {
			$routes = array_map( 'trim', explode( ',', $assoc_args['routes'] ) );
		} else {
			$routes = $this->warmer->get_warmable_routes();
		}

		if ( empty( $routes ) ) {
			\WP_CLI::warning( 'No warmable routes found.' );
			return;
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			\WP_CLI::log( sprintf( 'Would warm %d routes:', count( $routes ) ) );
			foreach ( $routes as $route ) {
				\WP_CLI::log( '  ' . $route );
			}
			return;
		}

		\WP_CLI::log( sprintf( 'Warming %d routes (batch size: %d)...', count( $routes ), $batch_size ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Warming cache', count( $routes ) );
		$results  = array();
		$success  = 0;
		$failed   = 0;

		$batches = array_chunk( $routes, max( 1, $batch_size ) );

		foreach ( $batches as $batch ) {
			$batch_results = $this->warmer->warm_routes( $batch, count( $batch ) );

			foreach ( $batch_results as $route => $result ) {
				$results[] = array(
					'Route'   => $route,
					'Status'  => $result['success'] ? 'OK' : 'FAILED',
					'Time'    => isset( $result['time_ms'] ) ? $result['time_ms'] . 'ms' : '-',
					'Message' => $result['message'] ?? '',
				);

				if ( $result['success'] ) {
					++$success;
				} else {
					++$failed;
				}

				$progress->tick();
			}
		}

		$progress->finish();

		\WP_CLI\Utils\format_items( 'table', $results, array( 'Route', 'Status', 'Time', 'Message' ) );

		\WP_CLI::log( '' );
		\WP_CLI::success( sprintf( 'Warmed %d routes. Success: %d, Failed: %d.', count( $routes ), $success, $failed ) );
	}

	/**
	 * Display cache statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acl stats
	 *     wp acl stats --format=json
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function stats( array $args, array $assoc_args ): void {
		$stats  = $this->cache_manager->get_stats();
		$format = $assoc_args['format'] ?? 'table';

		$hit_rate = (float) $stats['hit_rate'];

		// Color-coded hit rate.
		if ( 'table' === $format ) {
			$rate_display = $hit_rate . '%';
			if ( $hit_rate >= 80 ) {
				$rate_display = \WP_CLI::colorize( '%G' . $rate_display . '%n' );
			} elseif ( $hit_rate >= 50 ) {
				$rate_display = \WP_CLI::colorize( '%Y' . $rate_display . '%n' );
			} else {
				$rate_display = \WP_CLI::colorize( '%R' . $rate_display . '%n' );
			}

			\WP_CLI::log( '' );
			\WP_CLI::log( \WP_CLI::colorize( '%BCache Statistics%n' ) );
			\WP_CLI::log( str_repeat( '-', 40 ) );
			\WP_CLI::log( sprintf( '  Hit Rate:        %s', $rate_display ) );
			\WP_CLI::log( sprintf( '  Cache Hits:      %s', number_format( $stats['hits'] ) ) );
			\WP_CLI::log( sprintf( '  Cache Misses:    %s', number_format( $stats['misses'] ) ) );
			\WP_CLI::log( sprintf( '  Total Cached:    %s', number_format( $stats['total_cached'] ) ) );

			if ( ! empty( $stats['last_flush'] ) ) {
				\WP_CLI::log( sprintf( '  Last Flush:      %s', wp_date( 'Y-m-d H:i:s', $stats['last_flush'] ) ) );
			}

			$settings = $this->cache_manager->get_settings();
			$backend  = $settings['storage_method'] ?? 'transient';
			\WP_CLI::log( sprintf( '  Storage Backend: %s', $backend ) );

			// Warmer status.
			$warmer_status = $this->warmer->get_status();
			\WP_CLI::log( sprintf( '  Warmer State:    %s', $warmer_status['state'] ) );

			$next = $this->warmer->get_next_scheduled();
			if ( $next ) {
				\WP_CLI::log( sprintf( '  Next Warm:       %s', wp_date( 'Y-m-d H:i:s', $next ) ) );
			}

			\WP_CLI::log( '' );
		} else {
			\WP_CLI\Utils\format_items( $format, array( $stats ), array_keys( $stats ) );
		}
	}

	/**
	 * Show analytics report for a time period.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Time period. Accepts: 1d, 7d, 30d, 90d. Default: 7d.
	 *
	 * [--top=<count>]
	 * : Number of top endpoints to show. Default: 10.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acl analytics
	 *     wp acl analytics --period=30d
	 *     wp acl analytics --period=7d --top=20
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function analytics( array $args, array $assoc_args ): void {
		$period_str = $assoc_args['period'] ?? '7d';
		$top_count  = absint( $assoc_args['top'] ?? 10 );
		$format     = $assoc_args['format'] ?? 'table';

		// Parse period string.
		$days = match ( $period_str ) {
			'1d'    => 1,
			'7d'    => 7,
			'30d'   => 30,
			'90d'   => 90,
			default => 7,
		};

		$summary = $this->analytics->get_summary( $days );

		if ( 'table' === $format ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( \WP_CLI::colorize( '%BAnalytics Summary (' . $period_str . ')%n' ) );
			\WP_CLI::log( str_repeat( '=', 50 ) );
			\WP_CLI::log( sprintf( '  Total Requests:     %s', number_format( $summary['total_requests'] ) ) );
			\WP_CLI::log( sprintf( '  Total Hits:         %s', number_format( $summary['total_hits'] ) ) );
			\WP_CLI::log( sprintf( '  Total Misses:       %s', number_format( $summary['total_misses'] ) ) );
			\WP_CLI::log( sprintf( '  Hit Rate:           %s%%', $summary['hit_rate'] ) );
			\WP_CLI::log( sprintf( '  Avg Cached Time:    %sms', $summary['avg_cached_time'] ) );
			\WP_CLI::log( sprintf( '  Avg Uncached Time:  %sms', $summary['avg_uncached_time'] ) );
			\WP_CLI::log( sprintf( '  Unique Routes:      %s', $summary['unique_routes'] ) );
			\WP_CLI::log( sprintf( '  Est. Time Saved:    %sms', number_format( $summary['time_saved_ms'] ) ) );

			// Top cached endpoints.
			\WP_CLI::log( '' );
			\WP_CLI::log( \WP_CLI::colorize( '%GTop ' . $top_count . ' Most Cached Endpoints:%n' ) );

			$top_cached = $this->analytics->get_top_cached( $top_count );
			if ( ! empty( $top_cached ) ) {
				$table_data = array();
				foreach ( $top_cached as $item ) {
					$total    = $item['hits'] + $item['misses'];
					$hit_rate = $total > 0 ? round( ( $item['hits'] / $total ) * 100, 1 ) : 0;

					$table_data[] = array(
						'Route'    => $item['route'],
						'Hits'     => number_format( $item['hits'] ),
						'Misses'   => number_format( $item['misses'] ),
						'Hit Rate' => $hit_rate . '%',
					);
				}
				\WP_CLI\Utils\format_items( 'table', $table_data, array( 'Route', 'Hits', 'Misses', 'Hit Rate' ) );
			} else {
				\WP_CLI::log( '  No data available.' );
			}

			// Top missed endpoints.
			\WP_CLI::log( '' );
			\WP_CLI::log( \WP_CLI::colorize( '%RTop ' . $top_count . ' Most Missed Endpoints:%n' ) );

			$top_missed = $this->analytics->get_top_missed( $top_count );
			if ( ! empty( $top_missed ) ) {
				$table_data = array();
				foreach ( $top_missed as $item ) {
					$table_data[] = array(
						'Route'  => $item['route'],
						'Misses' => number_format( $item['misses'] ),
						'Hits'   => number_format( $item['hits'] ),
					);
				}
				\WP_CLI\Utils\format_items( 'table', $table_data, array( 'Route', 'Misses', 'Hits' ) );
			} else {
				\WP_CLI::log( '  No data available.' );
			}

			// Response time comparison.
			\WP_CLI::log( '' );
			\WP_CLI::log( \WP_CLI::colorize( '%YResponse Time Comparison:%n' ) );

			$comparison = $this->analytics->get_response_time_comparison( $top_count );
			if ( ! empty( $comparison ) ) {
				$table_data = array();
				foreach ( $comparison as $item ) {
					$table_data[] = array(
						'Route'       => $item['route'],
						'Cached'      => $item['avg_cached'] . 'ms',
						'Uncached'    => $item['avg_uncached'] . 'ms',
						'Improvement' => $item['improvement'] . '%',
					);
				}
				\WP_CLI\Utils\format_items( 'table', $table_data, array( 'Route', 'Cached', 'Uncached', 'Improvement' ) );
			} else {
				\WP_CLI::log( '  No data available.' );
			}

			\WP_CLI::log( '' );
		} else {
			$data = array(
				'summary'        => $summary,
				'top_cached'     => $this->analytics->get_top_cached( $top_count ),
				'top_missed'     => $this->analytics->get_top_missed( $top_count ),
				'response_times' => $this->analytics->get_response_time_comparison( $top_count ),
			);

			if ( 'json' === $format ) {
				\WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			} else {
				\WP_CLI\Utils\format_items( $format, array( $summary ), array_keys( $summary ) );
			}
		}
	}

	/**
	 * List all cached entries with TTL remaining.
	 *
	 * ## OPTIONS
	 *
	 * [--route=<route>]
	 * : Filter by route pattern.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, csv, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acl list
	 *     wp acl list --route=/wp/v2/posts
	 *     wp acl list --format=json
	 *
	 * @since 2.0.0
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_entries( array $args, array $assoc_args ): void {
		$index  = $this->cache_manager->get_index();
		$format = $assoc_args['format'] ?? 'table';
		$filter = $assoc_args['route'] ?? '';

		if ( empty( $index ) ) {
			\WP_CLI::log( 'No cached entries found.' );
			return;
		}

		$entries    = array();
		$now        = time();
		$default_ttl = (int) ( $this->cache_manager->get_settings()['default_ttl'] ?? 3600 );

		foreach ( $index as $entry ) {
			$route = $entry['route'] ?? 'unknown';

			if ( ! empty( $filter ) && ! str_contains( $route, $filter ) ) {
				continue;
			}

			$cached_at = $entry['time'] ?? 0;
			$ttl       = (int) ( $entry['ttl'] ?? $default_ttl );
			$expires   = $cached_at + $ttl;
			$remaining = max( 0, $expires - $now );

			// Check if data still exists.
			$data   = $this->cache_manager->get_cached( $entry['key'] );
			$exists = false !== $data;
			$size   = $exists ? strlen( maybe_serialize( $data ) ) : 0;

			$entries[] = array(
				'Key'       => $entry['key'],
				'Route'     => $route,
				'Status'    => $exists ? 'active' : 'expired',
				'Size'      => $this->format_bytes( $size ),
				'Cached At' => $cached_at > 0 ? wp_date( 'Y-m-d H:i:s', $cached_at ) : '-',
				'TTL Left'  => $exists ? $this->format_duration( $remaining ) : 'expired',
			);
		}

		if ( empty( $entries ) ) {
			\WP_CLI::log( 'No entries match the filter.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $entries, array( 'Key', 'Route', 'Status', 'Size', 'Cached At', 'TTL Left' ) );

		if ( 'table' === $format ) {
			\WP_CLI::log( sprintf( "\nTotal entries: %d", count( $entries ) ) );
		}
	}

	/**
	 * Show the invalidation log.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<count>]
	 * : Number of log entries to show. Default: 20.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acl log
	 *     wp acl log --limit=50
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function log( array $args, array $assoc_args ): void {
		$limit  = absint( $assoc_args['limit'] ?? 20 );
		$format = $assoc_args['format'] ?? 'table';

		$entries = $this->analytics->get_invalidation_log( $limit );

		if ( empty( $entries ) ) {
			\WP_CLI::log( 'No invalidation log entries found.' );
			return;
		}

		$table_data = array();
		foreach ( $entries as $entry ) {
			$table_data[] = array(
				'Route'    => $entry['route'],
				'Reason'   => $entry['reason'],
				'Source'   => $entry['source'],
				'Cleared'  => $entry['entries_cleared'],
				'Time'     => $entry['created_at'],
			);
		}

		\WP_CLI\Utils\format_items( $format, $table_data, array( 'Route', 'Reason', 'Source', 'Cleared', 'Time' ) );
	}

	/**
	 * Manage cache rules.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform. Accepts: list, add, delete.
	 *
	 * [--route=<route>]
	 * : Route pattern for the rule (required for add).
	 *
	 * [--ttl=<ttl>]
	 * : TTL in seconds (for add).
	 *
	 * [--id=<id>]
	 * : Rule ID (for delete).
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acl rules list
	 *     wp acl rules add --route="/wp/v2/posts*" --ttl=7200
	 *     wp acl rules delete --id=abc-123
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function rules( array $args, array $assoc_args ): void {
		$action = $args[0] ?? 'list';
		$format = $assoc_args['format'] ?? 'table';

		switch ( $action ) {
			case 'list':
				$all_rules = $this->rules->get_rules();

				if ( empty( $all_rules ) ) {
					\WP_CLI::log( 'No cache rules configured.' );
					return;
				}

				$table_data = array();
				foreach ( $all_rules as $rule ) {
					$table_data[] = array(
						'ID'       => substr( $rule['id'], 0, 8 ),
						'Route'    => $rule['route_pattern'],
						'TTL'      => $rule['ttl'] . 's',
						'Enabled'  => $rule['enabled'] ? 'Yes' : 'No',
						'Tags'     => $rule['tags'] ?: '-',
						'Priority' => $rule['priority'],
					);
				}

				\WP_CLI\Utils\format_items( $format, $table_data, array( 'ID', 'Route', 'TTL', 'Enabled', 'Tags', 'Priority' ) );
				break;

			case 'add':
				if ( empty( $assoc_args['route'] ) ) {
					\WP_CLI::error( 'Please provide --route for the rule.' );
					return;
				}

				$rule_id = $this->rules->save_rule( array(
					'route_pattern' => $assoc_args['route'],
					'ttl'           => absint( $assoc_args['ttl'] ?? 3600 ),
					'enabled'       => true,
					'tags'          => $assoc_args['tags'] ?? '',
					'priority'      => absint( $assoc_args['priority'] ?? 10 ),
				) );

				\WP_CLI::success( sprintf( 'Rule created with ID: %s', $rule_id ) );
				break;

			case 'delete':
				if ( empty( $assoc_args['id'] ) ) {
					\WP_CLI::error( 'Please provide --id of the rule to delete.' );
					return;
				}

				// Try to match short ID.
				$all_rules = $this->rules->get_rules();
				$found_id  = null;

				foreach ( $all_rules as $rule ) {
					if ( str_starts_with( $rule['id'], $assoc_args['id'] ) ) {
						$found_id = $rule['id'];
						break;
					}
				}

				if ( ! $found_id ) {
					\WP_CLI::error( 'Rule not found.' );
					return;
				}

				$this->rules->delete_rule( $found_id );
				\WP_CLI::success( 'Rule deleted.' );
				break;

			default:
				\WP_CLI::error( 'Unknown action. Use: list, add, or delete.' );
		}
	}

	/**
	 * Format bytes into human-readable string.
	 *
	 * @since 2.0.0
	 *
	 * @param int $bytes Size in bytes.
	 * @return string Formatted size string (e.g., '1.5 KB').
	 */
	private function format_bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1048576 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return round( $bytes / 1048576, 2 ) . ' MB';
	}

	/**
	 * Format seconds into human-readable duration.
	 *
	 * @since 2.0.0
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration string (e.g., '5m', '1.5h').
	 */
	private function format_duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		if ( $seconds < 3600 ) {
			return round( $seconds / 60 ) . 'm';
		}
		return round( $seconds / 3600, 1 ) . 'h';
	}
}
