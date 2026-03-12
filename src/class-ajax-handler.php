<?php
/**
 * Centralized AJAX handler for API Cache Layer admin operations.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Handler
 *
 * Registers and handles all AJAX endpoints used by the admin settings page.
 * Delegates to the appropriate component for each operation.
 *
 * @since 3.0.0
 * @package API_Cache_Layer
 */
class Ajax_Handler {

	/**
	 * AJAX nonce action.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	const NONCE_ACTION = 'acl_flush_cache';

	/**
	 * Cache manager instance.
	 *
	 * @since 3.0.0
	 * @var Cache_Manager
	 */
	private Cache_Manager $cache_manager;

	/**
	 * Analytics instance.
	 *
	 * @since 3.0.0
	 * @var Cache_Analytics|null
	 */
	private ?Cache_Analytics $analytics;

	/**
	 * Cache warmer instance.
	 *
	 * @since 3.0.0
	 * @var Cache_Warmer|null
	 */
	private ?Cache_Warmer $warmer;

	/**
	 * Cache rules instance.
	 *
	 * @since 3.0.0
	 * @var Cache_Rules|null
	 */
	private ?Cache_Rules $rules;

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 *
	 * @param Cache_Manager        $cache_manager Cache manager instance.
	 * @param Cache_Analytics|null $analytics     Analytics instance.
	 * @param Cache_Warmer|null    $warmer        Cache warmer instance.
	 * @param Cache_Rules|null     $rules         Cache rules instance.
	 */
	public function __construct(
		Cache_Manager $cache_manager,
		?Cache_Analytics $analytics = null,
		?Cache_Warmer $warmer = null,
		?Cache_Rules $rules = null
	) {
		$this->cache_manager = $cache_manager;
		$this->analytics     = $analytics;
		$this->warmer        = $warmer;
		$this->rules         = $rules;
	}

