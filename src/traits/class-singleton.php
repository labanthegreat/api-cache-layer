<?php
/**
 * Singleton pattern trait.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer\Traits;

/**
 * Trait Singleton
 *
 * Provides a reusable singleton pattern for classes that should only
 * be instantiated once during a request lifecycle.
 *
 * @since 3.0.0
 * @package API_Cache_Layer
 */
trait Singleton {

	/**
	 * The single instance of the class.
	 *
	 * @since 3.0.0
	 * @var static|null
	 */
	private static ?self $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @since 3.0.0
	 *
	 * @return static
	 */
	public static function get_instance(): static {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing of the singleton.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup(): void {
		throw new \RuntimeException( 'Cannot unserialize a singleton.' );
	}
}
