<?php
/**
 * Smart cache invalidation for REST API cache.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cache_Invalidator
 *
 * Listens to WordPress content lifecycle hooks and automatically
 * invalidates related REST API cache entries. Supports cascade
 * invalidation, debounced batching, webhooks, and logging.
 *
 * @since 2.0.0
 * @package API_Cache_Layer
 */
class Cache_Invalidator {

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
	 * @var Cache_Analytics|null
	 */
	private ?Cache_Analytics $analytics = null;

	/**
	 * Pending invalidations for debouncing.
	 *
	 * @since 2.0.0
	 * @var array<string, array>
	 */
	private array $pending_invalidations = array();

	/**
	 * Option key for webhook configuration.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const WEBHOOK_OPTION = 'acl_invalidation_webhooks';

	/**
	 * Option key for cascade rules.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const CASCADE_OPTION = 'acl_cascade_rules';

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param Cache_Manager        $cache_manager Cache manager instance.
	 * @param Cache_Analytics|null $analytics     Analytics instance.
	 */
	public function __construct( Cache_Manager $cache_manager, ?Cache_Analytics $analytics = null ) {
		$this->cache_manager = $cache_manager;
		$this->analytics     = $analytics;
	}

	/**
	 * Register hooks for automatic invalidation on content changes.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		// Post lifecycle.
		add_action( 'save_post', array( $this, 'on_post_saved' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_post_deleted' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_post_trashed' ) );
		add_action( 'untrashed_post', array( $this, 'on_post_untrashed' ) );

		// Term lifecycle.
		add_action( 'created_term', array( $this, 'on_term_changed' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term_changed' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_changed' ), 10, 3 );

		// Comment lifecycle.
		add_action( 'wp_insert_comment', array( $this, 'on_comment_changed' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'on_comment_changed' ), 10, 2 );
		add_action( 'deleted_comment', array( $this, 'on_comment_deleted' ) );
		add_action( 'trashed_comment', array( $this, 'on_comment_deleted' ) );

		// User lifecycle.
		add_action( 'profile_update', array( $this, 'on_user_changed' ) );
		add_action( 'deleted_user', array( $this, 'on_user_changed' ) );

		// Option updates (for settings, menus, etc.).
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );

		// Process debounced invalidations at shutdown.
		add_action( 'shutdown', array( $this, 'process_pending_invalidations' ) );

		// Webhook listener.
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
	}

	/**
	 * Invalidate caches when a post is saved.
	 *
	 * @since 2.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function on_post_saved( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->invalidate_post_caches( $post, 'post_saved' );
	}

	/**
	 * Invalidate caches when a post is deleted.
	 *
	 * @since 2.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function on_post_deleted( int $post_id, \WP_Post $post ): void {
		$this->invalidate_post_caches( $post, 'post_deleted' );
	}

	/**
	 * Invalidate caches when a post is trashed.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_trashed( int $post_id ): void {
		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$this->invalidate_post_caches( $post, 'post_trashed' );
		}
	}

	/**
	 * Invalidate caches when a post is untrashed.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_untrashed( int $post_id ): void {
		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$this->invalidate_post_caches( $post, 'post_untrashed' );
		}
	}

	/**
	 * Invalidate caches related to a specific post with cascade support.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post   Post object.
	 * @param string  $reason Reason for invalidation.
	 * @return void
	 */
	private function invalidate_post_caches( \WP_Post $post, string $reason = 'post_change' ): void {
		$post_type = $post->post_type;
		$rest_base = $this->get_rest_base_for_post_type( $post_type );

		if ( ! $rest_base ) {
			return;
		}

		// Debounce: queue the invalidation.
		$route = '/wp/v2/' . $rest_base;
		$this->queue_invalidation( $route, $reason );

		// Cascade invalidation: also invalidate related endpoints.
		$this->cascade_post_invalidation( $post, $rest_base, $reason );

		/**
		 * Fires after a post's related caches are invalidated.
		 *
		 * @param WP_Post           $post          The post that triggered invalidation.
		 * @param string            $rest_base     The REST base for the post type.
		 * @param Cache_Manager $cache_manager The cache manager instance.
		 */
		do_action( 'acl_post_cache_invalidated', $post, $rest_base, $this->cache_manager );
	}

