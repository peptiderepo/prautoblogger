<?php
/**
 * Tests for PRAutoBlogger_Generation_Log value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class GenerationLogTest extends BaseTestCase {

    /**
     * Test construction with array.
     */
    public function test_constructor_with_array(): void {
        $data = $this->get_generation_log_fixture();

        $log = new \PRAutoBlogger_Generation_Log( $data );

        $this->assertSame( 1, $log->get_id() );
        $this->assertSame( 123, $log->get_post_id() );
        $this->assertSame( 'run_abc123', $log->get_run_id() );
        $this->assertSame( 'analysis', $log->get_stage() );
        $this->assertSame( 'openrouter', $log->get_provider() );
        $this->assertSame( 'openai/gpt-4', $log->get_model() );
        $this->assertSame( 1000, $log->get_prompt_tokens() );
        $this->assertSame( 500, $log->get_completion_tokens() );
        $this->assertSame( 0.02, $log->get_estimated_cost() );
        $this->assertSame( '{}', $log->get_request_json() );
        $this->assertSame( 'success', $log->get_response_status() );
        $this->assertNull( $log->get_error_message() );
        $this->assertSame( '2026-04-12 10:00:00', $log->get_created_at() );
    }

    /**
     * Test getters return correct types.
     */
    public function test_getters_return_correct_types(): void {
        $log = new \PRAutoBlogger_Generation_Log(
            $this->get_generation_log_fixture()
        );

        $this->assertIsInt( $log->get_id() );
        $this->assertIsInt( $log->get_post_id() );
        $this->assertIsString( $log->get_run_id() );
        $this->assertIsString( $log->get_stage() );
        $this->assertIsString( $log->get_provider() );
        $this->assertIsString( $log->get_model() );
        $this->assertIsInt( $log->get_prompt_tokens() );
        $this->assertIsInt( $log->get_completion_tokens() );
        $this->assertIsFloat( $log->get_estimated_cost() );
        $this->assertIsString( $log->get_request_json() );
        $this->assertIsString( $log->get_response_status() );
        $this->assertIsString( $log->get_created_at() );
    }

    /**
     * Test with error message.
     */
    public function test_with_error_message(): void {
        $data = $this->get_generation_log_fixture();
        $data['response_status'] = 'error';
        $data['error_message']   = 'API rate limit exceeded';

        $log = new \PRAutoBlogger_Generation_Log( $data );

        $this->assertSame( 'error', $log->get_response_status() );
        $this->assertSame( 'API rate limit exceeded', $log->get_error_message() );
    }

    /**
     * Test with zero tokens.
     */
    public function test_with_zero_tokens(): void {
        $data = $this->get_generation_log_fixture();
        $data['prompt_tokens']     = 0;
        $data['completion_tokens'] = 0;

        $log = new \PRAutoBlogger_Generation_Log( $data );

        $this->assertSame( 0, $log->get_prompt_tokens() );
        $this->assertSame( 0, $log->get_completion_tokens() );
    }

    /**
     * Test with zero cost.
     */
    public function test_with_zero_cost(): void {
        $data = $this->get_generation_log_fixture();
        $data['estimated_cost'] = 0.0;

        $log = new \PRAutoBlogger_Generation_Log( $data );

        $this->assertSame( 0.0, $log->get_estimated_cost() );
    }

    /**
     * Test to_db_row returns array.
     */
    public function test_to_db_row_returns_array(): void {
        $log = new \PRAutoBlogger_Generation_Log(
            $this->get_generation_log_fixture()
        );

        $row = $log->to_db_row();

        $this->assertIsArray( $row );
        $this->assertArrayHasKey( 'post_id', $row );
        $this->assertArrayHasKey( 'run_id', $row );
        $this->assertArrayHasKey( 'stage', $row );
        $this->assertArrayHasKey( 'provider', $row );
        $this->assertArrayHasKey( 'model', $row );
    }

    /**
     * Test different response statuses.
     */
    public function test_different_response_statuses(): void {
        $data = $this->get_generation_log_fixture();

        $data['response_status'] = 'success';
        $success = new \PRAutoBlogger_Generation_Log( $data );
        $this->assertSame( 'success', $success->get_response_status() );

        $data['response_status'] = 'error';
        $error = new \PRAutoBlogger_Generation_Log( $data );
        $this->assertSame( 'error', $error->get_response_status() );

        $data['response_status'] = 'timeout';
        $timeout = new \PRAutoBlogger_Generation_Log( $data );
        $this->assertSame( 'timeout', $timeout->get_response_status() );
    }
}
