<?php
/**
 * Plugin Name: API Cache Layer
 * Plugin URI:  https://labanthegreat.com/plugins/api-cache-layer
 * Description: Transparent caching layer for WordPress REST API responses with configurable TTL, analytics, cache warming, advanced rules, and cache invalidation.
 * Version:     3.0.0
 * Author:      Laban The Great
 * Author URI:  https://labanthegreat.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: api-cache-layer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'ACL_VERSION', '3.0.0' );
define( 'ACL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACL_PLUGIN_FILE', __FILE__ );

// Load the autoloader (cannot be autoloaded itself).
require_once ACL_PLUGIN_DIR . 'src/class-autoloader.php';

// Register the PSR-4 autoloader.
$acl_autoloader = new Jestart\ApiCacheLayer\Autoloader( ACL_PLUGIN_DIR );
$acl_autoloader->register();

/**
 * Bootstrap the plugin on plugins_loaded.
 *
 * @since 3.0.0
 *
 * @return void
 */
function acl_init(): void {
	Jestart\ApiCacheLayer\Plugin::get_instance()->init();
}
add_action( 'plugins_loaded', 'acl_init' );

// Activation and deactivation hooks.
register_activation_hook( __FILE__, array( Jestart\ApiCacheLayer\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Jestart\ApiCacheLayer\Plugin::class, 'deactivate' ) );
