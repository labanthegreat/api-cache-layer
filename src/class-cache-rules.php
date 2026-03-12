<?php
/**
 * Advanced caching rules engine.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cache_Rules
 *
 * Provides per-route TTL configuration, cache variation by query params /
 * user role / custom headers, conditional caching, stale-while-revalidate,
 * cache tagging, and request rate limiting integration.
 *
 * @since 2.0.0
 * @package API_Cache_Layer
 */
class Cache_Rules {

	/**
	 * Option key for stored rules.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const RULES_OPTION = 'acl_cache_rules';

	/**
	 * Option key for cache tags index.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const TAGS_INDEX_OPTION = 'acl_cache_tags';

	/**
	 * Transient prefix for rate limit tracking.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const RATE_LIMIT_PREFIX = 'acl_rate_';

	/**
	 * Cache manager instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Manager
	 */
	private Cache_Manager $cache_manager;

	/**
	 * Compiled rules cache (in-memory for current request).
	 *
	 * @since 2.0.0
	 * @var array|null
	 */
	private ?array $compiled_rules = null;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param Cache_Manager $cache_manager Cache manager instance.
	 */
	public function __construct( Cache_Manager $cache_manager ) {
		$this->cache_manager = $cache_manager;
	}

	/**
	 * Register hooks for TTL, vary-by, conditional, and rate limit filters.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'acl_cache_ttl', array( $this, 'apply_rule_ttl' ), 20, 2 );
		add_filter( 'acl_cache_key_parts', array( $this, 'apply_vary_rules' ), 10, 2 );
		add_filter( 'acl_should_cache', array( $this, 'apply_conditional_rules' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( $this, 'check_rate_limit' ), 5, 3 );
	}

	/**
	 * Get all configured rules, using in-memory cache when available.
	 *
	 * @since 2.0.0
	 *
	 * @return array Associative array of rules keyed by rule ID.
	 */
	public function get_rules(): array {
		if ( null !== $this->compiled_rules ) {
			return $this->compiled_rules;
		}

		$this->compiled_rules = get_option( self::RULES_OPTION, array() );
		return $this->compiled_rules;
	}

	/**
	 * Get a single rule by ID.
	 *
	 * @since 2.0.0
	 *
	 * @param string $rule_id Rule identifier.
	 * @return array|null Rule configuration or null if not found.
	 */
	public function get_rule( string $rule_id ): ?array {
		$rules = $this->get_rules();
		return $rules[ $rule_id ] ?? null;
	}

