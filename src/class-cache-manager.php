<?php
/**
 * Core cache manager for REST API responses.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cache_Manager
 *
 * Intercepts REST API responses, caches them using transients, object cache,
 * or Redis/Memcached backends. Supports ETag/conditional requests, gzip
 * compression, cache metadata, and automatic TTL adjustment.
 *
 * @since 1.0.0
 * @package API_Cache_Layer
 */
class Cache_Manager {

	/**
	 * Transient prefix for all cache keys.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CACHE_PREFIX = 'acl_cache_';

	/**
	 * Option key that stores the index of all active cache keys.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const INDEX_OPTION = 'acl_cache_index';

	/**
	 * Option key for cache hit statistics.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const STATS_OPTION = 'acl_cache_stats';

	/**
	 * Option key for access pattern tracking (used for adaptive TTL).
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const ACCESS_PATTERNS_OPTION = 'acl_access_patterns';

	/**
	 * Plugin settings.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $settings;

	/**
	 * Analytics instance (set externally after construction).
	 *
	 * @since 2.0.0
	 * @var Cache_Analytics|null
	 */
	private ?Cache_Analytics $analytics = null;

	/**
	 * Cache rules instance (set externally after construction).
	 *
	 * @since 2.0.0
	 * @var Cache_Rules|null
	 */
	private ?Cache_Rules $rules = null;

	/**
	 * Request start time for response timing.
	 *
	 * @since 2.0.0
	 * @var float
	 */
	private float $request_start = 0.0;

	/**
	 * Whether external object cache is active (computed once).
	 *
	 * @since 2.0.0
	 * @var bool|null
	 */
	private ?bool $use_object_cache = null;

	/**
	 * In-memory cache index to avoid repeated get_option calls.
	 *
	 * @since 2.0.0
	 * @var array|null
	 */
	private ?array $cached_index = null;

	/**
	 * Whether the index has been modified and needs persisting.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	private bool $index_dirty = false;

	/**
	 * Pending stat increments batched for shutdown.
	 *
	 * @since 2.0.0
	 * @var array<string, int>
	 */
	private array $pending_stats = array( 'hits' => 0, 'misses' => 0 );

	/**
	 * Pending access pattern updates batched for shutdown.
	 *
	 * @since 2.0.0
	 * @var array<string, string>
	 */
	private array $pending_access_routes = array();

	/**
	 * Cached access patterns to avoid repeated get_option calls.
	 *
	 * @since 3.1.0
	 * @var array|null
	 */
	private ?array $cached_access_patterns = null;

