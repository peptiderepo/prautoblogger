<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Provider.
 *
 * Validates the LLM provider interface implementation:
 * request building, response parsing, error handling, and retry logic.
 * All HTTP calls are mocked — no real API calls.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterProviderTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/providers/class-prab-openrouter-provider.php';
    }

    /**
     * Test successful chat completion parses response correctly.
     */
    public function test_chat_completion_parses_success_response(): void {
        $mock_response = [
            'response' => [
                'code' => 200,
            ],
            'body' => wp_json_encode( [
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated content here.',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens'     => 150,
                    'completion_tokens' => 300,
                ],
            ] ),
        ];

        Functions\when( 'wp_remote_post' )->justReturn( $mock_response );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $mock_response['body'] );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider( 'test-api-key' );
        $result   = $provider->chat_completion(
            'google/gemini-2.0-flash-001',
            [
                [ 'role' => 'user', 'content' => 'Write a short paragraph.' ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'content', $result );
        $this->assertSame( 'Generated content here.', $result['content'] );
        $this->assertArrayHasKey( 'usage', $result );
        $this->assertSame( 150, $result['usage']['prompt_tokens'] );
        $this->assertSame( 300, $result['usage']['completion_tokens'] );
    }

    /**
     * Test chat completion handles WP_Error (network failure).
     */
    public function test_chat_completion_handles_wp_error(): void {
        $wp_error = new \stdClass();

        Functions\when( 'wp_remote_post' )->justReturn( $wp_error );
        Functions\when( 'is_wp_error' )->justReturn( true );

        $provider = new \PRAutoBlogger_OpenRouter_Provider( 'test-api-key' );
        $result   = $provider->chat_completion(
            'model/x',
            [ [ 'role' => 'user', 'content' => 'test' ] ]
        );

        // Should return an error structure, not throw.
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'error', $result );
    }

    /**
     * Test chat completion handles non-200 HTTP status.
     */
    public function test_chat_completion_handles_http_error(): void {
        $mock_response = [
            'body' => '{"error": {"message": "Rate limit exceeded"}}',
        ];

        Functions\when( 'wp_remote_post' )->justReturn( $mock_response );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $mock_response['body'] );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider( 'test-api-key' );
        $result   = $provider->chat_completion(
            'model/x',
            [ [ 'role' => 'user', 'content' => 'test' ] ]
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'error', $result );
    }

    /**
     * Test chat completion handles malformed JSON response.
     */
    public function test_chat_completion_handles_malformed_json(): void {
        Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => 'not json' ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'not json' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider( 'test-api-key' );
        $result   = $provider->chat_completion(
            'model/x',
            [ [ 'role' => 'user', 'content' => 'test' ] ]
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'error', $result );
    }

    /**
     * Test chat completion handles missing choices in response.
     */
    public function test_chat_completion_handles_empty_choices(): void {
        $body = wp_json_encode( [ 'choices' => [] ] );

        Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => $body ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $provider = new \PRAutoBlogger_OpenRouter_Provider( 'test-api-key' );
        $result   = $provider->chat_completion(
            'model/x',
            [ [ 'role' => 'user', 'content' => 'test' ] ]
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'error', $result );
    }
}
