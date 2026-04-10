<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Unit tests for Autoblogger_Generation_Log value object.
 *
 * Verifies construction, getters, and to_db_row() serialization.
 * These are the records that power the cost dashboard — data integrity is critical.
 */
class GenerationLogTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock current_time() which the constructor calls as default for created_at.
		Functions\when( 'current_time' )->justReturn( '2026-04-10 12:00:00' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_constructor_sets_all_fields(): void {
		$data = [
			'id'                => 42,
			'post_id'           => 100,
			'run_id'            => 'abc-123',
			'stage'             => 'draft',
			'provider'          => 'OpenRouter',
			'model'             => 'anthropic/claude-sonnet-4',
			'prompt_tokens'     => 1500,
			'completion_tokens' => 800,
			'estimated_cost'    => 0.0165,
			'request_json'      => '{"test": true}',
			'response_status'   => 'success',
			'error_message'     => null,
			'created_at'        => '2026-04-10 10:00:00',
		];

		$log = new Autoblogger_Generation_Log( $data );

		$this->assertSame( 42, $log->get_id() );
		$this->assertSame( 100, $log->get_post_id() );
		$this->assertSame( 'abc-123', $log->get_run_id() );
		$this->assertSame( 'draft', $log->get_stage() );
		$this->assertSame( 'OpenRouter', $log->get_provider() );
		$this->assertSame( 'anthropic/claude-sonnet-4', $log->get_model() );
		$this->assertSame( 1500, $log->get_prompt_tokens() );
		$this->assertSame( 800, $log->get_completion_tokens() );
		$this->assertEqualsWithDelta( 0.0165, $log->get_estimated_cost(), 0.0001 );
		$this->assertSame( 'success', $log->get_response_status() );
		$this->assertNull( $log->get_error_message() );
		$this->assertSame( '2026-04-10 10:00:00', $log->get_created_at() );
	}

	public function test_constructor_defaults_for_missing_fields(): void {
		$log = new Autoblogger_Generation_Log( [] );

		$this->assertSame( 0, $log->get_id() );
		$this->assertNull( $log->get_post_id() );
		$this->assertNull( $log->get_run_id() );
		$this->assertSame( '', $log->get_stage() );
		$this->assertSame( 0, $log->get_prompt_tokens() );
		$this->assertSame( 0, $log->get_completion_tokens() );
		$this->assertEqualsWithDelta( 0.0, $log->get_estimated_cost(), 0.0001 );
		$this->assertSame( 'success', $log->get_response_status() );
		$this->assertSame( '2026-04-10 12:00:00', $log->get_created_at() );
	}

	public function test_to_db_row_excludes_id(): void {
		$log = new Autoblogger_Generation_Log( [
			'id'       => 99,
			'post_id'  => 50,
			'run_id'   => 'run-abc',
			'stage'    => 'review',
			'provider' => 'OpenRouter',
			'model'    => 'anthropic/claude-3.5-haiku',
		] );

		$row = $log->to_db_row();

		// ID should NOT be in the DB row (it's auto-increment).
		$this->assertArrayNotHasKey( 'id', $row );

		// Key fields should be present.
		$this->assertSame( 50, $row['post_id'] );
		$this->assertSame( 'run-abc', $row['run_id'] );
		$this->assertSame( 'review', $row['stage'] );
		$this->assertSame( 'OpenRouter', $row['provider'] );
	}

	public function test_to_db_row_has_all_expected_keys(): void {
		$log = new Autoblogger_Generation_Log( [ 'stage' => 'analysis' ] );
		$row = $log->to_db_row();

		$expected_keys = [
			'post_id', 'run_id', 'stage', 'provider', 'model',
			'prompt_tokens', 'completion_tokens', 'estimated_cost',
			'request_json', 'response_status', 'error_message', 'created_at',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $row, "Missing key: {$key}" );
		}
	}

	public function test_numeric_fields_are_cast_correctly(): void {
		$log = new Autoblogger_Generation_Log( [
			'id'                => '42',
			'post_id'           => '100',
			'prompt_tokens'     => '1500',
			'completion_tokens' => '800',
			'estimated_cost'    => '0.0165',
		] );

		// String values should be cast to proper types.
		$this->assertSame( 42, $log->get_id() );
		$this->assertSame( 100, $log->get_post_id() );
		$this->assertSame( 1500, $log->get_prompt_tokens() );
		$this->assertSame( 800, $log->get_completion_tokens() );
		$this->assertIsFloat( $log->get_estimated_cost() );
	}
}
