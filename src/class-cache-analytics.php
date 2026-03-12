<?php
/**
 * Detailed cache analytics with per-endpoint tracking.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cache_Analytics
 *
 * Tracks per-endpoint hit/miss rates, response times, cache sizes,
 * and stores trend data in a custom database table for persistence.
 *
 * @since 2.0.0
 * @package API_Cache_Layer
 */
class Cache_Analytics {

	/**
	 * Custom database table name (without prefix).
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const TABLE_NAME = 'acl_analytics';

	/**
	 * Invalidation log table name (without prefix).
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const LOG_TABLE_NAME = 'acl_invalidation_log';

	/**
	 * Option key for endpoint statistics.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const ENDPOINT_STATS_OPTION = 'acl_endpoint_stats';

	/**
	 * Maximum rows to delete per cleanup batch to avoid long locks.
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const CLEANUP_BATCH_SIZE = 5000;

	/**
	 * Full table name with prefix.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private string $table_name;

	/**
	 * Full log table name with prefix.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private string $log_table_name;

	/**
	 * Pending analytics events to batch-insert at shutdown.
	 *
	 * @since 2.0.0
	 * @var array<int, array>
	 */
	private array $pending_events = array();

	/**
	 * Pending endpoint stat updates to batch-write at shutdown.
	 *
	 * @since 2.0.0
	 * @var array<string, array>
	 */
	private array $pending_endpoint_stats = array();

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name     = $wpdb->prefix . self::TABLE_NAME;
		$this->log_table_name = $wpdb->prefix . self::LOG_TABLE_NAME;
	}

	/**
	 * Register hooks for scheduled cleanup and shutdown batching.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'acl_analytics_cleanup', array( $this, 'cleanup_old_data' ) );
		add_action( 'shutdown', array( $this, 'flush_pending' ) );

		if ( ! wp_next_scheduled( 'acl_analytics_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'acl_analytics_cleanup' );
		}
	}

	/**
	 * Create custom database tables on plugin activation.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$log_table_name  = $wpdb->prefix . self::LOG_TABLE_NAME;

		$sql_analytics = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			route VARCHAR(500) NOT NULL,
			event_type ENUM('hit','miss') NOT NULL,
			response_time_ms FLOAT NOT NULL DEFAULT 0,
			cache_size INT UNSIGNED NOT NULL DEFAULT 0,
			user_role VARCHAR(100) DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_route (route(191)),
			KEY idx_event_type (event_type),
			KEY idx_created_at (created_at),
			KEY idx_route_created (route(191), created_at)
		) {$charset_collate};";

		$sql_log = "CREATE TABLE {$log_table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			route VARCHAR(500) NOT NULL,
			reason VARCHAR(255) NOT NULL DEFAULT '',
			source VARCHAR(100) NOT NULL DEFAULT 'auto',
			entries_cleared INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_created_at (created_at),
			KEY idx_route (route(191))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_analytics );
		dbDelta( $sql_log );
	}

	/**
	 * Record a cache event (hit or miss) with timing data.
	 *
	 * Events are queued in memory and batch-inserted at shutdown
	 * to minimize per-request DB overhead.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route          The REST route.
	 * @param string $event_type     Either 'hit' or 'miss'.
	 * @param float  $response_time  Response time in milliseconds.
	 * @param int    $cache_size     Size of cached data in bytes.
	 * @return void
	 */
	public function record_event( string $route, string $event_type, float $response_time = 0.0, int $cache_size = 0 ): void {
		if ( ! in_array( $event_type, array( 'hit', 'miss' ), true ) ) {
			return;
		}

		$user_role = '';
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$roles = $user->roles;
			$user_role = ! empty( $roles ) ? reset( $roles ) : '';
		}

		$this->pending_events[] = array(
			'route'            => $route,
			'event_type'       => $event_type,
			'response_time_ms' => $response_time,
			'cache_size'       => $cache_size,
			'user_role'        => $user_role,
			'created_at'       => current_time( 'mysql' ),
		);

		$this->queue_endpoint_stat_update( $route, $event_type, $response_time, $cache_size );
	}

	/**
	 * Queue an endpoint stat update for batch processing.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route         The REST route.
	 * @param string $event_type    Either 'hit' or 'miss'.
	 * @param float  $response_time Response time in milliseconds.
	 * @param int    $cache_size    Cached data size in bytes.
	 * @return void
	 */
	private function queue_endpoint_stat_update( string $route, string $event_type, float $response_time, int $cache_size ): void {
		$route_key = md5( $route );

		if ( ! isset( $this->pending_endpoint_stats[ $route_key ] ) ) {
			$this->pending_endpoint_stats[ $route_key ] = array(
				'route'  => $route,
				'hits'   => 0,
				'misses' => 0,
				'time_cached'   => 0.0,
				'time_uncached' => 0.0,
				'count_cached'   => 0,
				'count_uncached' => 0,
				'cache_size'     => 0,
			);
		}

		$pending = &$this->pending_endpoint_stats[ $route_key ];

		if ( 'hit' === $event_type ) {
			++$pending['hits'];
			$pending['time_cached'] += $response_time;
			++$pending['count_cached'];
		} else {
			++$pending['misses'];
			$pending['time_uncached'] += $response_time;
			++$pending['count_uncached'];
		}

		if ( $cache_size > 0 ) {
			$pending['cache_size'] = $cache_size;
		}
	}

	/**
	 * Flush all pending events and stats to the database.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function flush_pending(): void {
		$this->flush_pending_events();
		$this->flush_pending_endpoint_stats();
	}

	/**
	 * Batch-insert queued analytics events.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function flush_pending_events(): void {
		if ( empty( $this->pending_events ) ) {
			return;
		}

		global $wpdb;

		$values       = array();
		$placeholders = array();

		foreach ( $this->pending_events as $event ) {
			$placeholders[] = '(%s, %s, %f, %d, %s, %s)';
			$values[]       = $event['route'];
			$values[]       = $event['event_type'];
			$values[]       = $event['response_time_ms'];
			$values[]       = $event['cache_size'];
			$values[]       = $event['user_role'];
			$values[]       = $event['created_at'];
		}

		$sql = "INSERT INTO {$this->table_name} (route, event_type, response_time_ms, cache_size, user_role, created_at) VALUES ";
		$sql .= implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( $sql, $values ) );

		$this->pending_events = array();
	}

	/**
	 * Apply queued endpoint stat deltas to the stored option.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function flush_pending_endpoint_stats(): void {
		if ( empty( $this->pending_endpoint_stats ) ) {
			return;
		}

		$all_stats = get_option( self::ENDPOINT_STATS_OPTION, array() );

		foreach ( $this->pending_endpoint_stats as $route_key => $pending ) {
			if ( ! isset( $all_stats[ $route_key ] ) ) {
				$all_stats[ $route_key ] = array(
					'route'               => $pending['route'],
					'hits'                => 0,
					'misses'              => 0,
					'total_time_cached'   => 0.0,
					'total_time_uncached' => 0.0,
					'count_cached'        => 0,
					'count_uncached'      => 0,
					'cache_size'          => 0,
					'last_accessed'       => 0,
				);
			}

			$stats = &$all_stats[ $route_key ];

			$stats['hits']                += $pending['hits'];
			$stats['misses']              += $pending['misses'];
			$stats['total_time_cached']   += $pending['time_cached'];
			$stats['total_time_uncached'] += $pending['time_uncached'];
			$stats['count_cached']        += $pending['count_cached'];
			$stats['count_uncached']      += $pending['count_uncached'];
			$stats['last_accessed']        = time();

			if ( $pending['cache_size'] > 0 ) {
				$stats['cache_size'] = $pending['cache_size'];
			}
		}

		update_option( self::ENDPOINT_STATS_OPTION, $all_stats, false );

		$this->pending_endpoint_stats = array();
	}

	/**
	 * Log an invalidation event.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route           Route pattern invalidated.
	 * @param string $reason          Reason for invalidation.
	 * @param string $source          Source of invalidation (auto, manual, cli, webhook).
	 * @param int    $entries_cleared Number of entries cleared.
	 * @return void
	 */
	public function log_invalidation( string $route, string $reason, string $source = 'auto', int $entries_cleared = 0 ): void {
		global $wpdb;

		$valid_sources = array( 'auto', 'manual', 'cli', 'webhook' );
		if ( ! in_array( $source, $valid_sources, true ) ) {
			$source = 'auto';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->log_table_name,
			array(
				'route'           => $route,
				'reason'          => $reason,
				'source'          => $source,
				'entries_cleared' => $entries_cleared,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Get per-endpoint statistics.
	 *
	 * @since 2.0.0
	 *
	 * @return array Array of endpoint stats, keyed by route hash.
	 */
	public function get_endpoint_stats(): array {
		return get_option( self::ENDPOINT_STATS_OPTION, array() );
	}

	/**
	 * Get top N most cached (most hits) endpoints.
	 *
	 * @since 2.0.0
	 *
	 * @param int $limit Number of results to return.
	 * @return array Sorted list of endpoint stats with highest hits first.
	 */
	public function get_top_cached( int $limit = 10 ): array {
		$stats = $this->get_endpoint_stats();

		usort( $stats, static fn( array $a, array $b ): int => $b['hits'] <=> $a['hits'] );

		return array_slice( $stats, 0, $limit );
	}

	/**
	 * Get top N most missed endpoints.
	 *
	 * @since 2.0.0
	 *
	 * @param int $limit Number of results to return.
	 * @return array Sorted list of endpoint stats with highest misses first.
	 */
	public function get_top_missed( int $limit = 10 ): array {
		$stats = $this->get_endpoint_stats();

		usort( $stats, static fn( array $a, array $b ): int => $b['misses'] <=> $a['misses'] );

		return array_slice( $stats, 0, $limit );
	}

	/**
	 * Get trend data for a given period.
	 *
	 * Queries the analytics database table and groups results by the
	 * specified time period for charting and reporting.
	 *
	 * @since 2.0.0
	 *
	 * @param string $period One of 'hourly', 'daily', 'weekly'.
	 * @param int    $limit  Number of data points to return.
	 * @return array List of trend data points in chronological order.
	 */
	public function get_trend_data( string $period = 'daily', int $limit = 30 ): array {
		global $wpdb;

		$limit = min( max( 1, $limit ), 365 );

		$group_format = match ( $period ) {
			'hourly' => '%Y-%m-%d %H:00:00',
			'weekly' => '%x-W%v',
			default  => '%Y-%m-%d',
		};

		$days_back = match ( $period ) {
			'hourly' => 2,
			'weekly' => $limit * 7,
			default  => $limit,
		};

		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days_back * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE_FORMAT(created_at, %s) AS period_key,
					SUM(CASE WHEN event_type = 'hit' THEN 1 ELSE 0 END) AS hits,
					SUM(CASE WHEN event_type = 'miss' THEN 1 ELSE 0 END) AS misses,
					AVG(CASE WHEN event_type = 'hit' THEN response_time_ms ELSE NULL END) AS avg_cached_time,
					AVG(CASE WHEN event_type = 'miss' THEN response_time_ms ELSE NULL END) AS avg_uncached_time,
					COUNT(*) AS total
				FROM {$this->table_name}
				WHERE created_at >= %s
				GROUP BY period_key
				ORDER BY period_key DESC
				LIMIT %d",
				$group_format,
				$since,
				$limit
			),
			ARRAY_A
		);

		return array_reverse( $results ?: array() );
	}

	/**
	 * Get response time comparison (cached vs uncached) by endpoint.
	 *
	 * @since 2.0.0
	 *
	 * @param int $limit Number of endpoints to return.
	 * @return array Sorted list of endpoints with cached/uncached timing comparison.
	 */
	public function get_response_time_comparison( int $limit = 10 ): array {
		$stats   = $this->get_endpoint_stats();
		$results = array();

		foreach ( $stats as $stat ) {
			$avg_cached   = $stat['count_cached'] > 0 ? $stat['total_time_cached'] / $stat['count_cached'] : 0;
			$avg_uncached = $stat['count_uncached'] > 0 ? $stat['total_time_uncached'] / $stat['count_uncached'] : 0;
			$improvement  = $avg_uncached > 0 ? round( ( 1 - $avg_cached / $avg_uncached ) * 100, 1 ) : 0;

			$results[] = array(
				'route'         => $stat['route'],
				'avg_cached'    => round( $avg_cached, 2 ),
				'avg_uncached'  => round( $avg_uncached, 2 ),
				'improvement'   => $improvement,
				'total_hits'    => $stat['hits'],
				'total_misses'  => $stat['misses'],
			);
		}

		usort( $results, static fn( array $a, array $b ): int => ( $b['total_hits'] + $b['total_misses'] ) <=> ( $a['total_hits'] + $a['total_misses'] ) );

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Get cache size per endpoint, sorted by size descending.
	 *
	 * @since 2.0.0
	 *
	 * @return array List of endpoints with route and size in bytes.
	 */
	public function get_cache_sizes(): array {
		$stats   = $this->get_endpoint_stats();
		$results = array();

		foreach ( $stats as $stat ) {
			$results[] = array(
				'route' => $stat['route'],
				'size'  => $stat['cache_size'],
			);
		}

		usort( $results, static fn( array $a, array $b ): int => $b['size'] <=> $a['size'] );

		return $results;
	}

	/**
	 * Get overall analytics summary for a time period.
	 *
	 * @since 2.0.0
	 *
	 * @param int $days Number of days to look back.
	 * @return array Summary statistics including hit rate, timing, and time saved.
	 */
	public function get_summary( int $days = 7 ): array {
		global $wpdb;

		$days  = min( max( 1, $days ), 365 );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$empty_summary = array(
			'total_requests'    => 0,
			'total_hits'        => 0,
			'total_misses'      => 0,
			'hit_rate'          => 0,
			'avg_cached_time'   => 0,
			'avg_uncached_time' => 0,
			'unique_routes'     => 0,
			'time_saved_ms'     => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_requests,
					SUM(CASE WHEN event_type = 'hit' THEN 1 ELSE 0 END) AS total_hits,
					SUM(CASE WHEN event_type = 'miss' THEN 1 ELSE 0 END) AS total_misses,
					AVG(CASE WHEN event_type = 'hit' THEN response_time_ms ELSE NULL END) AS avg_cached_time,
					AVG(CASE WHEN event_type = 'miss' THEN response_time_ms ELSE NULL END) AS avg_uncached_time,
					COUNT(DISTINCT route) AS unique_routes
				FROM {$this->table_name}
				WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return $empty_summary;
		}

		$total    = (int) $row['total_requests'];
		$hits     = (int) $row['total_hits'];
		$hit_rate = $total > 0 ? round( ( $hits / $total ) * 100, 1 ) : 0;

		$avg_cached   = (float) ( $row['avg_cached_time'] ?? 0 );
		$avg_uncached = (float) ( $row['avg_uncached_time'] ?? 0 );
		$time_saved   = $hits * max( 0, $avg_uncached - $avg_cached );

		return array(
			'total_requests'    => $total,
			'total_hits'        => $hits,
			'total_misses'      => (int) $row['total_misses'],
			'hit_rate'          => $hit_rate,
			'avg_cached_time'   => round( $avg_cached, 2 ),
			'avg_uncached_time' => round( $avg_uncached, 2 ),
			'unique_routes'     => (int) $row['unique_routes'],
			'time_saved_ms'     => round( $time_saved, 0 ),
		);
	}

	/**
	 * Get invalidation log entries ordered by most recent first.
	 *
	 * @since 2.0.0
	 *
	 * @param int $limit Number of entries to return.
	 * @return array List of invalidation log rows.
	 */
	public function get_invalidation_log( int $limit = 50 ): array {
		global $wpdb;

		$limit = min( max( 1, $limit ), 500 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->log_table_name} ORDER BY created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Export analytics data as CSV-compatible array.
	 *
	 * @since 2.0.0
	 *
	 * @param int $days Number of days to export.
	 * @return array {
	 *     CSV-compatible data.
	 *
	 *     @type array $headers Column header strings.
	 *     @type array $rows    Data rows from the analytics table.
	 * }
	 */
	public function export_csv_data( int $days = 30 ): array {
		global $wpdb;

		$days  = min( max( 1, $days ), 365 );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT route, event_type, response_time_ms, cache_size, user_role, created_at
				FROM {$this->table_name}
				WHERE created_at >= %s
				ORDER BY created_at DESC
				LIMIT 50000",
				$since
			),
			ARRAY_A
		);

		return array(
			'headers' => array( 'Route', 'Event Type', 'Response Time (ms)', 'Cache Size (bytes)', 'User Role', 'Created At' ),
			'rows'    => $rows ?: array(),
		);
	}

	/**
	 * Clean up analytics data older than 90 days in bounded batches.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function cleanup_old_data(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE created_at < %s ORDER BY id ASC LIMIT %d",
				$cutoff,
				self::CLEANUP_BATCH_SIZE
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->log_table_name} WHERE created_at < %s ORDER BY id ASC LIMIT %d",
				$cutoff,
				self::CLEANUP_BATCH_SIZE
			)
		);
	}

	/**
	 * Reset all analytics data by truncating tables and clearing options.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function reset(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$this->log_table_name}" );
		update_option( self::ENDPOINT_STATS_OPTION, array() );
	}

	/**
	 * Get routes sorted by access frequency (for cache warmer priority).
	 *
	 * @since 2.0.0
	 *
	 * @param int $limit Maximum routes to return.
	 * @return array Array of routes sorted by total accesses descending.
	 */
	public function get_routes_by_popularity( int $limit = 50 ): array {
		$stats   = $this->get_endpoint_stats();
		$results = array();

		foreach ( $stats as $stat ) {
			$results[] = array(
				'route'   => $stat['route'],
				'total'   => $stat['hits'] + $stat['misses'],
				'hits'    => $stat['hits'],
				'misses'  => $stat['misses'],
			);
		}

		usort( $results, static fn( array $a, array $b ): int => $b['total'] <=> $a['total'] );

		return array_slice( $results, 0, $limit );
	}
}
