<?php
/**
 * Proactive cache warming for REST API endpoints.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cache_Warmer
 *
 * Crawls registered REST routes and pre-populates the cache.
 * Supports priority queuing based on access statistics,
 * scheduled warming via WP Cron, and configurable batch sizes.
 *
 * @since 2.0.0
 * @package API_Cache_Layer
 */
class Cache_Warmer {

	/**
	 * Option key for warmer settings.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const SETTINGS_OPTION = 'acl_warmer_settings';

	/**
	 * Option key for warmer status.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const STATUS_OPTION = 'acl_warmer_status';

	/**
	 * Cron hook name.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const CRON_HOOK = 'acl_cache_warm_cron';

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
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param Cache_Manager   $cache_manager Cache manager instance.
	 * @param Cache_Analytics $analytics     Analytics instance.
	 */
	public function __construct( Cache_Manager $cache_manager, Cache_Analytics $analytics ) {
		$this->cache_manager = $cache_manager;
		$this->analytics     = $analytics;
	}

	/**
	 * Register hooks for scheduled warming and post-flush warming.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_warm' ) );
		add_action( 'acl_cache_flushed', array( $this, 'schedule_warm_after_flush' ) );
	}

	/**
	 * Get default warmer settings.
	 *
	 * @since 2.0.0
	 *
	 * @return array Default warmer configuration values.
	 */
	public function get_defaults(): array {
		return array(
			'enabled'       => false,
			'batch_size'    => 10,
			'schedule'      => 'hourly',
			'skip_auth'     => true,
			'max_routes'    => 100,
		);
	}