	/**
	 * Add or update a cache rule.
	 *
	 * Sanitizes the rule configuration and persists it to the database.
	 * Rules are automatically sorted by priority after saving.
	 *
	 * @since 2.0.0
	 *
	 * @param array $rule Rule configuration.
	 * @return string The saved rule ID (generated if not provided).
	 */
	public function save_rule( array $rule ): string {
		$rules = $this->get_rules();

		$rule_id = $rule['id'] ?? wp_generate_uuid4();

		$sanitized = array(
			'id'                     => $rule_id,
			'route_pattern'          => sanitize_text_field( $rule['route_pattern'] ?? '' ),
			'ttl'                    => absint( $rule['ttl'] ?? 3600 ),
			'enabled'                => ! empty( $rule['enabled'] ),
			'vary_by_query_params'   => $this->sanitize_csv( $rule['vary_by_query_params'] ?? '' ),
			'vary_by_user_role'      => ! empty( $rule['vary_by_user_role'] ),
			'vary_by_headers'        => $this->sanitize_csv( $rule['vary_by_headers'] ?? '' ),
			'skip_params'            => $this->sanitize_csv( $rule['skip_params'] ?? '' ),
			'stale_ttl'              => absint( $rule['stale_ttl'] ?? 0 ),
			'tags'                   => $this->sanitize_csv( $rule['tags'] ?? '' ),
			'rate_limit'             => absint( $rule['rate_limit'] ?? 0 ),
			'rate_limit_window'      => absint( $rule['rate_limit_window'] ?? 60 ),
			'priority'               => absint( $rule['priority'] ?? 10 ),
			'created_at'             => $rule['created_at'] ?? time(),
			'updated_at'             => time(),
		);

		$rules[ $rule_id ] = $sanitized;

		// Sort by priority.
		uasort( $rules, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

		update_option( self::RULES_OPTION, $rules );
		$this->compiled_rules = null;

		return $rule_id;
	}

	/**
	 * Delete a cache rule.
	 *
	 * @since 2.0.0
	 *
	 * @param string $rule_id Rule identifier.
	 * @return bool True if the rule was found and deleted, false otherwise.
	 */
	public function delete_rule( string $rule_id ): bool {
		$rules = $this->get_rules();

		if ( ! isset( $rules[ $rule_id ] ) ) {
			return false;
		}

		unset( $rules[ $rule_id ] );
		update_option( self::RULES_OPTION, $rules );
		$this->compiled_rules = null;

		return true;
	}

	/**
	 * Find the first matching enabled rule for a given route.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route REST route.
	 * @return array|null Matching rule configuration or null if no match.
	 */
	public function find_matching_rule( string $route ): ?array {
		$rules = $this->get_rules();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			if ( $this->route_matches_pattern( $route, $rule['route_pattern'] ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Apply per-route TTL from rules.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $ttl   Current TTL in seconds.
	 * @param string $route REST route.
	 * @return int Possibly modified TTL in seconds.
	 */
	public function apply_rule_ttl( int $ttl, string $route ): int {
		$rule = $this->find_matching_rule( $route );

		if ( $rule && $rule['ttl'] > 0 ) {
			return $rule['ttl'];
		}

		return $ttl;
	}

	/**
	 * Apply vary-by rules to modify cache key components.
	 *
	 * Adds query param, user role, and header variations to the cache key
	 * based on matching rule configuration.
	 *
	 * @since 2.0.0
	 *
	 * @param array           $parts   Current cache key parts.
	 * @param WP_REST_Request $request The REST request.
	 * @return array Modified key parts with vary-by additions.
	 */
	public function apply_vary_rules( array $parts, \WP_REST_Request $request ): array {
		$route = $request->get_route();
		$rule  = $this->find_matching_rule( $route );

		if ( ! $rule ) {
			return $parts;
		}

		// Vary by specific query params.
		if ( ! empty( $rule['vary_by_query_params'] ) ) {
			$vary_params = array_map( 'trim', explode( ',', $rule['vary_by_query_params'] ) );
			$query       = $request->get_query_params();
			$vary_values = array();

			foreach ( $vary_params as $param ) {
				if ( isset( $query[ $param ] ) ) {
					$vary_values[ $param ] = $query[ $param ];
				}
			}

			if ( ! empty( $vary_values ) ) {
				ksort( $vary_values );
				$parts['vary_params'] = md5( wp_json_encode( $vary_values ) );
			}
		}

		// Vary by user role.
		if ( ! empty( $rule['vary_by_user_role'] ) && is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$roles = $user->roles;
			sort( $roles );
			$parts['user_role'] = implode( ',', $roles );
		}

		// Vary by custom headers.
		if ( ! empty( $rule['vary_by_headers'] ) ) {
			$vary_headers  = array_map( 'trim', explode( ',', $rule['vary_by_headers'] ) );
			$header_values = array();

			foreach ( $vary_headers as $header ) {
				$value = $request->get_header( $header );
				if ( null !== $value ) {
					$header_values[ $header ] = $value;
				}
			}

			if ( ! empty( $header_values ) ) {
				ksort( $header_values );
				$parts['vary_headers'] = md5( wp_json_encode( $header_values ) );
			}
		}

		return $parts;
	}

	/**
	 * Apply conditional caching rules based on skip_params configuration.
	 *
	 * @since 2.0.0
	 *
	 * @param bool            $should_cache Whether to cache the request.
	 * @param WP_REST_Request $request      The REST request.
	 * @return bool False if a skip parameter is present, original value otherwise.
	 */
	public function apply_conditional_rules( bool $should_cache, \WP_REST_Request $request ): bool {
		$route = $request->get_route();
		$rule  = $this->find_matching_rule( $route );

		if ( ! $rule ) {
			return $should_cache;
		}

		// Skip caching if certain query params are present.
		if ( ! empty( $rule['skip_params'] ) ) {
			$skip_params = array_map( 'trim', explode( ',', $rule['skip_params'] ) );
			$query       = $request->get_query_params();

			foreach ( $skip_params as $param ) {
				if ( isset( $query[ $param ] ) ) {
					return false;
				}
			}
		}

		return $should_cache;
	}

	/**
	 * Check rate limiting for a route and return 429 if exceeded.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request The REST request.
	 * @return mixed WP_REST_Response with 429 status if rate limited, original $result otherwise.
	 */
	public function check_rate_limit( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( null !== $result ) {
			return $result;
		}

		$route = $request->get_route();
		$rule  = $this->find_matching_rule( $route );

		if ( ! $rule || empty( $rule['rate_limit'] ) ) {
			return $result;
		}

		$ip         = $this->get_client_ip();
		$rate_key   = self::RATE_LIMIT_PREFIX . md5( $route . $ip );
		$window     = max( 1, $rule['rate_limit_window'] );
		$limit      = $rule['rate_limit'];
		$current    = (int) get_transient( $rate_key );

		if ( $current >= $limit ) {
			$response = new \WP_REST_Response(
				array(
					'code'    => 'rate_limit_exceeded',
					'message' => 'Rate limit exceeded. Please try again later.',
					'data'    => array( 'status' => 429 ),
				),
				429
			);
			$response->header( 'Retry-After', (string) $window );
			$response->header( 'X-RateLimit-Limit', (string) $limit );
			$response->header( 'X-RateLimit-Remaining', '0' );

			return $response;
		}

		set_transient( $rate_key, $current + 1, $window );

		return $result;
	}

	/**
	 * Get stale-while-revalidate data for a route.
	 *
	 * @since 2.0.0
	 *
	 * @param string          $cache_key The cache key.
	 * @param WP_REST_Request $request   The REST request.
	 * @return array|null Stale cached data or null if unavailable.
	 */
	public function get_stale_data( string $cache_key, \WP_REST_Request $request ): ?array {
		$route = $request->get_route();
		$rule  = $this->find_matching_rule( $route );

		if ( ! $rule || empty( $rule['stale_ttl'] ) ) {
			return null;
		}

		$stale_key = $cache_key . '_stale';
		$stale     = $this->cache_manager->get_cached( $stale_key );

		return false !== $stale ? $stale : null;
	}

	/**
	 * Store stale copy for stale-while-revalidate support.
	 *
	 * @since 2.0.0
	 *
	 * @param string          $cache_key The cache key.
	 * @param mixed           $data      The data to store as stale copy.
	 * @param WP_REST_Request $request   The REST request.
	 * @return void
	 */
	public function store_stale_copy( string $cache_key, mixed $data, \WP_REST_Request $request ): void {
		$route = $request->get_route();
		$rule  = $this->find_matching_rule( $route );

		if ( ! $rule || empty( $rule['stale_ttl'] ) ) {
			return;
		}

		$stale_key = $cache_key . '_stale';
		$stale_ttl = $rule['ttl'] + $rule['stale_ttl'];

		$this->cache_manager->set_cached( $stale_key, $data, $stale_ttl );
	}

	/**
	 * Add tags to a cache entry for tag-based invalidation.
	 *
	 * @since 2.0.0
	 *
	 * @param string $cache_key The cache key.
	 * @param string $route     The REST route.
	 * @return void
	 */
	public function tag_cache_entry( string $cache_key, string $route ): void {
		$rule = $this->find_matching_rule( $route );

		if ( ! $rule || empty( $rule['tags'] ) ) {
			return;
		}

		$tags       = array_map( 'trim', explode( ',', $rule['tags'] ) );
		$tags_index = get_option( self::TAGS_INDEX_OPTION, array() );

		foreach ( $tags as $tag ) {
			if ( empty( $tag ) ) {
				continue;
			}
			if ( ! isset( $tags_index[ $tag ] ) ) {
				$tags_index[ $tag ] = array();
			}
			if ( ! in_array( $cache_key, $tags_index[ $tag ], true ) ) {
				$tags_index[ $tag ][] = $cache_key;
			}
		}

		update_option( self::TAGS_INDEX_OPTION, $tags_index, false );
	}

	/**
	 * Invalidate all cache entries with a specific tag.
	 *
	 * @since 2.0.0
	 *
	 * @param string $tag Cache tag to invalidate.
	 * @return int Number of entries invalidated.
	 */
	public function invalidate_by_tag( string $tag ): int {
		$tags_index = get_option( self::TAGS_INDEX_OPTION, array() );
		$count      = 0;

		if ( ! isset( $tags_index[ $tag ] ) ) {
			return 0;
		}

		foreach ( $tags_index[ $tag ] as $cache_key ) {
			$this->cache_manager->invalidate( $cache_key );
			++$count;
		}

		unset( $tags_index[ $tag ] );
		update_option( self::TAGS_INDEX_OPTION, $tags_index, false );

		return $count;
	}

	/**
	 * Get all defined tags and their entry counts.
	 *
	 * @since 2.0.0
	 *
	 * @return array Associative array of tag name => entry count.
	 */
	public function get_tags_summary(): array {
		$tags_index = get_option( self::TAGS_INDEX_OPTION, array() );
		$summary    = array();

		foreach ( $tags_index as $tag => $keys ) {
			$summary[ $tag ] = count( $keys );
		}

		return $summary;
	}

	/**
	 * Check if a route matches a pattern (supports * wildcards).
	 *
	 * @since 2.0.0
	 *
	 * @param string $route   The route to test.
	 * @param string $pattern The pattern to match against.
	 * @return bool True if the route matches the pattern.
	 */
	private function route_matches_pattern( string $route, string $pattern ): bool {
		if ( $route === $pattern ) {
			return true;
		}

		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';
		return (bool) preg_match( $regex, $route );
	}

	/**
	 * Sanitize a comma-separated values string.
	 *
	 * @since 2.0.0
	 *
	 * @param string $input Raw CSV string.
	 * @return string Sanitized CSV string with empty values removed.
	 */
	private function sanitize_csv( string $input ): string {
		$parts = array_map( 'trim', explode( ',', $input ) );
		$parts = array_filter( $parts );
		return implode( ',', array_map( 'sanitize_text_field', $parts ) );
	}

	/**
	 * Get client IP address from proxy-aware headers.
	 *
	 * Checks Cloudflare, X-Forwarded-For, X-Real-IP, and REMOTE_ADDR
	 * headers in order of precedence.
	 *
	 * @since 2.0.0
	 *
	 * @return string Client IP address, defaults to '127.0.0.1'.
	 */
	private function get_client_ip(): string {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For can contain multiple IPs; take the first.
				$ip = str_contains( $raw, ',' ) ? trim( explode( ',', $raw )[0] ) : $raw;

				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '127.0.0.1';
	}
}
