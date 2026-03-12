<?php
/**
 * Main plugin class for API Cache Layer.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

use Jestart\ApiCacheLayer\Admin\Settings_Page;
use Jestart\ApiCacheLayer\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * Singleton entry point that bootstraps all plugin components:
 * cache manager, analytics, rules engine, warmer, invalidator,
 * deploy detector, admin UI, AJAX handlers, and WP-CLI commands.
 *
 * @since 3.0.0
 * @package API_Cache_Layer
 */
class Plugin {

	use Singleton;

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
	 * @var Cache_Analytics
	 */
	private Cache_Analytics $analytics;

	/**
	 * Cache warmer instance.
	 *
	 * @since 3.0.0
	 * @var Cache_Warmer
	 */
	private Cache_Warmer $warmer;

	/**
	 * Cache rules instance.
	 *
	 * @since 3.0.0
	 * @var Cache_Rules
	 */
	private Cache_Rules $rules;

	/**
	 * Cache invalidator instance.
	 *
	 * @since 3.0.0
	 * @var Cache_Invalidator
	 */
	private Cache_Invalidator $invalidator;

	/**
	 * Private constructor — use get_instance() instead.
	 *
	 * @since 3.0.0
	 */
	private function __construct() {}

	/**
	 * Bootstrap all plugin components.
	 *
	 * Called once on `plugins_loaded` to wire dependencies and register hooks.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		// Core cache manager.
		$this->cache_manager = new Cache_Manager();

		// Analytics.
		$this->analytics = new Cache_Analytics();
		$this->analytics->init();

		// Cache rules.
		$this->rules = new Cache_Rules( $this->cache_manager );
		$this->rules->init();

		// Wire dependencies into cache manager.
		$this->cache_manager->set_analytics( $this->analytics );
		$this->cache_manager->set_rules( $this->rules );
		$this->cache_manager->init();

		// Cache warmer.
		$this->warmer = new Cache_Warmer( $this->cache_manager, $this->analytics );
		$this->warmer->init();

		// Cache invalidator.
		$this->invalidator = new Cache_Invalidator( $this->cache_manager, $this->analytics );
		$this->invalidator->init();

		// Deploy detector.
		$deploy_detector = Deploy_Detector::get_instance( $this->cache_manager, $this->warmer );
		$deploy_detector->init();

		// Admin settings page and AJAX handlers.
		if ( is_admin() ) {
			$settings_page = new Settings_Page(
				$this->cache_manager,
				$this->analytics,
				$this->warmer,
				$this->rules,
				$this->invalidator
			);
			$settings_page->init();

			$ajax_handler = new Ajax_Handler(
				$this->cache_manager,
				$this->analytics,
				$this->warmer,
				$this->rules
			);
			$ajax_handler->init();
		}

		// Register WP-CLI commands.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			$cli = new ACL_CLI( $this->cache_manager, $this->analytics, $this->warmer, $this->rules );
			\WP_CLI::add_command( 'acl', $cli );
		}
	}

	/**
	 * Handle plugin activation by creating required database tables.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		Cache_Analytics::create_tables();
	}

	/**
	 * Handle plugin deactivation by flushing caches and unscheduling cron events.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$manager = new Cache_Manager();
		$manager->flush_all();

		// Unschedule cron events.
		$warmer_timestamp = wp_next_scheduled( Cache_Warmer::CRON_HOOK );
		if ( $warmer_timestamp ) {
			wp_unschedule_event( $warmer_timestamp, Cache_Warmer::CRON_HOOK );
		}

		$analytics_timestamp = wp_next_scheduled( 'acl_analytics_cleanup' );
		if ( $analytics_timestamp ) {
			wp_unschedule_event( $analytics_timestamp, 'acl_analytics_cleanup' );
		}
	}

	/**
	 * Get the cache manager instance.
	 *
	 * @since 3.0.0
	 *
	 * @return Cache_Manager
	 */
	public function get_cache_manager(): Cache_Manager {
		return $this->cache_manager;
	}

	/**
	 * Get the analytics instance.
	 *
	 * @since 3.0.0
	 *
	 * @return Cache_Analytics
	 */
	public function get_analytics(): Cache_Analytics {
		return $this->analytics;
	}

	/**
	 * Get the cache warmer instance.
	 *
	 * @since 3.0.0
	 *
	 * @return Cache_Warmer
	 */
	public function get_warmer(): Cache_Warmer {
		return $this->warmer;
	}

	/**
	 * Get the cache rules instance.
	 *
	 * @since 3.0.0
	 *
	 * @return Cache_Rules
	 */
	public function get_rules(): Cache_Rules {
		return $this->rules;
	}

	/**
	 * Get the cache invalidator instance.
	 *
	 * @since 3.0.0
	 *
	 * @return Cache_Invalidator
	 */
	public function get_invalidator(): Cache_Invalidator {
		return $this->invalidator;
	}
}
