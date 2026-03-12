<?php
/**
 * Admin settings page for API Cache Layer.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer\Admin;

use Jestart\ApiCacheLayer\Cache_Manager;
use Jestart\ApiCacheLayer\Cache_Analytics;
use Jestart\ApiCacheLayer\Cache_Warmer;
use Jestart\ApiCacheLayer\Cache_Rules;
use Jestart\ApiCacheLayer\Cache_Invalidator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings_Page
 *
 * Registers a settings page with tabbed UI for settings, analytics,
 * cache rules, cache browser, warmer controls, and real-time monitoring.
 *
 * @since 1.0.0
 * @package API_Cache_Layer
 */
class Settings_Page {

	/**
	 * Option group name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION_GROUP = 'acl_settings_group';

	/**
	 * Option name in the database.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION_NAME = 'acl_settings';

	/**
	 * AJAX nonce action.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const FLUSH_NONCE = 'acl_flush_cache';

	/**
	 * Cache manager instance.
	 *
	 * @since 1.0.0
	 * @var Cache_Manager
	 */
	private Cache_Manager $cache_manager;

	/**
	 * Analytics instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Analytics|null
	 */
	private ?Cache_Analytics $analytics = null;

	/**
	 * Cache warmer instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Warmer|null
	 */
	private ?Cache_Warmer $warmer = null;

	/**
	 * Cache rules instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Rules|null
	 */
	private ?Cache_Rules $rules = null;

	/**
	 * Cache invalidator instance.
	 *
	 * @since 2.0.0
	 * @var Cache_Invalidator|null
	 */
	private ?Cache_Invalidator $invalidator = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Cache_Manager          $cache_manager Cache manager instance.
	 * @param Cache_Analytics|null   $analytics     Analytics instance.
	 * @param Cache_Warmer|null      $warmer        Cache warmer instance.
	 * @param Cache_Rules|null       $rules         Cache rules instance.
	 * @param Cache_Invalidator|null $invalidator   Cache invalidator instance.
	 */
	public function __construct(
		Cache_Manager $cache_manager,
		?Cache_Analytics $analytics = null,
		?Cache_Warmer $warmer = null,
		?Cache_Rules $rules = null,
		?Cache_Invalidator $invalidator = null
	) {
		$this->cache_manager = $cache_manager;
		$this->analytics     = $analytics;
		$this->warmer        = $warmer;
		$this->rules         = $rules;
		$this->invalidator   = $invalidator;
	}

