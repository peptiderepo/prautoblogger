<?php
/**
 * PHPUnit bootstrap for PRAutoBlogger unit tests.
 *
 * Loads composer autoloader and defines WordPress constants.
 * Brain\Monkey setup is done in BaseTestCase, not here.
 *
 * @package PRAutoBlogger\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants that source files expect.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'PRAB_VERSION' ) ) {
    define( 'PRAB_VERSION', '1.0.0-test' );
}
if ( ! defined( 'PRAB_PLUGIN_DIR' ) ) {
    define( 'PRAB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// WordPress time constants used by CostTracker and ContentAnalyzer.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

// Plugin constants used by OpenRouter provider retry logic.
if ( ! defined( 'PRAUTOBLOGGER_MAX_RETRIES' ) ) {
    define( 'PRAUTOBLOGGER_MAX_RETRIES', 3 );
}
if ( ! defined( 'PRAUTOBLOGGER_API_TIMEOUT_SECONDS' ) ) {
    define( 'PRAUTOBLOGGER_API_TIMEOUT_SECONDS', 30 );
}
if ( ! defined( 'PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS' ) ) {
    define( 'PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS', 1 );
}

// WordPress database constants used in $wpdb queries.
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

/**
 * Minimal WP_Query stub for unit tests.
 *
 * IdeaScorer uses WP_Query to check for existing posts.
 * This stub returns empty results by default.
 */
if ( ! class_exists( 'WP_Query' ) ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
    class WP_Query {
        /** @var array */
        public $posts = [];
        /** @var int */
        public $found_posts = 0;
        /** @var bool */
        public $have_posts = false;

        public function __construct( $args = [] ) {
            // No-op for testing — returns no posts.
        }

        public function have_posts(): bool {
            return $this->have_posts;
        }

        public function the_post(): void {
            // No-op.
        }
    }
}
