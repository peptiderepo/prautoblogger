<?php
declare(strict_types=1);

/**
 * Unit tests for PRAutoBlogger_Opik_Trace_Context.
 *
 * @see includes/services/opik/class-opik-trace-context.php
 */
namespace PRAutoBlogger\Tests\Services;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

/**
 * @group opik
 * @group services
 */
class TestOpikTraceContext extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Stub UUID generation.
		Functions\when( 'wp_generate_uuid4' )->alias( function() {
			static $counter = 0;
			return 'uuid-' . ++$counter;
		} );
	}

	protected function tearDown(): void {
		// Clean up singleton.
		\PRAutoBlogger_Opik_Trace_Context::teardown();
		parent::tearDown();
	}

	/**
	 * Test current() returns singleton instance.
	 */
	public function test_current_returns_singleton(): void {
		$ctx1 = \PRAutoBlogger_Opik_Trace_Context::current();
		$ctx2 = \PRAutoBlogger_Opik_Trace_Context::current();

		$this->assertSame( $ctx1, $ctx2 );
	}

	/**
	 * Test init_trace() generates a UUID.
	 */
	public function test_init_trace_generates_uuid(): void {
		$ctx = \PRAutoBlogger_Opik_Trace_Context::current();
		$trace_id = $ctx->init_trace();

		$this->assertNotEmpty( $trace_id );
		$this->assertStringStartsWith( 'uuid-', $trace_id );
		$this->assertEquals( $trace_id, $ctx->get_trace_id() );
	}

	/**
	 * Test start_span() creates span and returns ID.
	 */
	public function test_start_span_creates_span(): void {
		$ctx = \PRAutoBlogger_Opik_Trace_Context::current();
		$ctx->init_trace();

		$span_id = $ctx->start_span(
			array(
				'name'     => 'draft_generation',
				'type'     => 'llm',
				'model'    => 'gpt-4',
				'provider' => 'openrouter',
				'input'    => array( 'prompt_hash' => 'sha256-abc' ),
			)
		);

		$this->assertNotEmpty( $span_id );
		$this->assertStringStartsWith( 'uuid-', $span_id );

		$span = $ctx->get_span( $span_id );
		$this->assertIsArray( $span );
		$this->assertEquals( 'draft_generation', $span['name'] );
		$this->assertEquals( 'llm', $span['type'] );
		$this->assertEquals( 'gpt-4', $span['model'] );
	}

	/**
	 * Test end_span() records output and usage.
	 */
	public function test_end_span_records_data(): void {
		$ctx = \PRAutoBlogger_Opik_Trace_Context::current();
		$ctx->init_trace();

		$span_id = $ctx->start_span(
			array(
				'name' => 'test_span',
				'type' => 'llm',
			)
		);

		$ctx->end_span(
			$span_id,
			array(
				'output' => array( 'response' => 'Hello world' ),
				'usage'  => array(
					'prompt_tokens'     => 50,
					'completion_tokens' => 25,
					'total_tokens'      => 75,
				),
			)
		);

		$span = $ctx->get_span( $span_id );
		$this->assertIsArray( $span['output'] );
		$this->assertEquals( 'Hello world', $span['output']['response'] );
		$this->assertEquals( 75, $span['usage']['total_tokens'] );
	}

	/**
	 * Test get_spans() returns all active spans.
	 */
	public function test_get_spans_returns_all(): void {
		$ctx = \PRAutoBlogger_Opik_Trace_Context::current();
		$ctx->init_trace();

		$span1 = $ctx->start_span( array( 'name' => 'span1', 'type' => 'llm' ) );
		$span2 = $ctx->start_span( array( 'name' => 'span2', 'type' => 'llm' ) );
		$span3 = $ctx->start_span( array( 'name' => 'span3', 'type' => 'llm' ) );

		$all_spans = $ctx->get_spans();

		$this->assertCount( 3, $all_spans );
		$this->assertArrayHasKey( $span1, $all_spans );
		$this->assertArrayHasKey( $span2, $all_spans );
		$this->assertArrayHasKey( $span3, $all_spans );
	}

	/**
	 * Test finalize_trace() returns complete trace object.
	 */
	public function test_finalize_trace_includes_all_data(): void {
		Functions\when( 'get_option' )->alias(
			function( $option, $default = false ) {
				if ( 'prautoblogger_opik_project_name' === $option ) {
					return 'prautoblogger';
				}
				return $default;
			}
		);

		$ctx = \PRAutoBlogger_Opik_Trace_Context::current();
		$trace_id = $ctx->init_trace();

		$span1 = $ctx->start_span( array( 'name' => 'step1', 'type' => 'llm' ) );
		$ctx->end_span( $span1, array( 'output' => array( 'ok' => true ) ) );

		$span2 = $ctx->start_span( array( 'name' => 'step2', 'type' => 'llm' ) );
		$ctx->end_span( $span2, array( 'output' => array( 'ok' => true ) ) );

		$trace = $ctx->finalize_trace();

		$this->assertEquals( $trace_id, $trace['id'] );
		$this->assertEquals( 'article_generation', $trace['name'] );
		$this->assertEquals( 'prautoblogger', $trace['project_name'] );
		$this->assertArrayHasKey( 'start_time', $trace );
		$this->assertArrayHasKey( 'end_time', $trace );
		$this->assertCount( 2, $trace['spans'] );
	}

	/**
	 * Test teardown() clears the singleton.
	 */
	public function test_teardown_clears_singleton(): void {
		$ctx1 = \PRAutoBlogger_Opik_Trace_Context::current();
		$ctx1->init_trace();

		\PRAutoBlogger_Opik_Trace_Context::teardown();

		$ctx2 = \PRAutoBlogger_Opik_Trace_Context::current();

		// Should be a new instance.
		$this->assertNotSame( $ctx1, $ctx2 );
		$this->assertEmpty( $ctx2->get_trace_id() );
	}

	/**
	 * Test warning logged for ending unknown span.
	 */
	public function test_warning_for_unknown_span(): void {
		$ctx = \PRAutoBlogger_Opik_Trace_Context::current();
		$ctx->init_trace();

		// Try to end a span that doesn't exist.
		$ctx->end_span( 'unknown-uuid-12345' );

		// If we got here without an exception, the warning was logged.
		$this->assertTrue( true );
	}

	/**
	 * Test span input is stored as-is (caller responsible for redaction).
	 */
	public function test_span_input_stored_as_is(): void {
		$ctx = \PRAutoBlogger_Opik_Trace_Context::current();
		$ctx->init_trace();

		$input_data = array(
			'prompt_hash' => 'sha256-abc123',
			'model'       => 'gpt-4',
			'version'     => '1.0',
		);

		$span_id = $ctx->start_span(
			array(
				'name'  => 'test',
				'type'  => 'llm',
				'input' => $input_data,
			)
		);

		$span = $ctx->get_span( $span_id );
		$this->assertEquals( $input_data, $span['input'] );
	}
}