	/**
	 * Compiled exclusion regex patterns (cached per request).
	 *
	 * @since 2.0.0
	 * @var array|null
	 */
	private ?array $exclusion_patterns = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->settings = wp_parse_args(
			get_option( 'acl_settings', array() ),
			$this->get_defaults()
		);
	}

	/**
	 * Set the analytics instance.
	 *
	 * @since 2.0.0
	 *
	 * @param Cache_Analytics $analytics Analytics instance.
	 * @return void
	 */
	public function set_analytics( Cache_Analytics $analytics ): void {
		$this->analytics = $analytics;
	}

	/**
	 * Set the cache rules instance.
	 *
	 * @since 2.0.0
	 *
	 * @param Cache_Rules $rules Cache rules instance.
	 * @return void
	 */
	public function set_rules( Cache_Rules $rules ): void {
		$this->rules = $rules;
	}

	/**
	 * Register WordPress hooks for cache interception.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		add_filter( 'rest_pre_dispatch', array( $this, 'serve_cached_response' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'cache_response' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'persist_deferred_writes' ) );
	}

	/**
	 * Return default settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default plugin settings keyed by setting name.
	 */
	public function get_defaults(): array {
		return array(
			'enabled'            => false,
			'default_ttl'        => 3600,
			'excluded_endpoints' => '',
			'storage_method'     => 'transient',
			'compression'        => false,
			'etag_support'       => true,
			'adaptive_ttl'       => false,
			'max_entries'        => 1000,
		);
	}

	/**
	 * Serve a cached response if one exists, short-circuiting the REST dispatch.
	 *
	 * Hooked to `rest_pre_dispatch`. Returns a cached WP_REST_Response on hit,
	 * handles ETag/304 responses, and serves stale data when available.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Full details about the request.
	 * @return mixed WP_REST_Response on cache hit, or original $result on miss.
	 */
	public function serve_cached_response( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		$this->request_start = microtime( true );

		if ( 'GET' !== $request->get_method() || null !== $result ) {
			return $result;
		}

		// Never cache authenticated requests.
		if ( is_user_logged_in() && ! apply_filters( 'acl_cache_authenticated', false, $request ) ) {
			return $result;
		}

		$route = $request->get_route();

		if ( $this->is_excluded( $route ) ) {
			return $result;
		}

		if ( ! apply_filters( 'acl_should_cache', true, $request ) ) {
			return $result;
		}

		$cache_key = $this->generate_cache_key( $request );
		$cached    = $this->get_cached( $cache_key );

		if ( false !== $cached ) {
			$elapsed = ( microtime( true ) - $this->request_start ) * 1000;
			$this->record_stat( 'hits' );

			if ( $this->analytics ) {
				$size = strlen( maybe_serialize( $cached ) );
				$this->analytics->record_event( $route, 'hit', $elapsed, $size );
			}

			$this->record_access_pattern( $route );

			$response = $this->build_response_from_cache( $cached );
			$response->header( 'X-ACL-Cache', 'HIT' );

			if ( ! empty( $this->settings['etag_support'] ) && ! empty( $cached['etag'] ) ) {
				$response->header( 'ETag', $cached['etag'] );

				$if_none_match = $request->get_header( 'If-None-Match' );
				if ( $if_none_match && $if_none_match === $cached['etag'] ) {
					return new \WP_REST_Response( null, 304 );
				}
			}

			if ( ! empty( $cached['metadata'] ) ) {
				$response->header( 'X-ACL-Cached-At', (string) ( $cached['metadata']['created'] ?? '' ) );
				$response->header( 'X-ACL-Cache-Size', (string) ( $cached['metadata']['size'] ?? '' ) );
			}

			return $response;
		}

		if ( $this->rules ) {
			$stale = $this->rules->get_stale_data( $cache_key, $request );
			if ( $stale ) {
				$this->record_stat( 'hits' );

				$response = $this->build_response_from_cache( $stale );
				$response->header( 'X-ACL-Cache', 'STALE' );

				wp_schedule_single_event( time(), 'acl_revalidate_cache', array( $cache_key, $route ) );

				return $response;
			}
		}

		$this->record_stat( 'misses' );

		if ( $this->analytics ) {
			$this->analytics->record_event( $route, 'miss', 0, 0 );
		}

		return $result;
	}

	/**
	 * Cache a REST API response after dispatch.
	 *
	 * Hooked to `rest_post_dispatch`. Stores successful GET responses in the
	 * configured cache backend with compression, ETag, and metadata support.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Response $result  Response object.
	 * @param WP_REST_Server   $server  Server instance.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @return WP_REST_Response The response with added cache headers.
	 */
	public function cache_response( \WP_REST_Response $result, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() ) {
			return $result;
		}

		$status = $result->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return $result;
		}

		$route = $request->get_route();

		if ( $this->is_excluded( $route ) ) {
			return $result;
		}

		if ( ! apply_filters( 'acl_should_cache', true, $request ) ) {
			return $result;
		}

		$headers = $result->get_headers();
		if ( ! empty( $headers['X-ACL-Cache'] ) && in_array( $headers['X-ACL-Cache'], array( 'HIT', 'STALE' ), true ) ) {
			return $result;
		}

		$ttl = $this->get_ttl_for_route( $route );

		if ( ! empty( $this->settings['adaptive_ttl'] ) ) {
			$ttl = $this->adjust_ttl_by_access_pattern( $route, $ttl );
		}

		$body         = $result->get_data();
		$serialized   = maybe_serialize( $body );
		$is_compressed = false;

		if ( ! empty( $this->settings['compression'] ) && strlen( $serialized ) > 1024 ) {
			$compressed = gzcompress( $serialized, 6 );
			if ( false !== $compressed ) {
				$body          = $compressed;
				$is_compressed = true;
			}
		}

		$etag = '';
		if ( ! empty( $this->settings['etag_support'] ) ) {
			$etag = '"' . md5( $serialized ) . '"';
		}

		$data = array(
			'body'       => $body,
			'status'     => $status,
			'headers'    => $headers,
			'compressed' => $is_compressed,
			'etag'       => $etag,
			'metadata'   => array(
				'created'  => time(),
				'accessed' => time(),
				'size'     => strlen( $serialized ),
				'route'    => $route,
			),
		);

		$cache_key = $this->generate_cache_key( $request );
		$this->set_cached( $cache_key, $data, $ttl );
		$this->update_index_entry( $cache_key, $route, $ttl );

		if ( $this->rules ) {
			$this->rules->store_stale_copy( $cache_key, $data, $request );
			$this->rules->tag_cache_entry( $cache_key, $route );
		}

		$result->header( 'X-ACL-Cache', 'MISS' );
		$result->header( 'X-ACL-Cache-TTL', (string) $ttl );

		if ( $etag ) {
			$result->header( 'ETag', $etag );
		}

		return $result;
	}

	/**
	 * Build a WP_REST_Response from cached data, handling decompression.
	 *
	 * @since 2.0.0
	 *
	 * @param array $cached Cached data array with body, status, headers, compressed flag.
	 * @return WP_REST_Response Reconstructed response.
	 */
	private function build_response_from_cache( array $cached ): \WP_REST_Response {
		$body = $cached['body'];

		if ( ! empty( $cached['compressed'] ) && is_string( $body ) ) {
			$decompressed = @gzuncompress( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $decompressed ) {
				$body = maybe_unserialize( $decompressed );
			}
		}

		$response = new \WP_REST_Response( $body, $cached['status'] );

		if ( ! empty( $cached['headers'] ) && is_array( $cached['headers'] ) ) {
			foreach ( $cached['headers'] as $key => $value ) {
				$response->header( $key, $value );
			}
		}

		return $response;
	}

	/**
	 * Check whether the object cache backend should be used.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if object cache is configured and available.
	 */
	private function should_use_object_cache(): bool {
		if ( null === $this->use_object_cache ) {
			$this->use_object_cache = 'object_cache' === $this->settings['storage_method']
				&& wp_using_ext_object_cache();
		}
		return $this->use_object_cache;
	}

	/**
	 * Retrieve a cached value from the configured storage backend.
	 *
	 * @since 1.0.0
	 *
	 * @param string $cache_key Full cache key.
	 * @return mixed Cached data array on hit, or false on miss.
	 */
	public function get_cached( string $cache_key ): mixed {
		if ( $this->should_use_object_cache() ) {
			return wp_cache_get( $cache_key, 'acl' );
		}

		return get_transient( $cache_key );
	}

	/**
	 * Store a value in the configured cache backend.
	 *
	 * @since 1.0.0
	 *
	 * @param string $cache_key Full cache key.
	 * @param mixed  $data      Data to cache.
	 * @param int    $ttl       Time to live in seconds.
	 * @return void
	 */
	public function set_cached( string $cache_key, mixed $data, int $ttl ): void {
		if ( $this->should_use_object_cache() ) {
			wp_cache_set( $cache_key, $data, 'acl', $ttl );
		} else {
			set_transient( $cache_key, $data, $ttl );
		}
	}

	/**
	 * Delete a value from the configured cache backend.
	 *
	 * @since 2.0.0
	 *
	 * @param string $cache_key Full cache key.
	 * @return void
	 */
	private function delete_cached( string $cache_key ): void {
		if ( $this->should_use_object_cache() ) {
			wp_cache_delete( $cache_key, 'acl' );
		} else {
			delete_transient( $cache_key );
		}
	}

	/**
	 * Invalidate a single cache key and its stale copy.
	 *
	 * @since 1.0.0
	 *
	 * @param string $cache_key Full cache key.
	 * @return void
	 */
	public function invalidate( string $cache_key ): void {
		$this->delete_cached( $cache_key );
		$this->delete_cached( $cache_key . '_stale' );
		$this->remove_from_index( $cache_key );
	}

	/**
	 * Invalidate all cache entries whose key contains a given route fragment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $route_fragment Partial route string to match against stored keys.
	 * @return int Number of entries invalidated.
	 */
	public function invalidate_by_route( string $route_fragment ): int {
		$index = $this->get_index();
		$count = 0;

		foreach ( $index as $entry ) {
			if ( ! empty( $entry['route'] ) && str_contains( $entry['route'], $route_fragment ) ) {
				$this->delete_cached( $entry['key'] );
				$this->delete_cached( $entry['key'] . '_stale' );
				++$count;
			}
		}

		if ( $count > 0 ) {
			$this->cached_index = array_values(
				array_filter( $index, static fn( array $entry ): bool =>
					empty( $entry['route'] ) || ! str_contains( $entry['route'], $route_fragment )
				)
			);
			$this->index_dirty = true;
		}

		return $count;
	}

	/**
	 * Flush every cached REST response and reset statistics.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of entries flushed.
	 */
	public function flush_all(): int {
		$index = $this->get_index();
		$count = count( $index );

		foreach ( $index as $entry ) {
			$this->delete_cached( $entry['key'] );
			$this->delete_cached( $entry['key'] . '_stale' );
		}

		$this->cached_index = array();
		$this->index_dirty  = false;
		update_option( self::INDEX_OPTION, array(), false );
		update_option( self::STATS_OPTION, array(
			'hits'       => 0,
			'misses'     => 0,
			'last_flush' => time(),
		), false );

		/** Fires after all caches have been flushed. */
		do_action( 'acl_cache_flushed' );

		return $count;
	}

	/**
	 * Generate a deterministic cache key from a request.
	 *
	 * Applies vary-by rules via the acl_cache_key_parts filter.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return string MD5-based cache key with the CACHE_PREFIX.
	 */
	public function generate_cache_key( \WP_REST_Request $request ): string {
		$route  = $request->get_route();
		$params = $request->get_query_params();

		ksort( $params );

		$parts = array(
			'route'  => $route,
			'params' => http_build_query( $params ),
		);

		/**
		 * Filter cache key components for vary-by support.
		 *
		 * @param array           $parts   Cache key parts.
		 * @param WP_REST_Request $request The REST request.
		 */
		$parts = apply_filters( 'acl_cache_key_parts', $parts, $request );

		ksort( $parts );
		$raw = implode( '|', array_map(
			static fn( string $k, mixed $v ): string => $k . '=' . ( is_array( $v ) ? wp_json_encode( $v ) : (string) $v ),
			array_keys( $parts ),
			array_values( $parts )
		) );

		return self::CACHE_PREFIX . md5( $raw );
	}

	/**
	 * Get the TTL for a specific route, falling back to the global default.
	 *
	 * @since 1.0.0
	 *
	 * @param string $route REST route.
	 * @return int TTL in seconds.
	 */
	public function get_ttl_for_route( string $route ): int {
		/**
		 * Filter the cache TTL for a specific REST route.
		 *
		 * @param int    $ttl   TTL in seconds.
		 * @param string $route The REST API route.
		 */
		return (int) apply_filters( 'acl_cache_ttl', (int) $this->settings['default_ttl'], $route );
	}

	/**
	 * Check whether a route is in the exclusion list.
	 *
	 * Compiles patterns once per request for efficiency.
	 *
	 * @since 1.0.0
	 *
	 * @param string $route REST route.
	 * @return bool True if the route matches an exclusion pattern.
	 */
	private function is_excluded( string $route ): bool {
		if ( null === $this->exclusion_patterns ) {
			$this->exclusion_patterns = array();
			$raw = array_filter(
				array_map( 'trim', explode( "\n", $this->settings['excluded_endpoints'] ) )
			);
			foreach ( $raw as $pattern ) {
				if ( '' !== $pattern ) {
					$this->exclusion_patterns[] = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';
				}
			}
		}

		foreach ( $this->exclusion_patterns as $regex ) {
			if ( preg_match( $regex, $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record a cache statistic increment (batched for shutdown).
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Either 'hits' or 'misses'.
	 * @return void
	 */
	private function record_stat( string $type ): void {
		if ( isset( $this->pending_stats[ $type ] ) ) {
			++$this->pending_stats[ $type ];
		}
	}

	/**
	 * Record access pattern for adaptive TTL (batched for shutdown).
	 *
	 * @since 2.0.0
	 *
	 * @param string $route The REST route.
	 * @return void
	 */
	private function record_access_pattern( string $route ): void {
		$this->pending_access_routes[ md5( $route ) ] = $route;
	}

	/**
	 * Persist all deferred writes: stats, access patterns, and index.
	 *
	 * Called at shutdown to batch DB writes and reduce per-request overhead.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function persist_deferred_writes(): void {
		$this->flush_pending_stats();
		$this->flush_pending_access_patterns();
		$this->flush_index();
	}

	/**
	 * Write batched stat increments to the database.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function flush_pending_stats(): void {
		if ( 0 === $this->pending_stats['hits'] && 0 === $this->pending_stats['misses'] ) {
			return;
		}

		$stats = get_option( self::STATS_OPTION, array(
			'hits'       => 0,
			'misses'     => 0,
			'last_flush' => 0,
		) );

		$stats['hits']   += $this->pending_stats['hits'];
		$stats['misses'] += $this->pending_stats['misses'];

		update_option( self::STATS_OPTION, $stats, false );

		$this->pending_stats = array( 'hits' => 0, 'misses' => 0 );
	}

	/**
	 * Write batched access pattern updates to the database.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function flush_pending_access_patterns(): void {
		if ( empty( $this->pending_access_routes ) ) {
			return;
		}

		$patterns = get_option( self::ACCESS_PATTERNS_OPTION, array() );
		$hour_key = gmdate( 'Y-m-d-H' );

		foreach ( $this->pending_access_routes as $route_key => $route ) {
			if ( ! isset( $patterns[ $route_key ] ) ) {
				$patterns[ $route_key ] = array(
					'route'        => $route,
					'access_count' => 0,
					'last_hour'    => array(),
				);
			}

			++$patterns[ $route_key ]['access_count'];

			if ( ! isset( $patterns[ $route_key ]['last_hour'][ $hour_key ] ) ) {
				$patterns[ $route_key ]['last_hour'][ $hour_key ] = 0;
			}
			++$patterns[ $route_key ]['last_hour'][ $hour_key ];

			$hours = $patterns[ $route_key ]['last_hour'];
			if ( count( $hours ) > 24 ) {
				ksort( $hours );
				$patterns[ $route_key ]['last_hour'] = array_slice( $hours, -24, 24, true );
			}
		}

		update_option( self::ACCESS_PATTERNS_OPTION, $patterns, false );

		$this->pending_access_routes = array();
	}

	/**
	 * Write dirty index to the database.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function flush_index(): void {
		if ( $this->index_dirty && null !== $this->cached_index ) {
			update_option( self::INDEX_OPTION, $this->cached_index, false );
			$this->index_dirty = false;
		}
	}

	/**
	 * Adjust TTL based on access patterns.
	 *
	 * High-traffic routes get longer TTLs. Low-traffic routes get shorter ones.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route The REST route.
	 * @param int    $ttl   Base TTL in seconds.
	 * @return int Adjusted TTL in seconds.
	 */
	private function adjust_ttl_by_access_pattern( string $route, int $ttl ): int {
		if ( null === $this->cached_access_patterns ) {
			$this->cached_access_patterns = get_option( self::ACCESS_PATTERNS_OPTION, array() );
		}
		$patterns  = $this->cached_access_patterns;
		$route_key = md5( $route );

		if ( ! isset( $patterns[ $route_key ] ) || empty( $patterns[ $route_key ]['last_hour'] ) ) {
			return $ttl;
		}

		$hours       = $patterns[ $route_key ]['last_hour'];
		$avg_hourly  = array_sum( $hours ) / max( 1, count( $hours ) );

		if ( $avg_hourly > 100 ) {
			return (int) min( $ttl * 4, 86400 );
		}
		if ( $avg_hourly > 50 ) {
			return (int) min( $ttl * 2, 43200 );
		}
		if ( $avg_hourly > 10 ) {
			return $ttl;
		}
		if ( $avg_hourly < 1 ) {
			return (int) max( $ttl / 2, 60 );
		}

		return $ttl;
	}

	/**
	 * Get all cache statistics including hit rate.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     Cache statistics.
	 *
	 *     @type int   $hits         Total cache hits.
	 *     @type int   $misses       Total cache misses.
	 *     @type int   $last_flush   Timestamp of last flush.
	 *     @type int   $total_cached Number of cached entries.
	 *     @type float $hit_rate     Cache hit rate percentage.
	 * }
	 */
	public function get_stats(): array {
		$stats = get_option( self::STATS_OPTION, array(
			'hits'       => 0,
			'misses'     => 0,
			'last_flush' => 0,
		) );

		$index         = $this->get_index();
		$total         = $stats['hits'] + $stats['misses'];
		$stats['total_cached'] = count( $index );
		$stats['hit_rate']     = $total > 0 ? round( ( $stats['hits'] / $total ) * 100, 1 ) : 0;

		return $stats;
	}

	/**
	 * Retrieve the cache key index.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of index entries with 'key', 'route', and 'time' values.
	 */
	public function get_index(): array {
		if ( null === $this->cached_index ) {
			$this->cached_index = get_option( self::INDEX_OPTION, array() );
		}
		return $this->cached_index;
	}

	/**
	 * Update or insert an index entry for a cache key with route metadata.
	 *
	 * @since 2.0.0
	 *
	 * @param string $cache_key Cache key.
	 * @param string $route     REST route.
	 * @param int    $ttl       Time to live in seconds.
	 * @return void
	 */
	private function update_index_entry( string $cache_key, string $route, int $ttl ): void {
		$index = $this->get_index();

		$index = array_values(
			array_filter( $index, static fn( array $entry ): bool => $entry['key'] !== $cache_key )
		);

		$index[] = array(
			'key'   => $cache_key,
			'route' => $route,
			'time'  => time(),
			'ttl'   => $ttl,
		);

		// Enforce max entries limit.
		$max_entries = (int) ( $this->settings['max_entries'] ?? 1000 );
		if ( count( $index ) > $max_entries ) {
			// Remove oldest entries.
			usort( $index, static fn( array $a, array $b ): int => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
			$to_remove = array_splice( $index, 0, count( $index ) - $max_entries );
			foreach ( $to_remove as $entry ) {
				$this->delete_cached( $entry['key'] );
				$this->delete_cached( $entry['key'] . '_stale' );
			}
		}

		$this->cached_index = $index;
		$this->index_dirty  = true;
	}

	/**
	 * Remove a cache key from the index.
	 *
	 * @since 1.0.0
	 *
	 * @param string $cache_key Cache key.
	 * @return void
	 */
	private function remove_from_index( string $cache_key ): void {
		$index = $this->get_index();

		$this->cached_index = array_values(
			array_filter( $index, static fn( array $entry ): bool => $entry['key'] !== $cache_key )
		);
		$this->index_dirty = true;
	}

	/**
	 * Store a value in cache with route metadata for invalidation lookups.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param mixed           $data    Data to cache.
	 * @param int             $ttl     Time to live in seconds.
	 * @return void
	 */
	public function set_cached_with_route( \WP_REST_Request $request, mixed $data, int $ttl ): void {
		$cache_key = $this->generate_cache_key( $request );
		$this->set_cached( $cache_key, $data, $ttl );
		$this->update_index_entry( $cache_key, $request->get_route(), $ttl );
	}

	/**
	 * Get the current plugin settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Current settings merged with defaults.
	 */
	public function get_settings(): array {
		return $this->settings;
	}
}
