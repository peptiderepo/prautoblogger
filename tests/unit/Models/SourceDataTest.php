<?php
/**
 * Tests for PRAutoBlogger_Source_Data value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class SourceDataTest extends BaseTestCase {

    /**
     * Require the class under test.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-source-data.php';
    }

    /**
     * Test construction with all required fields.
     */
    public function test_constructor_sets_all_properties(): void {
        $data = new \PRAutoBlogger_Source_Data(
            'Test Title',
            'https://example.com/article',
            'This is the content body.',
            'rss'
        );

        $this->assertSame( 'Test Title', $data->get_title() );
        $this->assertSame( 'https://example.com/article', $data->get_url() );
        $this->assertSame( 'This is the content body.', $data->get_content() );
        $this->assertSame( 'rss', $data->get_source_type() );
    }

    /**
     * Test that to_array returns all fields.
     */
    public function test_to_array_returns_complete_representation(): void {
        $data = new \PRAutoBlogger_Source_Data(
            'Array Test',
            'https://example.com',
            'Content here.',
            'manual'
        );

        $array = $data->to_array();

        $this->assertIsArray( $array );
        $this->assertArrayHasKey( 'title', $array );
        $this->assertArrayHasKey( 'url', $array );
        $this->assertArrayHasKey( 'content', $array );
        $this->assertArrayHasKey( 'source_type', $array );
        $this->assertSame( 'Array Test', $array['title'] );
    }

    /**
     * Test construction with empty content is allowed.
     */
    public function test_allows_empty_content(): void {
        $data = new \PRAutoBlogger_Source_Data(
            'Title Only',
            'https://example.com',
            '',
            'rss'
        );

        $this->assertSame( '', $data->get_content() );
    }
}
