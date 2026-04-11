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
