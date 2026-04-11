<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Provider.
 *
 * Validates the LLM provider interface implementation methods.
 * All HTTP calls are mocked — no real API calls.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterProviderTest extends BaseTestCase {

    /**
     * Test OpenRouter Provider can be instantiated.
     */
    public function test_openrouter_provider_instantiation(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $this->assertInstanceOf( \PRAutoBlogger_OpenRouter_Provider::class, $provider );
    }

    /**
     * Test that provider implements LLM Provider Interface.
     */
    public function test_provider_implements_interface(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $this->assertInstanceOf( \PRAutoBlogger_LLM_Provider_Interface::class, $provider );
    }

    /**
     * Test send_chat_completion method is callable.
     */
    public function test_send_chat_completion_method_exists(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $this->assertTrue( method_exists( $provider, 'send_chat_completion' ) );
    }

    /**
     * Test get_available_models returns array.
     */
    public function test_get_available_models_returns_array(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();
        $models = $provider->get_available_models();

        $this->assertIsArray( $models );
    }

    /**
     * Test estimate_cost returns float.
     */
    public function test_estimate_cost_returns_float(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();
        $cost = $provider->estimate_cost( 'model/test', 1000, 500 );

        $this->assertIsFloat( $cost );
    }

    /**
     * Test get_provider_name returns string.
     */
    public function test_get_provider_name_returns_string(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();
        $name = $provider->get_provider_name();

        $this->assertIsString( $name );
        $this->assertNotEmpty( $name );
    }

    /**
     * Test validate_credentials returns boolean.
     */
    public function test_validate_credentials_returns_boolean(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();
        $valid = $provider->validate_credentials();

        $this->assertIsBool( $valid );
    }

    /**
     * Test send_chat_completion returns array.
     */
    public function test_send_chat_completion_returns_array(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => '{}' ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $messages = [
            [ 'role' => 'user', 'content' => 'Test message' ],
        ];

        $result = $provider->send_chat_completion( $messages, 'model/test', [] );

        $this->assertIsArray( $result );
    }

    /**
     * Test send_chat_completion with options.
     */
    public function test_send_chat_completion_with_options(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => '{}' ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider();

        $messages = [
            [ 'role' => 'user', 'content' => 'Test message' ],
        ];
        $options = [ 'temperature' => 0.7 ];

        $result = $provider->send_chat_completion( $messages, 'model/test', $options );

        $this->assertIsArray( $result );
    }
}
