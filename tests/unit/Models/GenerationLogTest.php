<?php
/**
 * Tests for PRAutoBlogger_Generation_Log value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class GenerationLogTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-generation-log.php';
    }

    /**
     * Test construction with all fields.
     */
    public function test_constructor_sets_all_properties(): void {
        $log = new \PRAutoBlogger_Generation_Log(
            'google/gemini-2.0-flash-001',
            'content_generation',
            500,
            1200,
            0.0034,
            2.45,
            true,
            ''
        );

        $this->assertSame( 'google/gemini-2.0-flash-001', $log->get_model() );
        $this->assertSame( 'content_generation', $log->get_step() );
        $this->assertSame( 500, $log->get_prompt_tokens() );
        $this->assertSame( 1200, $log->get_completion_tokens() );
        $this->assertSame( 0.0034, $log->get_cost() );
        $this->assertSame( 2.45, $log->get_duration() );
        $this->assertTrue( $log->is_success() );
        $this->assertSame( '', $log->get_error() );
    }

    /**
     * Test failed generation log.
     */
    public function test_failed_generation_log(): void {
        $log = new \PRAutoBlogger_Generation_Log(
            'anthropic/claude-3-haiku',
            'analysis',
            200,
            0,
            0.0,
            0.0,
            false,
            'Rate limit exceeded'
        );

        $this->assertFalse( $log->is_success() );
        $this->assertSame( 'Rate limit exceeded', $log->get_error() );
        $this->assertSame( 0, $log->get_completion_tokens() );
    }

    /**
     * Test total tokens calculation.
     */
    public function test_get_total_tokens(): void {
        $log = new \PRAutoBlogger_Generation_Log(
            'model/x', 'step', 300, 700, 0.01, 1.0, true, ''
        );

        $this->assertSame( 1000, $log->get_total_tokens() );
    }

    /**
     * Test to_array completeness.
     */
    public function test_to_array_returns_all_fields(): void {
        $log = new \PRAutoBlogger_Generation_Log(
            'model', 'step', 100, 200, 0.001, 0.5, true, ''
        );

        $array = $log->to_array();
        $this->assertArrayHasKey( 'model', $array );
        $this->assertArrayHasKey( 'step', $array );
        $this->assertArrayHasKey( 'prompt_tokens', $array );
        $this->assertArrayHasKey( 'completion_tokens', $array );
        $this->assertArrayHasKey( 'cost', $array );
        $this->assertArrayHasKey( 'duration', $array );
        $this->assertArrayHasKey( 'success', $array );
        $this->assertArrayHasKey( 'error', $array );
    }
}