	/**
	 * Get current warmer settings merged with defaults.
	 *
	 * @since 2.0.0
	 *
	 * @return array Current warmer settings.
	 */
	public function get_settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, $this->get_defaults() );
		return wp_parse_args( $settings, $this->get_defaults() );
	}

	/**
	 * Update warmer settings and reschedule cron accordingly.
	 *
	 * @since 2.0.0
	 *
	 * @param array $settings New settings to sanitize and save.
	 * @return void
	 */
	public function update_settings( array $settings ): void {
		$sanitized = array(
			'enabled'    => ! empty( $settings['enabled'] ),
			'batch_size' => max( 1, min( 50, absint( $settings['batch_size'] ?? 10 ) ) ),
			'schedule'   => in_array( $settings['schedule'] ?? '', array( 'hourly', 'twicedaily', 'daily' ), true )
				? $settings['schedule'] : 'hourly',
			'skip_auth'  => ! empty( $settings['skip_auth'] ),
			'max_routes' => max( 10, min( 500, absint( $settings['max_routes'] ?? 100 ) ) ),
		);

		update_option( self::SETTINGS_OPTION, $sanitized );

		// Reschedule cron.
		$this->unschedule();
		if ( $sanitized['enabled'] ) {
			$this->schedule( $sanitized['schedule'] );
		}
	}

	/**
	 * Schedule the warming cron event.
	 *
	 * @since 2.0.0
	 *
	 * @param string $recurrence Cron recurrence interval (hourly, twicedaily, daily).
	 * @return void
	 */
	public function schedule( string $recurrence = 'hourly' ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, $recurrence, self::CRON_HOOK );
		}
	}

	/**
	 * Remove scheduled warming cron event.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Schedule a one-time warm operation 30 seconds after cache flush.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function schedule_warm_after_flush(): void {
		$settings = $this->get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		// Schedule a one-time warm 30 seconds after flush.
		wp_schedule_single_event( time() + 30, self::CRON_HOOK );
	}

	/**
	 * Run the scheduled cache warming via cron.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function run_scheduled_warm(): void {
		$settings = $this->get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		$routes = $this->get_warmable_routes();
		$this->warm_routes( $routes, $settings['batch_size'] );
	}

	/**
	 * Warm specific routes or all warmable routes.
	 *
	 * @since 2.0.0
	 *
	 * @param array|null $routes     Routes to warm. Null for auto-discovery.
	 * @param int        $batch_size Number of routes to process per batch.
	 * @return array Results with route => status mapping.
	 */
	public function warm_routes( ?array $routes = null, int $batch_size = 10 ): array {
		if ( null === $routes ) {
			$routes = $this->get_warmable_routes();
		}

		$settings = $this->get_settings();
		$routes   = array_slice( $routes, 0, $settings['max_routes'] );
		$results  = array();
		$batches  = array_chunk( $routes, max( 1, $batch_size ) );

		$this->update_status( 'running', count( $routes ), 0 );

		$warmed = 0;

		foreach ( $batches as $batch ) {
			foreach ( $batch as $route ) {
				$result = $this->warm_single_route( $route );
				$results[ $route ] = $result;

				if ( $result['success'] ) {
					++$warmed;
				}
			}

			$this->update_status( 'running', count( $routes ), $warmed );
		}

		$this->update_status( 'completed', count( $routes ), $warmed );

		return $results;
	}

	/**
	 * Warm a single route by making an internal REST request.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route The REST route to warm.
	 * @return array Result with 'success', 'message', and optionally 'time_ms' keys.
	 */
	private function warm_single_route( string $route ): array {
		$request = new \WP_REST_Request( 'GET', $route );

		$start  = microtime( true );
		$server = rest_get_server();

		if ( ! $server ) {
			return array(
				'success' => false,
				'message' => 'REST server not available.',
			);
		}

		$response = $server->dispatch( $request );
		$elapsed  = ( microtime( true ) - $start ) * 1000;

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'time_ms' => round( $elapsed, 2 ),
			);
		}

		$status = $response->get_status();

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'success' => true,
				'message' => 'Warmed successfully.',
				'status'  => $status,
				'time_ms' => round( $elapsed, 2 ),
			);
		}

		return array(
			'success' => false,
			'message' => sprintf( 'HTTP %d response.', $status ),
			'status'  => $status,
			'time_ms' => round( $elapsed, 2 ),
		);
	}

	/**
	 * Get all REST routes that can be warmed.
	 *
	 * Returns routes sorted by popularity (most accessed first) based on
	 * analytics data. Skips authenticated/admin-only endpoints and routes
	 * with URL parameter placeholders.
	 *
	 * @since 2.0.0
	 *
	 * @return array List of route strings sorted by access popularity.
	 */
	public function get_warmable_routes(): array {
		$server = rest_get_server();
		if ( ! $server ) {
			return array();
		}

		$all_routes = $server->get_routes();
		$settings   = $this->get_settings();
		$warmable   = array();

		foreach ( $all_routes as $route => $handlers ) {
			// Skip the index route.
			if ( '/' === $route ) {
				continue;
			}

			// Skip routes with regex placeholders — we cannot warm them generically.
			if ( preg_match( '/\(\?P</', $route ) ) {
				continue;
			}

			// Check if any handler requires authentication.
			if ( $settings['skip_auth'] ) {
				$requires_auth = false;

				foreach ( $handlers as $handler ) {
					if ( ! is_array( $handler ) ) {
						continue;
					}

					// Check permission callback.
					if ( ! empty( $handler['permission_callback'] ) && '__return_true' !== $handler['permission_callback'] ) {
						// If it has a non-trivial permission callback, likely requires auth.
						if ( is_string( $handler['permission_callback'] ) && str_contains( $handler['permission_callback'], 'admin' ) ) {
							$requires_auth = true;
							break;
						}
						if ( is_array( $handler['permission_callback'] ) ) {
							$requires_auth = true;
							break;
						}
					}
				}

				if ( $requires_auth ) {
					continue;
				}
			}

			// Only warm GET-capable routes.
			$supports_get = false;
			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) ) {
					continue;
				}
				$methods = $handler['methods'] ?? array();
				if ( is_array( $methods ) && isset( $methods['GET'] ) ) {
					$supports_get = true;
					break;
				}
				if ( is_string( $methods ) && str_contains( $methods, 'GET' ) ) {
					$supports_get = true;
					break;
				}
			}

			if ( $supports_get ) {
				$warmable[] = $route;
			}
		}

		// Sort by popularity using analytics data.
		$popular = $this->analytics->get_routes_by_popularity( 500 );
		$popularity_map = array();
		foreach ( $popular as $entry ) {
			$popularity_map[ $entry['route'] ] = $entry['total'];
		}

		usort( $warmable, static function ( string $a, string $b ) use ( $popularity_map ): int {
			$score_a = $popularity_map[ $a ] ?? 0;
			$score_b = $popularity_map[ $b ] ?? 0;
			return $score_b <=> $score_a;
		} );

		return $warmable;
	}

	/**
	 * Update the warmer status for UI display.
	 *
	 * @since 2.0.0
	 *
	 * @param string $state   Current state: 'idle', 'running', 'completed'.
	 * @param int    $total   Total routes to warm.
	 * @param int    $warmed  Routes warmed so far.
	 * @return void
	 */
	private function update_status( string $state, int $total, int $warmed ): void {
		update_option( self::STATUS_OPTION, array(
			'state'      => $state,
			'total'      => $total,
			'warmed'     => $warmed,
			'updated_at' => time(),
		), false );
	}

	/**
	 * Get the current warmer status.
	 *
	 * @since 2.0.0
	 *
	 * @return array Status with 'state', 'total', 'warmed', and 'updated_at' keys.
	 */
	public function get_status(): array {
		return get_option( self::STATUS_OPTION, array(
			'state'      => 'idle',
			'total'      => 0,
			'warmed'     => 0,
			'updated_at' => 0,
		) );
	}

	/**
	 * Get next scheduled warm time.
	 *
	 * @since 2.0.0
	 *
	 * @return int|false Unix timestamp or false if not scheduled.
	 */
	public function get_next_scheduled(): int|false {
		return wp_next_scheduled( self::CRON_HOOK );
	}
}
