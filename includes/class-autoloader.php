<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * PSR-4-inspired autoloader for PRAutoBlogger classes.
 *
 * Maps class names like `PRAutoBlogger_Content_Generator` to file paths like
 * `includes/core/class-content-generator.php` by scanning known subdirectories.
 *
 * Triggered by: PHP's spl_autoload when a class is first referenced.
 * Dependencies: None — this is loaded before anything else.
 *
 * @see prautoblogger.php — Registers this autoloader at boot time.
 */
class PRAutoBlogger_Autoloader {

	/**
	 * Directories to scan for class files, relative to the plugin's includes/ dir.
	 *
	 * @var string[]
	 */
	private static array $directories = array(
		'',           // includes/ root
		'admin/',
		'admin/fields/',
		'core/',
		'providers/',
		'models/',
		'frontend/',
		'services/',
		'ajax/',
	);

	/**
	 * Register the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Attempt to load a class file.
	 *
	 * Converts `PRAutoBlogger_Content_Generator` → `class-content-generator.php`
	 * and searches each registered subdirectory under includes/.
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return void
	 */
	public static function autoload( string $class_name ): void {
		// Only handle our own classes.
		if ( 0 !== strpos( $class_name, 'PRAutoBlogger' ) ) {
			return;
		}

		$file_name = self::class_to_filename( $class_name );
		$base_dir  = PRAUTOBLOGGER_PLUGIN_DIR . 'includes/';

		foreach ( self::$directories as $directory ) {
			$file_path = $base_dir . $directory . $file_name;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}
	}

	/**
	 * Convert a class name to its expected filename.
	 *
	 * PRAutoBlogger_Content_Generator → class-content-generator.php
	 * PRAutoBlogger_LLM_Provider_Interface → interface-llm-provider.php
	 *
	 * @param string $class_name The class name to convert.
	 *
	 * @return string The expected filename.
	 */
	private static function class_to_filename( string $class_name ): string {
		// Remove the PRAutoBlogger_ prefix.
		$name = (string) preg_replace( '/^PRAutoBlogger_/', '', $class_name );

		// Determine file prefix: interface- or class-.
		$prefix = 'class-';
		if ( preg_match( '/_Interface$/', $name ) ) {
			$prefix = 'interface-';
			$name   = (string) preg_replace( '/_Interface$/', '', $name );
		}

		// Convert PascalCase/UPPER_CASE to kebab-case.
		$name = strtolower( (string) preg_replace( '/([a-z])([A-Z])/', '$1-$2', $name ) );
		$name = str_replace( '_', '-', $name );

		return $prefix . $name . '.php';
	}
}