	/**
	 * Register all AJAX action hooks.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_ajax_acl_flush_cache', array( $this, 'ajax_flush_cache' ) );
		add_action( 'wp_ajax_acl_get_stats', array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_acl_get_analytics', array( $this, 'ajax_get_analytics' ) );
		add_action( 'wp_ajax_acl_save_rule', array( $this, 'ajax_save_rule' ) );
		add_action( 'wp_ajax_acl_delete_rule', array( $this, 'ajax_delete_rule' ) );
		add_action( 'wp_ajax_acl_warm_cache', array( $this, 'ajax_warm_cache' ) );
		add_action( 'wp_ajax_acl_get_cache_entries', array( $this, 'ajax_get_cache_entries' ) );
		add_action( 'wp_ajax_acl_export_csv', array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_acl_get_warmer_status', array( $this, 'ajax_get_warmer_status' ) );
		add_action( 'wp_ajax_acl_save_warmer_settings', array( $this, 'ajax_save_warmer_settings' ) );
	}

	/**
	 * Verify the AJAX request has a valid nonce and user capability.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON error and dies if verification fails.
	 */
	private function verify_request(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'api-cache-layer' ) ), 403 );
		}
	}

	/**
	 * AJAX handler to flush cache entries.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_flush_cache(): void {
		$this->verify_request();

		$route = sanitize_text_field( wp_unslash( $_POST['route'] ?? '' ) );

		if ( $route ) {
			$count = $this->cache_manager->invalidate_by_route( $route );
			if ( $this->analytics ) {
				$this->analytics->log_invalidation( $route, 'manual_flush', 'admin', $count );
			}
		} else {
			$count = $this->cache_manager->flush_all();
			if ( $this->analytics ) {
				$this->analytics->log_invalidation( '*', 'manual_flush_all', 'admin', $count );
			}
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of cache entries flushed */
				__( 'Successfully flushed %d cached responses.', 'api-cache-layer' ),
				$count
			),
			'count' => $count,
		) );
	}

	/**
	 * AJAX handler to get live cache statistics and recent invalidation log.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_get_stats(): void {
		$this->verify_request();

		$stats = $this->cache_manager->get_stats();
		$log   = $this->analytics ? $this->analytics->get_invalidation_log( 10 ) : array();

		wp_send_json_success( array(
			'stats'            => $stats,
			'invalidation_log' => $log,
		) );
	}

	/**
	 * AJAX handler to get analytics data for a specified time period.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_get_analytics(): void {
		$this->verify_request();

		$days = absint( wp_unslash( $_POST['days'] ?? 7 ) );

		if ( ! $this->analytics ) {
			wp_send_json_error( array( 'message' => __( 'Analytics not available.', 'api-cache-layer' ) ) );
			return;
		}

		wp_send_json_success( array(
			'summary'         => $this->analytics->get_summary( $days ),
			'trend_data'      => $this->analytics->get_trend_data( 'daily', min( $days, 30 ) ),
			'top_cached'      => $this->analytics->get_top_cached( 10 ),
			'top_missed'      => $this->analytics->get_top_missed( 10 ),
			'response_times'  => $this->analytics->get_response_time_comparison( 10 ),
		) );
	}

	/**
	 * AJAX handler to save a cache rule from the admin rule editor.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_save_rule(): void {
		$this->verify_request();

		if ( ! $this->rules ) {
			wp_send_json_error( array( 'message' => __( 'Rules engine not available.', 'api-cache-layer' ) ) );
			return;
		}

		$rule_data = array(
			'id'                   => sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) ) ?: null,
			'route_pattern'        => sanitize_text_field( wp_unslash( $_POST['route_pattern'] ?? '' ) ),
			'ttl'                  => absint( $_POST['ttl'] ?? 3600 ),
			'enabled'              => ! empty( $_POST['enabled'] ),
			'vary_by_query_params' => sanitize_text_field( wp_unslash( $_POST['vary_by_query_params'] ?? '' ) ),
			'vary_by_user_role'    => ! empty( $_POST['vary_by_user_role'] ),
			'vary_by_headers'      => sanitize_text_field( wp_unslash( $_POST['vary_by_headers'] ?? '' ) ),
			'skip_params'          => sanitize_text_field( wp_unslash( $_POST['skip_params'] ?? '' ) ),
			'stale_ttl'            => absint( $_POST['stale_ttl'] ?? 0 ),
			'tags'                 => sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) ),
			'rate_limit'           => absint( $_POST['rate_limit'] ?? 0 ),
			'rate_limit_window'    => absint( $_POST['rate_limit_window'] ?? 60 ),
			'priority'             => absint( $_POST['priority'] ?? 10 ),
		);

		if ( empty( $rule_data['route_pattern'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Route pattern is required.', 'api-cache-layer' ) ) );
			return;
		}

		$rule_id = $this->rules->save_rule( $rule_data );

		wp_send_json_success( array(
			'message' => __( 'Rule saved successfully.', 'api-cache-layer' ),
			'rule_id' => $rule_id,
		) );
	}

	/**
	 * AJAX handler to delete a cache rule by ID.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_delete_rule(): void {
		$this->verify_request();

		$rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );

		if ( ! $rule_id || ! $this->rules ) {
			wp_send_json_error( array( 'message' => __( 'Invalid rule ID.', 'api-cache-layer' ) ) );
			return;
		}

		$deleted = $this->rules->delete_rule( $rule_id );

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => __( 'Rule deleted.', 'api-cache-layer' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Rule not found.', 'api-cache-layer' ) ) );
		}
	}

	/**
	 * AJAX handler to trigger an immediate cache warming operation.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_warm_cache(): void {
		$this->verify_request();

		if ( ! $this->warmer ) {
			wp_send_json_error( array( 'message' => __( 'Cache warmer not available.', 'api-cache-layer' ) ) );
			return;
		}

		$results = $this->warmer->warm_routes();
		$success = 0;
		$failed  = 0;

		foreach ( $results as $result ) {
			if ( $result['success'] ) {
				++$success;
			} else {
				++$failed;
			}
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: routes warmed, 2: routes failed */
				__( 'Warmed %1$d routes. %2$d failed.', 'api-cache-layer' ),
				$success,
				$failed
			),
			'total'   => count( $results ),
			'success' => $success,
			'failed'  => $failed,
		) );
	}

	/**
	 * AJAX handler to get cache entries for the browser tab.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_get_cache_entries(): void {
		$this->verify_request();

		$search  = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$index   = $this->cache_manager->get_index();
		$entries = array();
		$now     = time();

		foreach ( $index as $entry ) {
			$route = $entry['route'] ?? 'unknown';

			if ( $search && ! str_contains( $route, $search ) && ! str_contains( $entry['key'], $search ) ) {
				continue;
			}

			$data    = $this->cache_manager->get_cached( $entry['key'] );
			$exists  = false !== $data;
			$size    = $exists ? strlen( maybe_serialize( $data ) ) : 0;
			$ttl     = $entry['ttl'] ?? (int) ( get_option( 'acl_settings', array() )['default_ttl'] ?? 3600 );
			$expires = ( $entry['time'] ?? 0 ) + $ttl;

			$entries[] = array(
				'key'        => $entry['key'],
				'route'      => $route,
				'status'     => $exists ? 'active' : 'expired',
				'size'       => $size,
				'size_fmt'   => size_format( $size ),
				'cached_at'  => $entry['time'] ?? 0,
				'cached_fmt' => ( $entry['time'] ?? 0 ) > 0 ? wp_date( 'Y-m-d H:i:s', $entry['time'] ) : '-',
				'ttl_left'   => $exists ? max( 0, $expires - $now ) : 0,
			);
		}

		wp_send_json_success( array( 'entries' => $entries ) );
	}

	/**
	 * AJAX handler to export analytics data as a CSV string.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_export_csv(): void {
		$this->verify_request();

		if ( ! $this->analytics ) {
			wp_send_json_error( array( 'message' => __( 'Analytics not available.', 'api-cache-layer' ) ) );
			return;
		}

		$days = absint( wp_unslash( $_POST['days'] ?? 30 ) );
		$data = $this->analytics->export_csv_data( $days );

		$csv = implode( ',', $data['headers'] ) . "\n";
		foreach ( $data['rows'] as $row ) {
			$csv .= implode( ',', array_map( static fn( $v ) => '"' . str_replace( '"', '""', (string) $v ) . '"', array_values( $row ) ) ) . "\n";
		}

		wp_send_json_success( array( 'csv' => $csv, 'filename' => 'acl-analytics-' . gmdate( 'Y-m-d' ) . '.csv' ) );
	}

	/**
	 * AJAX handler to get the current warmer status.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_get_warmer_status(): void {
		$this->verify_request();

		wp_send_json_success( array(
			'status' => $this->warmer ? $this->warmer->get_status() : array(),
		) );
	}

	/**
	 * AJAX handler to save warmer settings from the admin UI.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends JSON response and terminates.
	 */
	public function ajax_save_warmer_settings(): void {
		$this->verify_request();

		if ( ! $this->warmer ) {
			wp_send_json_error( array( 'message' => __( 'Cache warmer not available.', 'api-cache-layer' ) ) );
			return;
		}

		$this->warmer->update_settings( array(
			'enabled'    => ! empty( $_POST['enabled'] ),
			'schedule'   => sanitize_text_field( wp_unslash( $_POST['schedule'] ?? 'hourly' ) ),
			'batch_size' => absint( $_POST['batch_size'] ?? 10 ),
			'max_routes' => absint( $_POST['max_routes'] ?? 100 ),
			'skip_auth'  => ! empty( $_POST['skip_auth'] ),
		) );

		wp_send_json_success( array( 'message' => __( 'Warmer settings saved.', 'api-cache-layer' ) ) );
	}
}
