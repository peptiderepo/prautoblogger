<?php
declare(strict_types=1);

/**
 * Unit tests for PRAutoBlogger_Opik_Client.
 *
 * @see includes/services/opik/class-opik-client.php
 */
namespace PRAutoBlogger\Tests\Services;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

/**
 * @group opik
 * @group services
 */
class TestOpikClient extends BaseTestCase {

	/**
	 * Test constructor initializes properties correctly.
	 */
	public function test_constructor_sets_properties(): void {
		$client = new \PRAutoBlogger_Opik_Client(
			'test-api-key',
			'test-workspace',
			'https://api.example.com/opik/',
			45
		);

		$this->assertInstanceOf( \PRAutoBlogger_Opik_Client::class, $client );
	}

	/**
	 * Test create_trace sends correct payload to endpoint.
	 */
	public function test_create_trace_sends_payload(): void {
		$client = new \PRAutoBlogger_Opik_Client(
			'test-key',
			'test-workspace'
		);

		$trace_payload = array(
			'id'            => 'trace-123',
			'name'          => 'test_trace',
			'project_name'  => 'prautoblogger',
			'start_time'    => '2026-04-23T10:00:00Z',
			'end_time'      => '2026-04-23T10:01:00Z',
			'input'         => array( 'topic' => 'test' ),
			'output'        => array( 'status' => 'ok' ),
			'metadata'      => array(),
		);

		// Mock wp_remote_post to return success.
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id' => 'trace-123' ) ),
			)
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( wp_json_encode( array( 'id' => 'trace-123' ) ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $client->create_trace( $trace_payload );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
	}

	/**
	 * Test create_span sends span with correct structure.
	 */
	public function test_create_span_sends_payload(): void {
		$client = new \PRAutoBlogger_Opik_Client(
			'test-key',
			'test-workspace'
		);

		$span_payload = array(
			'id'          => 'span-456',
			'trace_id'    => 'trace-123',
			'name'        => 'draft_generation',
			'type'        => 'llm',
			'start_time'  => '2026-04-23T10:00:00Z',
			'end_time'    => '2026-04-23T10:00:15Z',
			'input'       => array( 'prompt_hash' => 'sha256-abc' ),
			'output'      => array( 'response' => 'test' ),
			'model'       => 'gpt-4',
			'provider'    => 'openrouter',
			'usage'       => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
		);

		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id' => 'span-456' ) ),
			)
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( wp_json_encode( array( 'id' => 'span-456' ) ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $client->create_span( $span_payload );

		$this->assertIsArray( $result );
	}

	/**
	 * Test batch_create_spans respects the 100-item limit.
	 */
	public function test_batch_create_spans_truncates_over_100(): void {
		$client = new \PRAutoBlogger_Opik_Client(
			'test-key',
			'test-workspace'
		);

		// Create 150 dummy spans.
		$spans = array_map(
			function( $i ) {
				return array(
					'id'       => "span-$i",
					'trace_id' => 'trace-123',
					'name'     => 'test',
					'type'     => 'llm',
					'start_time' => '2026-04-23T10:00:00Z',
					'end_time'   => '2026-04-23T10:00:01Z',
					'input'    => array(),
					'output'   => array(),
					'model'    => 'gpt-4',
					'provider' => 'openrouter',
					'usage'    => array( 'total_tokens' => 10 ),
				);
			},
			range( 1, 150 )
		);

		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'inserted' => 100 ) ),
			)
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( wp_json_encode( array( 'inserted' => 100 ) ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Should truncate and succeed.
		$result = $client->batch_create_spans( $spans );

		$this->assertIsArray( $result );
	}

	/**
	 * Test that HTTP 500 errors trigger retry logic.
	 */
	public function test_retry_on_500_error(): void {
		$client = new \PRAutoBlogger_Opik_Client(
			'test-key',
			'test-workspace',
			'https://api.example.com/opik/',
			1  // 1-second timeout to make test fast
		);

		$call_count = 0;
		Functions\when( 'wp_remote_post' )->alias(
			function() use ( &$call_count ) {
				++$call_count;
				if ( $call_count < 3 ) {
					// First two calls return 500.
					return array(
						'response' => array( 'code' => 500 ),
						'body'     => 'Server error',
					);
				}
				// Third call succeeds.
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'ok' => true ) ),
				);
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function() use ( &$call_count ) {
				return $call_count < 3 ? 500 : 200;
			}
		);

		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function() use ( &$call_count ) {
				return $call_count < 3 ? 'Server error' : wp_json_encode( array( 'ok' => true ) );
			}
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $client->create_trace( array( 'id' => 'test', 'name' => 'test' ) );

		// Should eventually succeed on 3rd attempt.
		$this->assertIsArray( $result );
		$this->assertEquals( 3, $call_count );
	}

	/**
	 * Test that HTTP 400 errors don't retry.
	 */
	public function test_no_retry_on_400_error(): void {
		$client = new \PRAutoBlogger_Opik_Client(
			'test-key',
			'test-workspace'
		);

		$call_count = 0;
		Functions\when( 'wp_remote_post' )->alias(
			function() use ( &$call_count ) {
				++$call_count;
				return array(
					'response' => array( 'code' => 400 ),
					'body'     => wp_json_encode( array( 'error' => 'Bad request' ) ),
				);
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( wp_json_encode( array( 'error' => 'Bad request' ) ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $client->create_trace( array( 'id' => 'test', 'name' => 'test' ) );

		// Should fail on first attempt, not retry.
		$this->assertNull( $result );
		$this->assertEquals( 1, $call_count );
	}

	/**
	 * Test that API key and workspace are included in headers.
	 */
	public function test_headers_include_credentials(): void {
		$client = new \PRAutoBlogger_Opik_Client(
			'secret-key-12345',
			'my-workspace'
		);

		$captured_headers = null;
		Functions\when( 'wp_remote_post' )->alias(
			function( $url, $args ) use ( &$captured_headers ) {
				$captured_headers = $args['headers'] ?? array();
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( wp_json_encode( array() ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client->create_trace( array( 'id' => 'test', 'name' => 'test' ) );

		$this->assertIsArray( $captured_headers );
		$this->assertArrayHasKey( 'Authorization', $captured_headers );
		$this->assertEquals( 'secret-key-12345', $captured_headers['Authorization'] );
		$this->assertArrayHasKey( 'Comet-Workspace', $captured_headers );
		$this->assertEquals( 'my-workspace', $captured_headers['Comet-Workspace'] );
	}
}
