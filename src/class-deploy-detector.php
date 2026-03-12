<?php
/**
 * Deploy/update detection and cache preloading.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deploy_Detector
 *
 * Detects deployment and update events (plugin/theme updates, theme switches,
 * plugin activations, auto-updates, CI/CD webhooks) and triggers cache
 * flushing followed by a scheduled cache warm-up.
 *
 * @since 2.1.0
 * @package API_Cache_Layer
 */
class Deploy_Detector {

	/**
	 * Option key for the deploy event log.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const LOG_OPTION = 'acl_deploy_log';

	/**
	 * Option key for the webhook secret.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const SECRET_OPTION = 'acl_deploy_webhook_secret';

	/**
	 * Cron hook for deploy-triggered cache warming.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const WARM_CRON_HOOK = 'acl_deploy_warm_cron';

	/**
	 * REST API namespace.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const REST_NAMESPACE = 'acl/v1';

	/**
	 * Maximum number of deploy log entries to retain.
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const MAX_LOG_ENTRIES = 50;

	/**
	 * Singleton instance.
	 *
	 * @since 2.1.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Cache manager instance.
	 *
	 * @since 2.1.0
	 * @var Cache_Manager
	 */
	private Cache_Manager $cache_manager;

	/**
	 * Cache warmer instance.
	 *
	 * @since 2.1.0
	 * @var Cache_Warmer
	 */
	private Cache_Warmer $warmer;

	/**
	 * Get or create the singleton instance.
	 *
	 * @since 2.1.0
	 *
	 * @param Cache_Manager $cache_manager Cache manager instance.
	 * @param Cache_Warmer  $warmer        Cache warmer instance.
	 * @return self
	 */
	public static function get_instance( Cache_Manager $cache_manager, Cache_Warmer $warmer ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $cache_manager, $warmer );
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 2.1.0
	 *
	 * @param Cache_Manager $cache_manager Cache manager instance.
	 * @param Cache_Warmer  $warmer        Cache warmer instance.
	 */
	private function __construct( Cache_Manager $cache_manager, Cache_Warmer $warmer ) {
		$this->cache_manager = $cache_manager;
		$this->warmer        = $warmer;
	}

	/**
	 * Register hooks for deploy detection, REST route, and cron callback.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function init(): void {
		// Plugin/theme update completed.
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );

		// Theme switch.
		add_action( 'switch_theme', array( $this, 'on_theme_switch' ), 10, 3 );

		// Plugin activation/deactivation.
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 2 );

		// Automatic updates completed.
		add_action( 'automatic_updates_complete', array( $this, 'on_auto_updates_complete' ), 10, 1 );

		// Deploy warm cron callback.
		add_action( self::WARM_CRON_HOOK, array( $this, 'run_warm' ) );

		// Register REST route for CI/CD webhook.
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Handle the upgrader_process_complete hook.
	 *
	 * Fires after plugins or themes are bulk-updated via the WordPress upgrader.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $hook_extra Extra data about what was updated.
	 * @return void
	 */
	public function on_upgrader_complete( $upgrader, array $hook_extra ): void {
		$action = $hook_extra['action'] ?? '';
		$type   = $hook_extra['type'] ?? '';

		if ( 'update' !== $action ) {
			return;
		}

		if ( 'plugin' === $type ) {
			$plugins = $hook_extra['plugins'] ?? array();
			$details = sprintf( 'Updated plugins: %s', implode( ', ', $plugins ) );
			$this->on_deploy( 'plugin_update', $details );
		} elseif ( 'theme' === $type ) {
			$themes  = $hook_extra['themes'] ?? array();
			$details = sprintf( 'Updated themes: %s', implode( ', ', $themes ) );
			$this->on_deploy( 'theme_update', $details );
		}
	}

	/**
	 * Handle the switch_theme hook.
	 *
	 * @since 2.1.0
	 *
	 * @param string   $new_name  Name of the new theme.
	 * @param WP_Theme $new_theme New theme object.
	 * @param WP_Theme $old_theme Old theme object.
	 * @return void
	 */
	public function on_theme_switch( string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme ): void {
		$details = sprintf( 'Switched theme from "%s" to "%s"', $old_theme->get( 'Name' ), $new_name );
		$this->on_deploy( 'theme_switch', $details );
	}

	/**
	 * Handle the activated_plugin hook.
	 *
	 * @since 2.1.0
	 *
	 * @param string $plugin       Path to the plugin file relative to the plugins directory.
	 * @param bool   $network_wide Whether the plugin is being activated network-wide.
	 * @return void
	 */
	public function on_plugin_activated( string $plugin, bool $network_wide ): void {
		$details = sprintf( 'Activated plugin: %s%s', $plugin, $network_wide ? ' (network-wide)' : '' );
		$this->on_deploy( 'plugin_activate', $details );
	}

	/**
	 * Handle the deactivated_plugin hook.
	 *
	 * @since 2.1.0
	 *
	 * @param string $plugin       Path to the plugin file relative to the plugins directory.
	 * @param bool   $network_wide Whether the plugin is being deactivated network-wide.
	 * @return void
	 */
	public function on_plugin_deactivated( string $plugin, bool $network_wide ): void {
		$details = sprintf( 'Deactivated plugin: %s%s', $plugin, $network_wide ? ' (network-wide)' : '' );
		$this->on_deploy( 'plugin_deactivate', $details );
	}

