<?php
/**
 * Base test case for all PRAutoBlogger unit tests.
 *
 * Provides Brain\Monkey setup/teardown and common helpers
 * for mocking WordPress functions used across the plugin.
 *
 * @package PRAutoBlogger\Tests
 */

namespace PRAutoBlogger\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class BaseTestCase extends TestCase {

    /**
     * Set up Brain\Monkey before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Common WordPress function stubs used across many classes.
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
    }

    /**
     * Tear down Brain\Monkey after each test.
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: stub get_option with a map of option_name => value.
     *
     * @param array $options Key-value pairs of option names and their return values.
     */
    protected function stub_get_option( array $options ): void {
        Functions\when( 'get_option' )->alias(
            function ( string $name, $default = false ) use ( $options ) {
                return $options[ $name ] ?? $default;
            }
        );
    }

    /**
     * Helper: stub current_time to return a fixed timestamp.
     *
     * @param string $time MySQL datetime string (Y-m-d H:i:s).
     */
    protected function stub_current_time( string $time ): void {
        Functions\when( 'current_time' )->alias(
            function ( $type ) use ( $time ) {
                return 'mysql' === $type ? $time : strtotime( $time );
            }
        );
    }

    /**
     * Helper: create a mock $wpdb with expectations.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function create_mock_wpdb() {
        $wpdb = $this->getMockBuilder( \stdClass::class )
            ->addMethods( [ 'prepare', 'get_var', 'get_results', 'insert', 'query', 'get_row' ] )
            ->getMock();

        $wpdb->prefix         = 'wp_';
        $wpdb->insert_id      = 0;
        $wpdb->last_error     = '';
        $wpdb->prab_cost_logs = 'wp_prab_cost_logs';

        return $wpdb;
    }
}
