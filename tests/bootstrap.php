<?php
/**
 * PHPUnit bootstrap for AutoBlogger unit tests.
 *
 * Uses Brain Monkey to mock WordPress functions so tests run without
 * a full WordPress installation. Only loads the classes under test.
 *
 * @see https://brain-wp.github.io/BrainMonkey/
 */

declare(strict_types=1);

// Composer autoloader for Brain Monkey and test dependencies.
$autoloader = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
	echo "Run 'composer install' before running tests.\n";
	exit( 1 );
}
require_once $autoloader;

// Define WordPress constants that plugin code expects.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-wp/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// AutoBlogger constants from the main plugin file.
if ( ! defined( 'AUTOBLOGGER_VERSION' ) ) {
	define( 'AUTOBLOGGER_VERSION', '0.1.0' );
}
if ( ! defined( 'AUTOBLOGGER_MAX_RETRIES' ) ) {
	define( 'AUTOBLOGGER_MAX_RETRIES', 3 );
}
if ( ! defined( 'AUTOBLOGGER_RETRY_BASE_DELAY_SECONDS' ) ) {
	define( 'AUTOBLOGGER_RETRY_BASE_DELAY_SECONDS', 2 );
}
if ( ! defined( 'AUTOBLOGGER_DEFAULT_ANALYSIS_MODEL' ) ) {
	define( 'AUTOBLOGGER_DEFAULT_ANALYSIS_MODEL', 'anthropic/claude-3.5-haiku' );
}
if ( ! defined( 'AUTOBLOGGER_DEFAULT_WRITING_MODEL' ) ) {
	define( 'AUTOBLOGGER_DEFAULT_WRITING_MODEL', 'anthropic/claude-sonnet-4' );
}
if ( ! defined( 'AUTOBLOGGER_DEFAULT_EDITOR_MODEL' ) ) {
	define( 'AUTOBLOGGER_DEFAULT_EDITOR_MODEL', 'anthropic/claude-sonnet-4' );
}

// Load plugin class files (no autoloader in tests — explicit includes).
$includes_dir = dirname( __DIR__ ) . '/includes';

// Models (no WP dependencies, safe to load).
require_once $includes_dir . '/models/class-generation-log.php';
require_once $includes_dir . '/models/class-content-score.php';
require_once $includes_dir . '/models/class-source-data.php';
require_once $includes_dir . '/models/class-analysis-result.php';
require_once $includes_dir . '/models/class-article-idea.php';
require_once $includes_dir . '/models/class-editorial-review.php';
require_once $includes_dir . '/models/class-content-request.php';

// Providers (loaded on demand in tests since they need WP mocks).
// Core classes (loaded on demand in tests since they need WP mocks).