	/**
	 * Register admin hooks for menu, settings, assets, and AJAX handlers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add settings page to the Settings menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'API Cache Layer', 'api-cache-layer' ),
			__( 'API Cache Layer', 'api-cache-layer' ),
			'manage_options',
			'api-cache-layer',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS on the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_api-cache-layer' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'acl-admin',
			ACL_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ACL_VERSION
		);

		wp_enqueue_script(
			'acl-admin',
			ACL_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ACL_VERSION,
			true
		);

		$warmer_status = $this->warmer ? $this->warmer->get_status() : array();
		$warmer_settings = $this->warmer ? $this->warmer->get_settings() : array();

		wp_localize_script( 'acl-admin', 'aclAdmin', array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( self::FLUSH_NONCE ),
			'warmerStatus'    => $warmer_status,
			'warmerSettings'  => $warmer_settings,
			'i18n'            => array(
				'flushing'          => __( 'Flushing...', 'api-cache-layer' ),
				'flushed'           => __( 'Cache flushed!', 'api-cache-layer' ),
				'error'             => __( 'Error flushing cache.', 'api-cache-layer' ),
				'confirm'           => __( 'Are you sure you want to flush the entire cache?', 'api-cache-layer' ),
				'warming'           => __( 'Warming cache...', 'api-cache-layer' ),
				'warmed'            => __( 'Cache warmed!', 'api-cache-layer' ),
				'saving'            => __( 'Saving...', 'api-cache-layer' ),
				'saved'             => __( 'Saved!', 'api-cache-layer' ),
				'deleting'          => __( 'Deleting...', 'api-cache-layer' ),
				'deleted'           => __( 'Deleted!', 'api-cache-layer' ),
				'confirmDelete'     => __( 'Are you sure you want to delete this rule?', 'api-cache-layer' ),
				'noData'            => __( 'No data available.', 'api-cache-layer' ),
				'cancel'            => __( 'Cancel', 'api-cache-layer' ),
				'confirmBtn'        => __( 'Confirm', 'api-cache-layer' ),
				'copiedToClipboard' => __( 'Copied to clipboard!', 'api-cache-layer' ),
				'flushCache'        => __( 'Flush Cache', 'api-cache-layer' ),
				'analyticsExported' => __( 'Analytics exported successfully.', 'api-cache-layer' ),
				'csvExported'       => __( 'CSV exported successfully.', 'api-cache-layer' ),
				'addCacheRule'      => __( 'Add Cache Rule', 'api-cache-layer' ),
				'editCacheRule'     => __( 'Edit Cache Rule', 'api-cache-layer' ),
				'delete'            => __( 'Delete', 'api-cache-layer' ),
				'active'            => __( 'Active', 'api-cache-layer' ),
				'inactive'          => __( 'Inactive', 'api-cache-layer' ),
				'ruleStatusUpdated' => __( 'Rule status updated.', 'api-cache-layer' ),
				'ruleOrderUpdated'  => __( 'Rule order updated. Save to persist.', 'api-cache-layer' ),
				'entryInvalidated'  => __( 'Cache entry invalidated.', 'api-cache-layer' ),
				'enterPattern'      => __( 'Enter a route pattern to flush.', 'api-cache-layer' ),
				/* translators: %s: route pattern */
				'confirmFlushPattern' => __( 'Flush all cache entries matching "%s"?', 'api-cache-layer' ),
				'flushMatching'     => __( 'Flush Matching', 'api-cache-layer' ),
				'loadingEntries'    => __( 'Loading cache entries...', 'api-cache-layer' ),
				'invalidate'        => __( 'Invalidate', 'api-cache-layer' ),
				/* translators: 1: start number, 2: end number, 3: total number */
				'showingEntries'    => __( 'Showing %1$s-%2$s of %3$s entries', 'api-cache-layer' ),
				'completed'         => __( 'Completed', 'api-cache-layer' ),
				'expired'           => __( 'expired', 'api-cache-layer' ),
				'clickToCopy'       => __( 'Click to copy', 'api-cache-layer' ),
			),
		) );
	}

	/**
	 * Register settings, sections, and fields with the WordPress Settings API.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->cache_manager->get_defaults(),
			)
		);

		// General section.
		add_settings_section(
			'acl_general',
			__( 'General Settings', 'api-cache-layer' ),
			'__return_null',
			'api-cache-layer'
		);

		add_settings_field( 'acl_enabled', __( 'Enable Caching', 'api-cache-layer' ), array( $this, 'render_enabled_field' ), 'api-cache-layer', 'acl_general' );
		add_settings_field(
			'acl_default_ttl',
			__( 'Default TTL (seconds)', 'api-cache-layer' ) . $this->render_help_tip( __( 'Time To Live: how long API responses are stored before being refreshed. Higher values reduce database load but may serve stale data.', 'api-cache-layer' ) ),
			array( $this, 'render_ttl_field' ),
			'api-cache-layer',
			'acl_general'
		);
		add_settings_field(
			'acl_storage_method',
			__( 'Storage Method', 'api-cache-layer' ) . $this->render_help_tip( __( 'Transients store cache in the database. Object Cache (Redis/Memcached) is faster but requires a persistent caching plugin.', 'api-cache-layer' ) ),
			array( $this, 'render_storage_field' ),
			'api-cache-layer',
			'acl_general'
		);
		add_settings_field(
			'acl_compression',
			__( 'Compression', 'api-cache-layer' ) . $this->render_help_tip( __( 'Compresses cached data with gzip to reduce storage size. Recommended for large JSON responses. Adds minimal CPU overhead.', 'api-cache-layer' ) ),
			array( $this, 'render_compression_field' ),
			'api-cache-layer',
			'acl_general'
		);
		add_settings_field(
			'acl_etag_support',
			__( 'ETag Support', 'api-cache-layer' ) . $this->render_help_tip( __( 'Enables HTTP conditional requests. Clients can send If-None-Match headers to receive 304 Not Modified responses, saving bandwidth.', 'api-cache-layer' ) ),
			array( $this, 'render_etag_field' ),
			'api-cache-layer',
			'acl_general'
		);
		add_settings_field(
			'acl_adaptive_ttl',
			__( 'Adaptive TTL', 'api-cache-layer' ) . $this->render_help_tip( __( 'Dynamically adjusts cache duration based on how often each endpoint is accessed. Popular routes get longer TTLs, rarely used routes get shorter ones.', 'api-cache-layer' ) ),
			array( $this, 'render_adaptive_ttl_field' ),
			'api-cache-layer',
			'acl_general'
		);
		add_settings_field( 'acl_excluded_endpoints', __( 'Excluded Endpoints', 'api-cache-layer' ), array( $this, 'render_excluded_field' ), 'api-cache-layer', 'acl_general' );
	}

	/**
	 * Sanitize submitted settings before saving to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Raw input from the settings form.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();

		$sanitized['enabled']            = ! empty( $input['enabled'] );
		$sanitized['default_ttl']        = absint( $input['default_ttl'] ?? 3600 );
		$sanitized['storage_method']     = in_array( $input['storage_method'] ?? '', array( 'transient', 'object_cache' ), true )
			? $input['storage_method']
			: 'transient';
		$sanitized['excluded_endpoints'] = sanitize_textarea_field( $input['excluded_endpoints'] ?? '' );
		$sanitized['compression']        = ! empty( $input['compression'] );
		$sanitized['etag_support']       = ! empty( $input['etag_support'] );
		$sanitized['adaptive_ttl']       = ! empty( $input['adaptive_ttl'] );

		if ( $sanitized['default_ttl'] < 60 ) {
			$sanitized['default_ttl'] = 60;
		}

		return $sanitized;
	}

	/**
	 * Render a help tooltip icon with hover content.
	 *
	 * @since 2.1.0
	 *
	 * @param string $text Tooltip text to display on hover.
	 * @return string HTML for the tooltip.
	 */
	private function render_help_tip( string $text ): string {
		return ' <span class="acl-help-tip"><span class="dashicons dashicons-editor-help"></span><span class="acl-help-tip__content">' . esc_html( $text ) . '</span></span>';
	}

	/**
	 * Render the enable/disable checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_enabled_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->cache_manager->get_defaults() );
		$checked  = ! empty( $settings['enabled'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable REST API response caching', 'api-cache-layer' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the TTL input field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_ttl_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->cache_manager->get_defaults() );
		$ttl      = absint( $settings['default_ttl'] ?? 3600 );
		?>
		<input type="number" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_ttl]" value="<?php echo esc_attr( $ttl ); ?>" min="60" step="1" class="small-text">
		<p class="description"><?php esc_html_e( 'How long cached responses should be stored (minimum 60 seconds).', 'api-cache-layer' ); ?></p>
		<?php
	}

	/**
	 * Render the storage method select dropdown.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_storage_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->cache_manager->get_defaults() );
		$method   = $settings['storage_method'] ?? 'transient';
		$has_ext  = wp_using_ext_object_cache();
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[storage_method]">
			<option value="transient" <?php selected( $method, 'transient' ); ?>><?php esc_html_e( 'Transients (database)', 'api-cache-layer' ); ?></option>
			<option value="object_cache" <?php selected( $method, 'object_cache' ); ?> <?php disabled( ! $has_ext ); ?>><?php esc_html_e( 'Object Cache (Redis/Memcached)', 'api-cache-layer' ); ?></option>
		</select>
		<?php if ( ! $has_ext ) : ?>
			<p class="description"><?php esc_html_e( 'Object cache requires a persistent object cache plugin (Redis, Memcached, etc.).', 'api-cache-layer' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the compression checkbox field.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_compression_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->cache_manager->get_defaults() );
		$checked  = ! empty( $settings['compression'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[compression]" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Compress cached responses with gzip (recommended for large responses)', 'api-cache-layer' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the ETag support checkbox field.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_etag_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->cache_manager->get_defaults() );
		$checked  = ! empty( $settings['etag_support'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[etag_support]" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable ETag and conditional request support (304 Not Modified)', 'api-cache-layer' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the adaptive TTL checkbox field.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_adaptive_ttl_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->cache_manager->get_defaults() );
		$checked  = ! empty( $settings['adaptive_ttl'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[adaptive_ttl]" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Automatically adjust TTL based on endpoint access patterns', 'api-cache-layer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Frequently accessed routes get longer TTLs; rarely accessed routes get shorter ones.', 'api-cache-layer' ); ?></p>
		<?php
	}

	/**
	 * Render the excluded endpoints textarea field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_excluded_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->cache_manager->get_defaults() );
		$excluded = $settings['excluded_endpoints'] ?? '';
		?>
		<textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[excluded_endpoints]" rows="6" cols="50" class="large-text code"><?php echo esc_textarea( $excluded ); ?></textarea>
		<p class="description"><?php esc_html_e( 'One endpoint pattern per line. Use * as wildcard. Example: /wp/v2/users/*', 'api-cache-layer' ); ?></p>
		<?php
	}

	/**
	 * Render the settings page with tabbed navigation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'settings' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$stats      = $this->cache_manager->get_stats();
		$settings   = get_option( self::OPTION_NAME, $this->cache_manager->get_defaults() );
		$is_enabled = ! empty( $settings['enabled'] );
		$tabs       = array(
			'settings'  => array( 'label' => __( 'Settings', 'api-cache-layer' ), 'icon' => 'admin-generic' ),
			'analytics' => array( 'label' => __( 'Analytics', 'api-cache-layer' ), 'icon' => 'chart-bar' ),
			'rules'     => array( 'label' => __( 'Cache Rules', 'api-cache-layer' ), 'icon' => 'list-view' ),
			'browser'   => array( 'label' => __( 'Cache Browser', 'api-cache-layer' ), 'icon' => 'database' ),
			'warmer'    => array( 'label' => __( 'Cache Warmer', 'api-cache-layer' ), 'icon' => 'update' ),
			'monitor'   => array( 'label' => __( 'Monitor', 'api-cache-layer' ), 'icon' => 'visibility' ),
		);

		$shortcut_key = strpos( php_uname( 's' ), 'Darwin' ) !== false ? 'Cmd' : 'Ctrl';
		?>
		<div class="wrap acl-settings-wrap">

			<div class="acl-page-header">
				<div class="acl-page-header-info">
					<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<p class="acl-page-subtitle">
						<?php if ( $is_enabled ) : ?>
							<span class="acl-cache-status-indicator acl-cache-active">
								<span class="acl-status-dot"></span>
								<?php esc_html_e( 'Active', 'api-cache-layer' ); ?>
							</span>
						<?php else : ?>
							<span class="acl-cache-status-indicator acl-cache-inactive">
								<?php esc_html_e( 'Inactive', 'api-cache-layer' ); ?>
							</span>
						<?php endif; ?>
						<?php
						printf(
							/* translators: %s: storage method name */
							esc_html__( 'Storage: %s', 'api-cache-layer' ),
							esc_html( ucfirst( str_replace( '_', ' ', $settings['storage_method'] ?? 'transient' ) ) )
						);
						?>
					</p>
				</div>
				<div class="acl-header-stats">
					<div class="acl-header-stat acl-header-stat--success">
						<span class="acl-header-stat-value"><?php echo esc_html( $stats['hit_rate'] ); ?>%</span>
						<span class="acl-header-stat-label"><?php esc_html_e( 'Hit Rate', 'api-cache-layer' ); ?></span>
					</div>
					<div class="acl-header-stat">
						<span class="acl-header-stat-value"><?php echo esc_html( number_format_i18n( $stats['total_cached'] ) ); ?></span>
						<span class="acl-header-stat-label"><?php esc_html_e( 'Cached', 'api-cache-layer' ); ?></span>
					</div>
					<?php if ( ! empty( $stats['total_size'] ) ) : ?>
					<div class="acl-header-stat">
						<span class="acl-header-stat-value"><?php echo esc_html( size_format( $stats['total_size'] ) ); ?></span>
						<span class="acl-header-stat-label"><?php esc_html_e( 'Size', 'api-cache-layer' ); ?></span>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Quick Actions Bar -->
			<div class="acl-quick-actions">
				<span class="acl-quick-actions-label"><?php esc_html_e( 'Quick Actions', 'api-cache-layer' ); ?></span>
				<button type="button" id="acl-quick-flush" class="acl-action-btn acl-action-danger">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Flush All', 'api-cache-layer' ); ?>
					<span class="acl-shortcut-hint"><?php echo esc_html( $shortcut_key ); ?>+F</span>
				</button>
				<button type="button" id="acl-quick-warm" class="acl-action-btn">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Warm Cache', 'api-cache-layer' ); ?>
				</button>
				<button type="button" id="acl-quick-export" class="acl-action-btn">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export Analytics', 'api-cache-layer' ); ?>
				</button>
			</div>

			<!-- Dashboard Analytics Cards -->
			<?php $this->render_dashboard_cards( $stats ); ?>

			<nav class="nav-tab-wrapper acl-nav-tabs">
				<?php foreach ( $tabs as $tab_key => $tab_info ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key, admin_url( 'options-general.php?page=api-cache-layer' ) ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>"
					   data-tab="<?php echo esc_attr( $tab_key ); ?>">
						<span class="dashicons dashicons-<?php echo esc_attr( $tab_info['icon'] ); ?>"></span>
						<?php echo esc_html( $tab_info['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $active_tab ) {
				case 'analytics':
					$this->render_analytics_tab();
					break;
				case 'rules':
					$this->render_rules_tab();
					break;
				case 'browser':
					$this->render_browser_tab();
					break;
				case 'warmer':
					$this->render_warmer_tab();
					break;
				case 'monitor':
					$this->render_monitor_tab();
					break;
				default:
					$this->render_settings_tab( $stats );
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the dashboard analytics cards shown on all tabs.
	 *
	 * @since 3.0.0
	 *
	 * @param array $stats Cache statistics.
	 * @return void
	 */
	private function render_dashboard_cards( array $stats ): void {
		$summary = $this->analytics ? $this->analytics->get_summary( 7 ) : array();
		$avg_response = $summary['avg_cached_time'] ?? 0;
		?>
		<div class="acl-dashboard-cards">
			<div class="acl-dashboard-card">
				<div class="acl-dashboard-card-header">
					<span class="acl-dashboard-card-label"><?php esc_html_e( 'Hit Rate', 'api-cache-layer' ); ?></span>
					<div class="acl-dashboard-card-icon acl-icon-indigo">
						<span class="dashicons dashicons-performance"></span>
					</div>
				</div>
				<div class="acl-dashboard-card-value"><?php echo esc_html( $stats['hit_rate'] ); ?>%</div>
				<?php if ( $stats['hit_rate'] >= 80 ) : ?>
					<span class="acl-dashboard-card-trend acl-trend-up"><span class="acl-trend-arrow">&#8593;</span> <?php esc_html_e( 'Excellent', 'api-cache-layer' ); ?></span>
				<?php elseif ( $stats['hit_rate'] >= 50 ) : ?>
					<span class="acl-dashboard-card-trend acl-trend-up"><span class="acl-trend-arrow">&#8594;</span> <?php esc_html_e( 'Good', 'api-cache-layer' ); ?></span>
				<?php else : ?>
					<span class="acl-dashboard-card-trend acl-trend-down"><span class="acl-trend-arrow">&#8595;</span> <?php esc_html_e( 'Needs attention', 'api-cache-layer' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="acl-dashboard-card">
				<div class="acl-dashboard-card-header">
					<span class="acl-dashboard-card-label"><?php esc_html_e( 'Total Cached', 'api-cache-layer' ); ?></span>
					<div class="acl-dashboard-card-icon acl-icon-green">
						<span class="dashicons dashicons-database"></span>
					</div>
				</div>
				<div class="acl-dashboard-card-value"><?php echo esc_html( number_format_i18n( $stats['total_cached'] ) ); ?></div>
				<span class="acl-dashboard-card-trend acl-trend-up"><span class="acl-trend-arrow">&#8593;</span> <?php esc_html_e( 'responses', 'api-cache-layer' ); ?></span>
			</div>

			<div class="acl-dashboard-card">
				<div class="acl-dashboard-card-header">
					<span class="acl-dashboard-card-label"><?php esc_html_e( 'Avg Response Time', 'api-cache-layer' ); ?></span>
					<div class="acl-dashboard-card-icon acl-icon-amber">
						<span class="dashicons dashicons-clock"></span>
					</div>
				</div>
				<div class="acl-dashboard-card-value"><?php echo esc_html( $avg_response ); ?>ms</div>
				<?php if ( $avg_response > 0 && $avg_response < 50 ) : ?>
					<span class="acl-dashboard-card-trend acl-trend-up"><span class="acl-trend-arrow">&#8593;</span> <?php esc_html_e( 'Fast', 'api-cache-layer' ); ?></span>
				<?php elseif ( $avg_response > 0 ) : ?>
					<span class="acl-dashboard-card-trend acl-trend-down"><span class="acl-trend-arrow">&#8595;</span> <?php esc_html_e( 'cached avg', 'api-cache-layer' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="acl-dashboard-card">
				<div class="acl-dashboard-card-header">
					<span class="acl-dashboard-card-label"><?php esc_html_e( 'Cache Size', 'api-cache-layer' ); ?></span>
					<div class="acl-dashboard-card-icon acl-icon-rose">
						<span class="dashicons dashicons-media-archive"></span>
					</div>
				</div>
				<div class="acl-dashboard-card-value"><?php echo esc_html( ! empty( $stats['total_size'] ) ? size_format( $stats['total_size'] ) : '0 B' ); ?></div>
				<span class="acl-dashboard-card-trend acl-trend-up"><span class="acl-trend-arrow">&#8594;</span> <?php esc_html_e( 'storage used', 'api-cache-layer' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the settings tab with statistics overview and settings form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $stats Cache statistics from the cache manager.
	 * @return void
	 */
	private function render_settings_tab( array $stats ): void {
		?>
		<div class="acl-tab-content" id="acl-tab-settings">
			<div class="acl-stats-panel">
				<h2><?php esc_html_e( 'Cache Statistics', 'api-cache-layer' ); ?></h2>

				<!-- Hit Rate Ring Chart -->
				<div class="acl-ring-chart-container">
					<div class="acl-ring-chart">
						<svg viewBox="0 0 140 140">
							<circle class="acl-ring-bg" cx="70" cy="70" r="65"></circle>
							<circle class="acl-ring-fill" cx="70" cy="70" r="65" data-rate="<?php echo esc_attr( $stats['hit_rate'] ); ?>"></circle>
						</svg>
						<div class="acl-ring-chart-label">
							<div class="acl-ring-chart-value"><?php echo esc_html( $stats['hit_rate'] ); ?>%</div>
							<div class="acl-ring-chart-sublabel"><?php esc_html_e( 'Hit Rate', 'api-cache-layer' ); ?></div>
						</div>
					</div>
					<div class="acl-ring-chart-legend">
						<div class="acl-ring-legend-item">
							<span class="acl-ring-legend-dot acl-dot-hits"></span>
							<div class="acl-ring-legend-info">
								<span class="acl-ring-legend-label"><?php esc_html_e( 'Cache Hits', 'api-cache-layer' ); ?></span>
								<span class="acl-ring-legend-value"><?php echo esc_html( number_format_i18n( $stats['hits'] ) ); ?></span>
							</div>
						</div>
						<div class="acl-ring-legend-item">
							<span class="acl-ring-legend-dot acl-dot-misses"></span>
							<div class="acl-ring-legend-info">
								<span class="acl-ring-legend-label"><?php esc_html_e( 'Cache Misses', 'api-cache-layer' ); ?></span>
								<span class="acl-ring-legend-value"><?php echo esc_html( number_format_i18n( $stats['misses'] ) ); ?></span>
							</div>
						</div>
					</div>
				</div>

				<div class="acl-stats-grid">
					<div class="acl-stat-card">
						<span class="dashicons dashicons-performance acl-stat-icon"></span>
						<span class="acl-stat-value"><?php echo esc_html( $stats['hit_rate'] ); ?>%</span>
						<span class="acl-stat-label"><?php esc_html_e( 'Hit Rate', 'api-cache-layer' ); ?></span>
					</div>
					<div class="acl-stat-card">
						<span class="dashicons dashicons-yes-alt acl-stat-icon"></span>
						<span class="acl-stat-value"><?php echo esc_html( number_format_i18n( $stats['hits'] ) ); ?></span>
						<span class="acl-stat-label"><?php esc_html_e( 'Cache Hits', 'api-cache-layer' ); ?></span>
					</div>
					<div class="acl-stat-card">
						<span class="dashicons dashicons-dismiss acl-stat-icon"></span>
						<span class="acl-stat-value"><?php echo esc_html( number_format_i18n( $stats['misses'] ) ); ?></span>
						<span class="acl-stat-label"><?php esc_html_e( 'Cache Misses', 'api-cache-layer' ); ?></span>
					</div>
					<div class="acl-stat-card">
						<span class="dashicons dashicons-database acl-stat-icon"></span>
						<span class="acl-stat-value"><?php echo esc_html( number_format_i18n( $stats['total_cached'] ) ); ?></span>
						<span class="acl-stat-label"><?php esc_html_e( 'Cached Responses', 'api-cache-layer' ); ?></span>
					</div>
					<?php if ( ! empty( $stats['total_size'] ) ) : ?>
					<div class="acl-stat-card">
						<span class="dashicons dashicons-media-archive acl-stat-icon"></span>
						<span class="acl-stat-value"><?php echo esc_html( size_format( $stats['total_size'] ) ); ?></span>
						<span class="acl-stat-label"><?php esc_html_e( 'Total Size', 'api-cache-layer' ); ?></span>
					</div>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $stats['last_flush'] ) ) : ?>
					<p class="acl-last-flush">
						<?php
						printf(
							/* translators: %s: formatted date/time of last cache flush */
							esc_html__( 'Last flushed: %s', 'api-cache-layer' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stats['last_flush'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<p>
					<button type="button" id="acl-flush-cache" class="button button-secondary">
						<?php esc_html_e( 'Flush All Cache', 'api-cache-layer' ); ?>
					</button>
					<span id="acl-flush-status" class="acl-flush-status"></span>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'api-cache-layer' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the analytics tab with charts and endpoint tables.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_analytics_tab(): void {
		$summary      = $this->analytics ? $this->analytics->get_summary( 7 ) : array();
		$trend_data   = $this->analytics ? $this->analytics->get_trend_data( 'daily', 14 ) : array();
		$top_cached   = $this->analytics ? $this->analytics->get_top_cached( 10 ) : array();
		$top_missed   = $this->analytics ? $this->analytics->get_top_missed( 10 ) : array();
		$response_cmp = $this->analytics ? $this->analytics->get_response_time_comparison( 10 ) : array();
		?>
		<div class="acl-tab-content" id="acl-tab-analytics">
			<div class="acl-analytics-controls">
				<select id="acl-analytics-period">
					<option value="1"><?php esc_html_e( 'Last 24 Hours', 'api-cache-layer' ); ?></option>
					<option value="7" selected><?php esc_html_e( 'Last 7 Days', 'api-cache-layer' ); ?></option>
					<option value="30"><?php esc_html_e( 'Last 30 Days', 'api-cache-layer' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 Days', 'api-cache-layer' ); ?></option>
				</select>
				<button type="button" id="acl-refresh-analytics" class="button"><?php esc_html_e( 'Refresh', 'api-cache-layer' ); ?></button>
				<button type="button" id="acl-export-csv" class="button"><?php esc_html_e( 'Export CSV', 'api-cache-layer' ); ?></button>
				<div class="acl-auto-refresh-controls">
					<label>
						<input type="checkbox" id="acl-analytics-auto-refresh">
						<?php esc_html_e( 'Auto-refresh', 'api-cache-layer' ); ?>
					</label>
					<select id="acl-analytics-refresh-interval">
						<option value="15">15s</option>
						<option value="30" selected>30s</option>
						<option value="60">60s</option>
						<option value="120">2m</option>
					</select>
				</div>
			</div>

			<div class="acl-stats-grid acl-analytics-summary">
				<div class="acl-stat-card">
					<span class="dashicons dashicons-rest-api acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-a-requests"><?php echo esc_html( number_format_i18n( $summary['total_requests'] ?? 0 ) ); ?></span>
					<span class="acl-stat-label"><?php esc_html_e( 'Total Requests', 'api-cache-layer' ); ?></span>
				</div>
				<div class="acl-stat-card">
					<span class="dashicons dashicons-performance acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-a-hitrate"><?php echo esc_html( $summary['hit_rate'] ?? 0 ); ?>%</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Hit Rate', 'api-cache-layer' ); ?></span>
				</div>
				<div class="acl-stat-card">
					<span class="dashicons dashicons-clock acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-a-cached-time"><?php echo esc_html( $summary['avg_cached_time'] ?? 0 ); ?>ms</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Avg Cached Time', 'api-cache-layer' ); ?></span>
				</div>
				<div class="acl-stat-card">
					<span class="dashicons dashicons-clock acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-a-uncached-time"><?php echo esc_html( $summary['avg_uncached_time'] ?? 0 ); ?>ms</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Avg Uncached Time', 'api-cache-layer' ); ?></span>
				</div>
				<div class="acl-stat-card acl-stat-highlight">
					<span class="dashicons dashicons-saved acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-a-time-saved"><?php echo esc_html( number_format_i18n( $summary['time_saved_ms'] ?? 0 ) ); ?>ms</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Est. Time Saved', 'api-cache-layer' ); ?></span>
				</div>
			</div>

			<!-- Hit Rate Chart -->
			<div class="acl-chart-container">
				<h3><?php esc_html_e( 'Hit Rate Over Time', 'api-cache-layer' ); ?></h3>
				<div class="acl-chart" id="acl-hitrate-chart">
					<div class="acl-bar-chart" data-chart="hitrate">
						<?php foreach ( $trend_data as $point ) :
							$total    = ( (int) $point['hits'] ) + ( (int) $point['misses'] );
							$hit_rate = $total > 0 ? round( ( (int) $point['hits'] / $total ) * 100 ) : 0;
						?>
							<div class="acl-bar-group" title="<?php echo esc_attr( $point['period_key'] . ': ' . $hit_rate . '%' ); ?>">
								<div class="acl-bar" style="height: <?php echo esc_attr( max( 2, $hit_rate ) ); ?>%;">
									<span class="acl-bar-label"><?php echo esc_html( $hit_rate ); ?>%</span>
								</div>
								<span class="acl-bar-axis"><?php echo esc_html( substr( $point['period_key'], 5 ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<!-- Response Time Comparison Chart -->
			<div class="acl-chart-container">
				<h3><?php esc_html_e( 'Response Time: Cached vs Uncached', 'api-cache-layer' ); ?></h3>
				<div class="acl-chart" id="acl-response-chart">
					<div class="acl-comparison-chart">
						<?php foreach ( array_slice( $response_cmp, 0, 8 ) as $item ) : ?>
							<div class="acl-cmp-row">
								<span class="acl-cmp-route" title="<?php echo esc_attr( $item['route'] ); ?>"><?php echo esc_html( substr( $item['route'], 0, 40 ) ); ?></span>
								<div class="acl-cmp-bars">
									<div class="acl-cmp-bar acl-cmp-cached" style="width: <?php echo esc_attr( min( 100, max( 5, $item['avg_cached'] / max( 1, $item['avg_uncached'] ) * 100 ) ) ); ?>%;">
										<?php echo esc_html( $item['avg_cached'] ); ?>ms
									</div>
									<div class="acl-cmp-bar acl-cmp-uncached" style="width: 100%;">
										<?php echo esc_html( $item['avg_uncached'] ); ?>ms
									</div>
								</div>
								<span class="acl-cmp-improvement"><?php echo esc_html( $item['improvement'] ); ?>%</span>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="acl-chart-legend">
						<span class="acl-legend-item"><span class="acl-legend-color acl-color-cached"></span> <?php esc_html_e( 'Cached', 'api-cache-layer' ); ?></span>
						<span class="acl-legend-item"><span class="acl-legend-color acl-color-uncached"></span> <?php esc_html_e( 'Uncached', 'api-cache-layer' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Top Endpoints Tables -->
			<div class="acl-two-col">
				<div class="acl-col">
					<h3><?php esc_html_e( 'Top 10 Most Cached', 'api-cache-layer' ); ?></h3>
					<table class="widefat striped acl-endpoints-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Route', 'api-cache-layer' ); ?></th>
								<th><?php esc_html_e( 'Hits', 'api-cache-layer' ); ?></th>
								<th><?php esc_html_e( 'Hit Rate', 'api-cache-layer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $top_cached ) ) : ?>
								<tr><td colspan="3"><?php esc_html_e( 'No data available.', 'api-cache-layer' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $top_cached as $item ) :
									$total = $item['hits'] + $item['misses'];
									$rate  = $total > 0 ? round( ( $item['hits'] / $total ) * 100, 1 ) : 0;
									$color = $rate >= 80 ? 'acl-rate-high' : ( $rate >= 50 ? 'acl-rate-medium' : 'acl-rate-low' );
								?>
									<tr>
										<td class="acl-route-cell" title="<?php echo esc_attr( $item['route'] ); ?>"><?php echo esc_html( $item['route'] ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $item['hits'] ) ); ?></td>
										<td><span class="acl-rate-badge <?php echo esc_attr( $color ); ?>"><?php echo esc_html( $rate ); ?>%</span></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<div class="acl-col">
					<h3><?php esc_html_e( 'Top 10 Most Missed', 'api-cache-layer' ); ?></h3>
					<table class="widefat striped acl-endpoints-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Route', 'api-cache-layer' ); ?></th>
								<th><?php esc_html_e( 'Misses', 'api-cache-layer' ); ?></th>
								<th><?php esc_html_e( 'Hits', 'api-cache-layer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $top_missed ) ) : ?>
								<tr><td colspan="3"><?php esc_html_e( 'No data available.', 'api-cache-layer' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $top_missed as $item ) : ?>
									<tr>
										<td class="acl-route-cell" title="<?php echo esc_attr( $item['route'] ); ?>"><?php echo esc_html( $item['route'] ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $item['misses'] ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $item['hits'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the cache rules tab with rule editor and rules table.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_rules_tab(): void {
		$rules = $this->rules ? $this->rules->get_rules() : array();
		?>
		<div class="acl-tab-content" id="acl-tab-rules">
			<div class="acl-rules-header">
				<div>
					<h2><?php esc_html_e( 'Cache Rules', 'api-cache-layer' ); ?></h2>
					<p class="description" style="margin: 4px 0 0;"><?php esc_html_e( 'Define per-route caching behavior. Drag rows to reorder priority. Rules are matched by priority (lower number = higher priority).', 'api-cache-layer' ); ?></p>
				</div>
				<button type="button" id="acl-add-rule" class="button button-primary"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;font-size:16px;width:16px;height:16px;"></span> <?php esc_html_e( 'Add Rule', 'api-cache-layer' ); ?></button>
			</div>

			<!-- Rule Editor Modal -->
			<div id="acl-rule-editor" class="acl-modal" style="display:none;">
				<div class="acl-modal-content">
					<h3 id="acl-rule-editor-title"><?php esc_html_e( 'Add Cache Rule', 'api-cache-layer' ); ?></h3>
					<input type="hidden" id="acl-rule-id" value="">
					<table class="form-table acl-rule-form">
						<tr>
							<th><?php esc_html_e( 'Route Pattern', 'api-cache-layer' ); ?></th>
							<td><input type="text" id="acl-rule-route" class="regular-text" placeholder="/wp/v2/posts*"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'TTL (seconds)', 'api-cache-layer' ); ?></th>
							<td><input type="number" id="acl-rule-ttl" class="small-text" min="60" value="3600"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Vary by Query Params', 'api-cache-layer' ); ?></th>
							<td><input type="text" id="acl-rule-vary-params" class="regular-text" placeholder="page,per_page,search"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Vary by User Role', 'api-cache-layer' ); ?></th>
							<td><label><input type="checkbox" id="acl-rule-vary-role"> <?php esc_html_e( 'Create separate cache per user role', 'api-cache-layer' ); ?></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Vary by Headers', 'api-cache-layer' ); ?></th>
							<td><input type="text" id="acl-rule-vary-headers" class="regular-text" placeholder="Accept-Language,X-Custom"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Skip if Params Present', 'api-cache-layer' ); ?></th>
							<td><input type="text" id="acl-rule-skip-params" class="regular-text" placeholder="preview,draft"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Stale TTL (seconds)', 'api-cache-layer' ); ?></th>
							<td>
								<input type="number" id="acl-rule-stale-ttl" class="small-text" min="0" value="0">
								<p class="description"><?php esc_html_e( 'Extra time to serve stale content while revalidating (0 = disabled).', 'api-cache-layer' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Tags', 'api-cache-layer' ); ?></th>
							<td><input type="text" id="acl-rule-tags" class="regular-text" placeholder="posts,content"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Rate Limit', 'api-cache-layer' ); ?></th>
							<td>
								<input type="number" id="acl-rule-rate-limit" class="small-text" min="0" value="0">
								<?php esc_html_e( 'requests per', 'api-cache-layer' ); ?>
								<input type="number" id="acl-rule-rate-window" class="small-text" min="1" value="60">
								<?php esc_html_e( 'seconds (0 = no limit)', 'api-cache-layer' ); ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Priority', 'api-cache-layer' ); ?></th>
							<td><input type="number" id="acl-rule-priority" class="small-text" min="1" value="10"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Enabled', 'api-cache-layer' ); ?></th>
							<td><label><input type="checkbox" id="acl-rule-enabled" checked> <?php esc_html_e( 'Rule is active', 'api-cache-layer' ); ?></label></td>
						</tr>
					</table>
					<div class="acl-modal-actions">
						<button type="button" id="acl-save-rule" class="button button-primary"><?php esc_html_e( 'Save Rule', 'api-cache-layer' ); ?></button>
						<button type="button" id="acl-cancel-rule" class="button"><?php esc_html_e( 'Cancel', 'api-cache-layer' ); ?></button>
						<span id="acl-rule-status" class="acl-flush-status"></span>
					</div>
				</div>
			</div>

			<!-- Rules Table -->
			<table class="widefat acl-rules-table" id="acl-rules-table">
				<thead>
					<tr>
						<th style="width: 40px;"></th>
						<th><?php esc_html_e( 'Route Pattern', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'TTL', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Vary By', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Tags', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Rate Limit', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Status', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'api-cache-layer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rules ) ) : ?>
						<tr class="acl-no-rules"><td colspan="8"><?php esc_html_e( 'No cache rules configured. Click "Add Rule" to create one.', 'api-cache-layer' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rules as $rule ) :
							$vary_parts = array();
							if ( ! empty( $rule['vary_by_query_params'] ) ) $vary_parts[] = 'params';
							if ( ! empty( $rule['vary_by_user_role'] ) ) $vary_parts[] = 'role';
							if ( ! empty( $rule['vary_by_headers'] ) ) $vary_parts[] = 'headers';
							$vary_str = ! empty( $vary_parts ) ? implode( ', ', $vary_parts ) : '-';
							$rate_str = ! empty( $rule['rate_limit'] ) ? $rule['rate_limit'] . '/' . $rule['rate_limit_window'] . 's' : '-';
						?>
							<tr data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>" draggable="true">
								<td><span class="acl-drag-handle" draggable="true" title="<?php esc_attr_e( 'Drag to reorder', 'api-cache-layer' ); ?>">&#8942;&#8942;</span></td>
								<td><code><?php echo esc_html( $rule['route_pattern'] ); ?></code></td>
								<td><span class="acl-rule-priority-val"><?php echo esc_html( $rule['ttl'] ); ?></span>s</td>
								<td><?php echo esc_html( $vary_str ); ?></td>
								<td><?php echo esc_html( $rule['tags'] ?: '-' ); ?></td>
								<td><?php echo esc_html( $rate_str ); ?></td>
								<td>
									<label class="acl-toggle">
										<input type="checkbox" class="acl-rule-toggle-status" data-id="<?php echo esc_attr( $rule['id'] ); ?>" data-rule='<?php echo esc_attr( wp_json_encode( $rule ) ); ?>' <?php checked( $rule['enabled'] ); ?>>
										<span class="acl-toggle-slider"></span>
									</label>
									<span class="acl-status-badge <?php echo $rule['enabled'] ? 'acl-status-active' : 'acl-status-inactive'; ?>" style="margin-left: 6px;">
										<?php echo $rule['enabled'] ? esc_html__( 'Active', 'api-cache-layer' ) : esc_html__( 'Inactive', 'api-cache-layer' ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button button-small acl-edit-rule" data-rule='<?php echo esc_attr( wp_json_encode( $rule ) ); ?>'><?php esc_html_e( 'Edit', 'api-cache-layer' ); ?></button>
									<button type="button" class="button button-small acl-delete-rule" data-id="<?php echo esc_attr( $rule['id'] ); ?>"><?php esc_html_e( 'Delete', 'api-cache-layer' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the cache browser tab for inspecting cached entries.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_browser_tab(): void {
		?>
		<div class="acl-tab-content" id="acl-tab-browser">
			<div class="acl-browser-controls">
				<input type="text" id="acl-browser-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by route or cache key...', 'api-cache-layer' ); ?>">
				<input type="text" id="acl-bulk-pattern" class="regular-text acl-bulk-pattern" placeholder="<?php esc_attr_e( 'Pattern to flush (e.g. /wp/v2/posts*)', 'api-cache-layer' ); ?>">
				<button type="button" id="acl-bulk-flush" class="button"><?php esc_html_e( 'Flush Pattern', 'api-cache-layer' ); ?></button>
				<button type="button" id="acl-browser-refresh" class="button"><?php esc_html_e( 'Refresh', 'api-cache-layer' ); ?></button>
			</div>
			<table class="widefat acl-browser-table" id="acl-browser-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Cache Key', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Route', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Status', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Size', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Cached At', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'TTL Remaining', 'api-cache-layer' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'api-cache-layer' ); ?></th>
					</tr>
				</thead>
				<tbody id="acl-browser-body">
					<tr><td colspan="7" style="text-align:center;padding:24px;"><?php esc_html_e( 'Loading...', 'api-cache-layer' ); ?></td></tr>
				</tbody>
			</table>
			<div id="acl-browser-pagination"></div>
		</div>
		<?php
	}

	/**
	 * Render the cache warmer tab with status, controls, and settings.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_warmer_tab(): void {
		$settings = $this->warmer ? $this->warmer->get_settings() : array();
		$status   = $this->warmer ? $this->warmer->get_status() : array();
		$next     = $this->warmer ? $this->warmer->get_next_scheduled() : false;

		$warmed = $status['warmed'] ?? 0;
		$total  = $status['total'] ?? 0;
		$pct    = $total > 0 ? round( ( $warmed / $total ) * 100 ) : 0;
		?>
		<div class="acl-tab-content" id="acl-tab-warmer">
			<div class="acl-stats-panel">
				<h2><?php esc_html_e( 'Cache Warmer', 'api-cache-layer' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Proactively warm the cache by pre-populating responses for registered REST routes. Routes are prioritized by access frequency.', 'api-cache-layer' ); ?></p>

				<div class="acl-warmer-status">
					<div class="acl-stats-grid">
						<div class="acl-stat-card">
							<span class="dashicons dashicons-info-outline acl-stat-icon"></span>
							<span class="acl-stat-value" id="acl-warmer-state"><?php echo esc_html( ucfirst( $status['state'] ?? 'idle' ) ); ?></span>
							<span class="acl-stat-label"><?php esc_html_e( 'Status', 'api-cache-layer' ); ?></span>
						</div>
						<div class="acl-stat-card">
							<span class="dashicons dashicons-update acl-stat-icon"></span>
							<span class="acl-stat-value" id="acl-warmer-progress"><?php echo esc_html( $warmed . '/' . $total ); ?></span>
							<span class="acl-stat-label"><?php esc_html_e( 'Routes Warmed', 'api-cache-layer' ); ?></span>
						</div>
						<div class="acl-stat-card">
							<span class="dashicons dashicons-calendar-alt acl-stat-icon"></span>
							<span class="acl-stat-value"><?php echo $next ? esc_html( wp_date( 'M j, H:i', $next ) ) : esc_html__( 'Not scheduled', 'api-cache-layer' ); ?></span>
							<span class="acl-stat-label"><?php esc_html_e( 'Next Scheduled', 'api-cache-layer' ); ?></span>
						</div>
					</div>

					<!-- Warmer Progress Bar -->
					<div class="acl-warmer-progress-container">
						<div class="acl-warmer-progress-bar">
							<div class="acl-warmer-progress-fill" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
						</div>
						<div class="acl-warmer-progress-label">
							<span><?php echo esc_html( $warmed ); ?> / <?php echo esc_html( $total ); ?> <?php esc_html_e( 'routes warmed', 'api-cache-layer' ); ?></span>
							<span class="acl-warmer-progress-pct"><?php echo esc_html( $pct ); ?>%</span>
						</div>
					</div>
				</div>

				<p>
					<button type="button" id="acl-warm-now" class="button button-primary"><?php esc_html_e( 'Warm Now', 'api-cache-layer' ); ?></button>
					<span id="acl-warm-status" class="acl-flush-status"></span>
				</p>
			</div>

			<div class="acl-stats-panel">
				<h3><?php esc_html_e( 'Warmer Settings', 'api-cache-layer' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Scheduled Warming', 'api-cache-layer' ); ?></th>
						<td><label><input type="checkbox" id="acl-warmer-enabled" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Automatically warm cache on schedule', 'api-cache-layer' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Schedule', 'api-cache-layer' ); ?></th>
						<td>
							<select id="acl-warmer-schedule">
								<option value="hourly" <?php selected( $settings['schedule'] ?? '', 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'api-cache-layer' ); ?></option>
								<option value="twicedaily" <?php selected( $settings['schedule'] ?? '', 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'api-cache-layer' ); ?></option>
								<option value="daily" <?php selected( $settings['schedule'] ?? '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'api-cache-layer' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Batch Size', 'api-cache-layer' ); ?></th>
						<td><input type="number" id="acl-warmer-batch" class="small-text" min="1" max="50" value="<?php echo esc_attr( $settings['batch_size'] ?? 10 ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Max Routes', 'api-cache-layer' ); ?></th>
						<td><input type="number" id="acl-warmer-max-routes" class="small-text" min="10" max="500" value="<?php echo esc_attr( $settings['max_routes'] ?? 100 ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Skip Authenticated Routes', 'api-cache-layer' ); ?></th>
						<td><label><input type="checkbox" id="acl-warmer-skip-auth" <?php checked( ! empty( $settings['skip_auth'] ) ); ?>> <?php esc_html_e( 'Skip routes that require authentication', 'api-cache-layer' ); ?></label></td>
					</tr>
				</table>
				<p>
					<button type="button" id="acl-save-warmer" class="button button-primary"><?php esc_html_e( 'Save Warmer Settings', 'api-cache-layer' ); ?></button>
					<span id="acl-warmer-save-status" class="acl-flush-status"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the real-time monitor tab with auto-refreshing statistics.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_monitor_tab(): void {
		$stats = $this->cache_manager->get_stats();
		?>
		<div class="acl-tab-content" id="acl-tab-monitor">
			<div class="acl-monitor-controls">
				<label>
					<input type="checkbox" id="acl-monitor-auto-refresh" checked>
					<?php esc_html_e( 'Auto-refresh', 'api-cache-layer' ); ?>
				</label>
				<select id="acl-monitor-interval">
					<option value="3">3s</option>
					<option value="5" selected>5s</option>
					<option value="10">10s</option>
					<option value="30">30s</option>
				</select>
				<button type="button" id="acl-monitor-refresh" class="button"><?php esc_html_e( 'Refresh Now', 'api-cache-layer' ); ?></button>
				<span class="acl-monitor-indicator">
					<span class="acl-pulse-dot"></span>
					<?php esc_html_e( 'Live', 'api-cache-layer' ); ?>
					<span class="acl-countdown">5s</span>
				</span>
			</div>

			<!-- Real-time Ring Chart -->
			<div class="acl-ring-chart-container" style="margin-bottom: 24px;">
				<div class="acl-ring-chart">
					<svg viewBox="0 0 140 140">
						<circle class="acl-ring-bg" cx="70" cy="70" r="65"></circle>
						<circle class="acl-ring-fill" id="acl-monitor-ring-fill" cx="70" cy="70" r="65"
							data-rate="<?php echo esc_attr( $stats['hit_rate'] ); ?>"
							style="stroke-dasharray: 408.41; stroke-dashoffset: <?php echo esc_attr( 408.41 - ( $stats['hit_rate'] / 100 ) * 408.41 ); ?>;">
						</circle>
					</svg>
					<div class="acl-ring-chart-label">
						<div class="acl-ring-chart-value" id="acl-monitor-ring-value"><?php echo esc_html( $stats['hit_rate'] ); ?>%</div>
						<div class="acl-ring-chart-sublabel"><?php esc_html_e( 'Hit Rate', 'api-cache-layer' ); ?></div>
					</div>
				</div>
			</div>

			<div class="acl-stats-grid" id="acl-monitor-stats">
				<div class="acl-stat-card">
					<span class="dashicons dashicons-yes-alt acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-monitor-hits">-</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Hits', 'api-cache-layer' ); ?></span>
				</div>
				<div class="acl-stat-card">
					<span class="dashicons dashicons-dismiss acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-monitor-misses">-</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Misses', 'api-cache-layer' ); ?></span>
				</div>
				<div class="acl-stat-card">
					<span class="dashicons dashicons-performance acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-monitor-rate">-</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Hit Rate', 'api-cache-layer' ); ?></span>
				</div>
				<div class="acl-stat-card">
					<span class="dashicons dashicons-database acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-monitor-cached">-</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Cached', 'api-cache-layer' ); ?></span>
				</div>
				<div class="acl-stat-card">
					<span class="dashicons dashicons-media-archive acl-stat-icon"></span>
					<span class="acl-stat-value" id="acl-monitor-size">-</span>
					<span class="acl-stat-label"><?php esc_html_e( 'Total Size', 'api-cache-layer' ); ?></span>
				</div>
			</div>

			<div class="acl-monitor-log">
				<h3><?php esc_html_e( 'Recent Invalidations', 'api-cache-layer' ); ?></h3>
				<table class="widefat striped" id="acl-monitor-log-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'api-cache-layer' ); ?></th>
							<th><?php esc_html_e( 'Route', 'api-cache-layer' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'api-cache-layer' ); ?></th>
							<th><?php esc_html_e( 'Source', 'api-cache-layer' ); ?></th>
							<th><?php esc_html_e( 'Cleared', 'api-cache-layer' ); ?></th>
						</tr>
					</thead>
					<tbody id="acl-monitor-log-body">
						<tr><td colspan="5" style="text-align:center;padding:16px;"><?php esc_html_e( 'Loading...', 'api-cache-layer' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
