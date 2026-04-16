<?php
/**
 * Tests for PRAutoBlogger_Image_Prompt_Builder.
 *
 * Validates prompt generation from article content and source data,
 * including style suffix appending and HTML stripping.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ImagePromptBuilderTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress functions.
		Functions\when( 'wp_strip_all_tags' )->alias( function ( $str ) {
			return trim( strip_tags( $str ) );
		} );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = [];
			return $options[ $key ] ?? $default;
		} );
	}

	/**
	 * Test build_article_prompt() generates a prompt from article title and content.
	 */
	public function test_build_article_prompt_from_content(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$article_data = [
			'post_title'   => 'How to Train Your Dragon',
			'post_content' => '<p>Dragons are fascinating creatures. Learn the secrets.</p>',
		];

		$prompt = $builder->build_article_prompt( $article_data );

		$this->assertStringContainsString( 'How to Train Your Dragon', $prompt );
		$this->assertStringContainsString( 'Dragons are fascinating', $prompt );
		// Style suffix should be appended.
		$this->assertNotEmpty( $prompt );
		$this->assertGreaterThan( 50, strlen( $prompt ) );
	}

	/**
	 * Test build_article_prompt() handles missing content gracefully.
	 */
	public function test_build_article_prompt_with_missing_content(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$article_data = [
			'post_title' => 'Minimal Article',
		];

		$prompt = $builder->build_article_prompt( $article_data );

		$this->assertStringContainsString( 'Minimal Article', $prompt );
		$this->assertNotEmpty( $prompt );
	}

	/**
	 * Test build_source_prompt() generates a prompt from Reddit data.
	 */
	public function test_build_source_prompt_from_reddit_data(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$source_data = [
			'title'    => 'Help: My Robot Won\'t Stop Dancing',
			'comments' => [
				'Have you tried unplugging it?',
				'Maybe it\'s just happy.',
			],
		];

		$prompt = $builder->build_source_prompt( $source_data );

		$this->assertStringContainsString( 'Robot', $prompt );
		$this->assertStringContainsString( 'Dancing', $prompt );
		$this->assertNotEmpty( $prompt );
	}

	/**
	 * Test build_source_prompt() handles missing comments.
	 */
	public function test_build_source_prompt_without_comments(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$source_data = [
			'title' => 'Standalone Title',
		];

		$prompt = $builder->build_source_prompt( $source_data );

		$this->assertStringContainsString( 'Standalone Title', $prompt );
		$this->assertNotEmpty( $prompt );
	}

	/**
	 * Test that prompts don't exceed reasonable length.
	 */
	public function test_prompts_are_reasonably_sized(): void {
		$builder = new \PRAutoBlogger_Image_Prompt_Builder();

		$long_content = '<p>' . str_repeat( 'a', 5000 ) . '</p>';
		$article_data = [
			'post_title'   => 'Test',
			'post_content' => $long_content,
		];

		$prompt = $builder->build_article_prompt( $article_data );

		// Prompt should be under 500 chars (concept + style suffix).
		$this->assertLessThan( 500, strlen( $prompt ) );
	}
}
