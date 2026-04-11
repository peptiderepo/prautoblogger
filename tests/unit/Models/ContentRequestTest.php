<?php
/**
 * Tests for PRAutoBlogger_Content_Request value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class ContentRequestTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-content-request.php';
    }

    /**
     * Test construction with required fields.
     */
    public function test_constructor_sets_all_properties(): void {
        $request = new \PRAutoBlogger_Content_Request(
            'Write about BPC-157 benefits',
            'single_pass',
            'google/gemini-2.0-flash-001',
            [ 'tone' => 'informative', 'length' => 'long' ]
        );

        $this->assertSame( 'Write about BPC-157 benefits', $request->get_prompt() );
        $this->assertSame( 'single_pass', $request->get_pipeline_mode() );
        $this->assertSame( 'google/gemini-2.0-flash-001', $request->get_model() );
        $this->assertSame( 'informative', $request->get_option( 'tone' ) );
        $this->assertSame( 'long', $request->get_option( 'length' ) );
    }

    /**
     * Test get_option returns default for missing keys.
     */
    public function test_get_option_returns_default_for_missing_key(): void {
        $request = new \PRAutoBlogger_Content_Request(
            'prompt',
            'single_pass',
            'model/name',
            []
        );

        $this->assertNull( $request->get_option( 'nonexistent' ) );
        $this->assertSame( 'fallback', $request->get_option( 'nonexistent', 'fallback' ) );
    }

    /**
     * Test to_array completeness.
     */
    public function test_to_array_returns_all_fields(): void {
        $request = new \PRAutoBlogger_Content_Request(
            'test prompt',
            'multi_step',
            'model/x',
            [ 'key' => 'val' ]
        );

        $array = $request->to_array();
        $this->assertIsArray( $array );
        $this->assertArrayHasKey( 'prompt', $array );
        $this->assertArrayHasKey( 'pipeline_mode', $array );
        $this->assertArrayHasKey( 'model', $array );
        $this->assertArrayHasKey( 'options', $array );
    }
}
