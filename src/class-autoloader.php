<?php
/**
 * PSR-4 compatible autoloader for API Cache Layer.
 *
 * @package API_Cache_Layer
 */

namespace Jestart\ApiCacheLayer;

/**
 * Class Autoloader
 *
 * Custom PSR-4 autoloader that maps the Jestart\ApiCacheLayer namespace
 * to the plugin's src/ directory and sub-namespaces to subdirectories.
 *
 * @since 3.0.0
 * @package API_Cache_Layer
 */
class Autoloader {

	/**
	 * Namespace prefix for this autoloader.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	private const NAMESPACE_PREFIX = 'Jestart\\ApiCacheLayer\\';

	/**
	 * Mapping of sub-namespaces to directories relative to the plugin root.
	 *
	 * @since 3.0.0
	 * @var array<string, string>
	 */
	private array $namespace_map;

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 *
	 * @param string $plugin_dir Absolute path to the plugin root directory.
	 */
	public function __construct( string $plugin_dir ) {
		$this->namespace_map = array(
			'Admin\\'  => $plugin_dir . 'admin/',
			'Traits\\' => $plugin_dir . 'src/traits/',
			''         => $plugin_dir . 'src/',
		);
	}

	/**
	 * Register this autoloader with the SPL autoload stack.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Autoload a class by its fully qualified name.
	 *
	 * Converts the namespace to a file path using WordPress naming conventions
	 * (class-{name}.php with lowercase and hyphens).
	 *
	 * @since 3.0.0
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public function autoload( string $class ): void {
		// Bail if the class does not belong to our namespace.
		if ( ! str_starts_with( $class, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		// Strip the root namespace prefix.
		$relative_class = substr( $class, strlen( self::NAMESPACE_PREFIX ) );

		// Find the matching sub-namespace directory.
		foreach ( $this->namespace_map as $sub_namespace => $directory ) {
			if ( '' === $sub_namespace || str_starts_with( $relative_class, $sub_namespace ) ) {
				$class_name = '' === $sub_namespace
					? $relative_class
					: substr( $relative_class, strlen( $sub_namespace ) );

				// Convert class name to WordPress file naming convention.
				$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';
				$file_path = $directory . $file_name;

				if ( file_exists( $file_path ) ) {
					require_once $file_path;
					return;
				}
			}
		}
	}
}
