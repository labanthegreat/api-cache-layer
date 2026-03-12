<?php
/**
 * Uninstall script for API Cache Layer.
 *
 * Removes all plugin data from the database when the plugin is deleted
 * via the WordPress admin interface.
 *
 * @package API_Cache_Layer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Options ──────────────────────────────────────────────────────────────────

$options = array(
	'acl_settings',
	'acl_cache_index',
	'acl_cache_stats',
	'acl_access_patterns',
	'acl_endpoint_stats',
	'acl_cache_rules',
	'acl_cache_tags',
	'acl_warmer_settings',
	'acl_warmer_status',
	'acl_invalidation_webhooks',
	'acl_cascade_rules',
	'acl_deploy_log',
	'acl_deploy_webhook_secret',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Transients ───────────────────────────────────────────────────────────────

// Delete all acl_cache_* transients (cached REST responses).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_acl_cache_%'
	    OR option_name LIKE '_transient_timeout_acl_cache_%'"
);

// Delete all acl_rate_* transients (rate limit counters).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_acl_rate_%'
	    OR option_name LIKE '_transient_timeout_acl_rate_%'"
);

// ── Cron events ──────────────────────────────────────────────────────────────

wp_clear_scheduled_hook( 'acl_cache_warm_cron' );
wp_clear_scheduled_hook( 'acl_analytics_cleanup' );
wp_clear_scheduled_hook( 'acl_deploy_warm_cron' );
wp_clear_scheduled_hook( 'acl_revalidate_cache' );

// ── Custom database tables ──────────────────────────────────────────────────

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}acl_analytics" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}acl_invalidation_log" );