	/**
	 * Handle the automatic_updates_complete hook.
	 *
	 * @since 2.1.0
	 *
	 * @param array $results Results of all automatic update attempts.
	 * @return void
	 */
	public function on_auto_updates_complete( array $results ): void {
		$updated = array();

		foreach ( $results as $type => $type_results ) {
			if ( ! is_array( $type_results ) ) {
				continue;
			}
			foreach ( $type_results as $result ) {
				if ( ! empty( $result->result ) ) {
					$name      = $result->item->slug ?? $result->item->theme ?? 'unknown';
					$updated[] = sprintf( '%s (%s)', $name, $type );
				}
			}
		}

		if ( empty( $updated ) ) {
			return;
		}

		$details = sprintf( 'Auto-updated: %s', implode( ', ', $updated ) );
		$this->on_deploy( 'plugin_update', $details );
	}

	/**
	 * Process a deploy event: flush cache, schedule warm-up, and log the event.
	 *
	 * @since 2.1.0
	 *
	 * @param string $type    Deploy type: plugin_update, theme_update, theme_switch,
	 *                        plugin_activate, plugin_deactivate, ci_webhook, manual.
	 * @param string $details Human-readable description of the event.
	 * @return void
	 */
	public function on_deploy( string $type, string $details = '' ): void {
		// Flush all cached responses.
		$this->cache_manager->flush_all();

		// Schedule a cache warm-up with a 30-second delay.
		$this->schedule_warm();

		// Log the deploy event.
		$log   = $this->get_deploy_log();
		$log[] = array(
			'timestamp'    => time(),
			'type'         => $type,
			'details'      => $details,
			'cache_warmed' => false,
		);

		// Keep only the last MAX_LOG_ENTRIES entries.
		if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_LOG_ENTRIES );
		}

		update_option( self::LOG_OPTION, $log, false );

		/**
		 * Fires after a deploy event has been processed.
		 *
		 * @since 2.1.0
		 *
		 * @param string $type    Deploy event type.
		 * @param string $details Event details.
		 */
		do_action( 'acl_deploy_detected', $type, $details );
	}

	/**
	 * Get the deploy event log.
	 *
	 * @since 2.1.0
	 *
	 * @return array List of deploy log entries, each with timestamp, type, details, cache_warmed.
	 */
	public function get_deploy_log(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear the deploy event log.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function clear_deploy_log(): void {
		update_option( self::LOG_OPTION, array(), false );
	}

	/**
	 * Schedule a one-time cache warm-up 30 seconds from now.
	 *
	 * Avoids scheduling a duplicate if one is already pending.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function schedule_warm(): void {
		if ( ! wp_next_scheduled( self::WARM_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::WARM_CRON_HOOK );
		}
	}

	/**
	 * Cron callback: run cache warming and mark the latest log entry as warmed.
	 *
	 * Delegates to the CacheWarmer to perform the actual route warming.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function run_warm(): void {
		$routes = $this->warmer->get_warmable_routes();

		if ( ! empty( $routes ) ) {
			$settings   = $this->warmer->get_settings();
			$batch_size = $settings['batch_size'] ?? 10;

			$this->warmer->warm_routes( $routes, $batch_size );
		}

		// Mark the most recent log entry as warmed.
		$log = $this->get_deploy_log();

		if ( ! empty( $log ) ) {
			$last_index                       = count( $log ) - 1;
			$log[ $last_index ]['cache_warmed'] = true;
			update_option( self::LOG_OPTION, $log, false );
		}
	}

	/**
	 * Register the REST API webhook endpoint.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register_rest_route(): void {
		register_rest_route( self::REST_NAMESPACE, '/deploy-notify', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => array( $this, 'verify_webhook_secret' ),
		) );
	}

	/**
	 * Verify the webhook secret from the X-ACL-Secret header.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool|WP_Error True if the secret matches, WP_Error otherwise.
	 */
	public function verify_webhook_secret( \WP_REST_Request $request ) {
		$provided = $request->get_header( 'X-ACL-Secret' );
		$stored   = $this->get_webhook_secret();

		if ( empty( $provided ) || ! hash_equals( $stored, $provided ) ) {
			return new \WP_Error(
				'acl_unauthorized',
				'Invalid or missing webhook secret.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle the CI/CD deploy webhook request.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response JSON response with success, message, and flushed_count.
	 */
	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$details = $request->get_param( 'details' ) ?? 'CI/CD deploy webhook triggered';

		// Get the current cache count before flushing.
		$index         = $this->cache_manager->get_index();
		$flushed_count = count( $index );

		$this->on_deploy( 'ci_webhook', sanitize_text_field( $details ) );

		return new \WP_REST_Response( array(
			'success'       => true,
			'message'       => 'Deploy event processed. Cache flushed and warm-up scheduled.',
			'flushed_count' => $flushed_count,
		), 200 );
	}

	/**
	 * Get the webhook secret, generating one on first use.
	 *
	 * @since 2.1.0
	 *
	 * @return string The webhook secret.
	 */
	public function get_webhook_secret(): string {
		$secret = get_option( self::SECRET_OPTION, '' );

		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 40, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
	}

	/**
	 * Regenerate the webhook secret.
	 *
	 * @since 2.1.0
	 *
	 * @return string The new webhook secret.
	 */
	public function regenerate_webhook_secret(): string {
		$secret = wp_generate_password( 40, false );
		update_option( self::SECRET_OPTION, $secret, false );
		return $secret;
	}
}
