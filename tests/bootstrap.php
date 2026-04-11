<?php
/**
 * PHPUnit bootstrap for PRAutoBlogger unit tests.
 *
 * Loads Brain\Monkey for WordPress function mocking,
 * then requires the source files under test.
 *
 * @package PRAutoBlogger\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Brain\Monkey sets up Patchwork & function stubs.
\Brain\Monkey\setUp();

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