	/**
	 * Cascade post invalidation to related endpoints.
	 *
	 * When a post is updated, also invalidate:
	 * - Taxonomy term endpoints the post belongs to
	 * - Author endpoint
	 * - Search endpoints
	 * - Custom cascade rules
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post      The post object.
	 * @param string  $rest_base REST base for the post type.
	 * @param string  $reason    Reason for invalidation.
	 * @return void
	 */
	private function cascade_post_invalidation( \WP_Post $post, string $rest_base, string $reason ): void {
		// Invalidate taxonomies this post belongs to.
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->show_in_rest ) ) {
				continue;
			}

			$tax_rest_base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;
			$terms         = wp_get_post_terms( $post->ID, $taxonomy->name );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$this->queue_invalidation( '/wp/v2/' . $tax_rest_base, $reason . '_cascade_taxonomy' );
			}
		}

		// Invalidate author endpoint.
		if ( $post->post_author ) {
			$this->queue_invalidation( '/wp/v2/users', $reason . '_cascade_author' );
		}

		// Apply custom cascade rules.
		$cascade_rules = get_option( self::CASCADE_OPTION, array() );
		foreach ( $cascade_rules as $rule ) {
			if ( ! empty( $rule['source'] ) && str_contains( '/wp/v2/' . $rest_base, $rule['source'] ) ) {
				if ( ! empty( $rule['targets'] ) && is_array( $rule['targets'] ) ) {
					foreach ( $rule['targets'] as $target ) {
						$this->queue_invalidation( $target, $reason . '_cascade_custom' );
					}
				}
			}
		}
	}

	/**
	 * Queue an invalidation for debounced processing.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route  Route pattern to invalidate.
	 * @param string $reason Reason for invalidation.
	 * @return void
	 */
	private function queue_invalidation( string $route, string $reason ): void {
		$key = md5( $route );

		if ( ! isset( $this->pending_invalidations[ $key ] ) ) {
			$this->pending_invalidations[ $key ] = array(
				'route'  => $route,
				'reason' => $reason,
			);
		}
	}

	/**
	 * Process all pending invalidations (called at shutdown for debouncing).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function process_pending_invalidations(): void {
		if ( empty( $this->pending_invalidations ) ) {
			return;
		}

		foreach ( $this->pending_invalidations as $invalidation ) {
			$count = $this->cache_manager->invalidate_by_route( $invalidation['route'] );

			// Log the invalidation.
			if ( $this->analytics ) {
				$this->analytics->log_invalidation(
					$invalidation['route'],
					$invalidation['reason'],
					'auto',
					$count
				);
			}

			// Send webhook notifications.
			$this->send_invalidation_webhook( $invalidation['route'], $invalidation['reason'], $count );
		}

		$this->pending_invalidations = array();
	}

	/**
	 * Determine the REST base for a given post type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $post_type Post type slug.
	 * @return string|false REST base or false if not REST-enabled.
	 */
	private function get_rest_base_for_post_type( string $post_type ): string|false {
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object || empty( $post_type_object->show_in_rest ) ) {
			return false;
		}

		return ! empty( $post_type_object->rest_base ) ? $post_type_object->rest_base : $post_type;
	}

	/**
	 * Invalidate caches when a term is created, edited, or deleted.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function on_term_changed( int $term_id, int $tt_id, string $taxonomy ): void {
		$tax_object = get_taxonomy( $taxonomy );

		if ( ! $tax_object || empty( $tax_object->show_in_rest ) ) {
			return;
		}

		$rest_base = ! empty( $tax_object->rest_base ) ? $tax_object->rest_base : $taxonomy;

		$this->queue_invalidation( '/wp/v2/' . $rest_base, 'term_changed' );

		// Cascade: invalidate post types using this taxonomy.
		if ( ! empty( $tax_object->object_type ) ) {
			foreach ( $tax_object->object_type as $post_type ) {
				$pt_rest_base = $this->get_rest_base_for_post_type( $post_type );
				if ( $pt_rest_base ) {
					$this->queue_invalidation( '/wp/v2/' . $pt_rest_base, 'term_changed_cascade' );
				}
			}
		}
	}

	/**
	 * Invalidate caches when a comment is inserted or edited.
	 *
	 * @since 2.0.0
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object.
	 * @return void
	 */
	public function on_comment_changed( int $comment_id, \WP_Comment $comment ): void {
		$this->queue_invalidation( '/wp/v2/comments', 'comment_changed' );

		// Also invalidate the parent post's cache.
		if ( $comment->comment_post_ID ) {
			$post = get_post( (int) $comment->comment_post_ID );

			if ( $post instanceof \WP_Post ) {
				$this->invalidate_post_caches( $post, 'comment_changed_cascade' );
			}
		}
	}

	/**
	 * Invalidate comment caches when a comment is deleted or trashed.
	 *
	 * @since 2.0.0
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function on_comment_deleted( int $comment_id ): void {
		$this->queue_invalidation( '/wp/v2/comments', 'comment_deleted' );
	}

	/**
	 * Invalidate user-related caches.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function on_user_changed( int $user_id ): void {
		$this->queue_invalidation( '/wp/v2/users', 'user_changed' );
	}

	/**
	 * Invalidate caches when certain options are updated.
	 *
	 * Performs a full cache flush for structural option changes such as
	 * permalink structure or site name.
	 *
	 * @since 2.0.0
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public function on_option_updated( string $option, mixed $old_value, mixed $value ): void {
		$tracked_options = array(
			'permalink_structure',
			'blogname',
			'blogdescription',
			'posts_per_page',
			'default_category',
		);

		/**
		 * Filter the list of options that trigger cache invalidation.
		 *
		 * @param array $tracked_options Option names to watch.
		 */
		$tracked_options = apply_filters( 'acl_tracked_options', $tracked_options );

		if ( in_array( $option, $tracked_options, true ) ) {
			// Full flush for structural changes.
			$count = $this->cache_manager->flush_all();

			if ( $this->analytics ) {
				$this->analytics->log_invalidation( '*', 'option_changed:' . $option, 'auto', $count );
			}
		}
	}

	/**
	 * Register webhook endpoint for external invalidation triggers.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_webhook_endpoint(): void {
		register_rest_route( 'acl/v1', '/invalidate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook_invalidation' ),
			'permission_callback' => array( $this, 'verify_webhook_auth' ),
			'args'                => array(
				'route' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'reason' => array(
					'type'              => 'string',
					'default'           => 'webhook',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	/**
	 * Handle incoming webhook invalidation request.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request containing route and reason params.
	 * @return WP_REST_Response Response with success status and entries cleared count.
	 */
	public function handle_webhook_invalidation( \WP_REST_Request $request ): \WP_REST_Response {
		$route  = $request->get_param( 'route' );
		$reason = $request->get_param( 'reason' );

		if ( '*' === $route ) {
			$count = $this->cache_manager->flush_all();
		} else {
			$count = $this->cache_manager->invalidate_by_route( $route );
		}

		if ( $this->analytics ) {
			$this->analytics->log_invalidation( $route, $reason, 'webhook', $count );
		}

		return new \WP_REST_Response( array(
			'success'         => true,
			'entries_cleared' => $count,
			'route'           => $route,
		), 200 );
	}

	/**
	 * Verify webhook authentication via the X-ACL-Webhook-Secret header.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error True if authenticated, WP_Error on failure.
	 */
	public function verify_webhook_auth( \WP_REST_Request $request ): bool|\WP_Error {
		$webhooks = get_option( self::WEBHOOK_OPTION, array() );

		if ( empty( $webhooks['secret'] ) ) {
			return new \WP_Error(
				'acl_webhook_not_configured',
				'Webhook secret is not configured.',
				array( 'status' => 403 )
			);
		}

		$auth_header = $request->get_header( 'X-ACL-Webhook-Secret' );

		if ( ! $auth_header || ! hash_equals( $webhooks['secret'], $auth_header ) ) {
			return new \WP_Error(
				'acl_unauthorized',
				'Invalid webhook secret.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Send invalidation notification to configured webhooks.
	 *
	 * Sends a non-blocking POST request with the invalidation payload.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route   Route that was invalidated.
	 * @param string $reason  Reason for invalidation.
	 * @param int    $count   Number of entries cleared.
	 * @return void
	 */
	private function send_invalidation_webhook( string $route, string $reason, int $count ): void {
		$webhooks = get_option( self::WEBHOOK_OPTION, array() );

		if ( empty( $webhooks['notify_url'] ) || ! wp_http_validate_url( $webhooks['notify_url'] ) ) {
			return;
		}

		$payload = array(
			'event'           => 'cache_invalidated',
			'route'           => $route,
			'reason'          => $reason,
			'entries_cleared' => $count,
			'timestamp'       => time(),
			'site_url'        => get_site_url(),
		);

		wp_remote_post( $webhooks['notify_url'], array(
			'body'      => wp_json_encode( $payload ),
			'headers'   => array(
				'Content-Type'         => 'application/json',
				'X-ACL-Webhook-Event'  => 'cache_invalidated',
			),
			'timeout'   => 5,
			'blocking'  => false,
			'sslverify' => true,
		) );
	}

	/**
	 * Get webhook configuration.
	 *
	 * @since 2.0.0
	 *
	 * @return array Webhook configuration with 'secret' and 'notify_url' keys.
	 */
	public function get_webhook_config(): array {
		return get_option( self::WEBHOOK_OPTION, array(
			'secret'     => '',
			'notify_url' => '',
		) );
	}

	/**
	 * Update webhook configuration.
	 *
	 * @since 2.0.0
	 *
	 * @param array $config Webhook settings with 'secret' and 'notify_url' keys.
	 * @return void
	 */
	public function update_webhook_config( array $config ): void {
		$sanitized = array(
			'secret'     => sanitize_text_field( $config['secret'] ?? '' ),
			'notify_url' => esc_url_raw( $config['notify_url'] ?? '' ),
		);

		update_option( self::WEBHOOK_OPTION, $sanitized );
	}

	/**
	 * Get cascade rules.
	 *
	 * @since 2.0.0
	 *
	 * @return array List of cascade rules with 'source' and 'targets' keys.
	 */
	public function get_cascade_rules(): array {
		return get_option( self::CASCADE_OPTION, array() );
	}

	/**
	 * Update cascade rules.
	 *
	 * @since 2.0.0
	 *
	 * @param array $rules Cascade rules, each with 'source' and 'targets' keys.
	 * @return void
	 */
	public function update_cascade_rules( array $rules ): void {
		$sanitized = array();

		foreach ( $rules as $rule ) {
			$sanitized[] = array(
				'source'  => sanitize_text_field( $rule['source'] ?? '' ),
				'targets' => array_map( 'sanitize_text_field', $rule['targets'] ?? array() ),
			);
		}

		update_option( self::CASCADE_OPTION, $sanitized );
	}
}
