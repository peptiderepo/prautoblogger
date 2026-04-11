<?php
/**
 * Base test case for all PRAutoBlogger unit tests.
 *
 * Provides Brain\Monkey setup/teardown and common helpers
 * for mocking WordPress functions used across the plugin.
 *
 * @package PRAutoBlogger\Tests
 */

namespace PRAutoBlogger\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class BaseTestCase extends TestCase {

    /**
     * Set up Brain\Monkey before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Common WordPress function stubs used across many classes.
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
    }

    /**
     * Tear down Brain\Monkey after each test.
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: stub get_option with a map of option_name => value.
     *
     * @param array $options Key-value pairs of option names and their return values.
     */
    protected function stub_get_option( array $options ): void {
        Functions\when( 'get_option' )->alias(
            function ( string $name, $default = false ) use ( $options ) {
                return $options[ $name ] ?? $default;
            }
        );
    }

    /**
     * Helper: stub current_time to return a fixed timestamp.
     *
     * @param string $time MySQL datetime string (Y-m-d H:i:s).
     */
    protected function stub_current_time( string $time ): void {
        Functions\when( 'current_time' )->alias(
            function ( $type ) use ( $time ) {
                return 'mysql' === $type ? $time : strtotime( $time );
            }
        );
    }

    /**
     * Helper: stub wp_insert_post for mock post creation.
     *
     * @param int $post_id The post ID to return.
     */
    protected function stub_wp_insert_post( int $post_id ): void {
        Functions\when( 'wp_insert_post' )->justReturn( $post_id );
    }

    /**
     * Helper: stub wp_update_post for mock post updates.
     */
    protected function stub_wp_update_post(): void {
        Functions\when( 'wp_update_post' )->justReturn( true );
    }

    /**
     * Helper: stub wp_set_post_terms for taxonomy assignment.
     */
    protected function stub_wp_set_post_terms(): void {
        Functions\when( 'wp_set_post_terms' )->justReturn( [] );
    }

    /**
     * Helper: stub get_post to return post data.
     *
     * @param int $post_id The post ID.
     * @param array $post_data The post data to return.
     */
    protected function stub_get_post( int $post_id, array $post_data ): void {
        Functions\when( 'get_post' )->alias(
            function ( $id ) use ( $post_id, $post_data ) {
                return $id === $post_id ? (object) $post_data : null;
            }
        );
    }

    /**
     * Helper: create a mock $wpdb with expectations.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function create_mock_wpdb() {
        $wpdb = $this->getMockBuilder( \stdClass::class )
            ->addMethods( [ 'prepare', 'get_var', 'get_results', 'insert', 'query', 'get_row', 'update' ] )
            ->getMock();

        $wpdb->prefix         = 'wp_';
        $wpdb->insert_id      = 0;
        $wpdb->last_error     = '';
        $wpdb->prab_cost_logs = 'wp_prab_cost_logs';

        return $wpdb;
    }

    /**
     * Helper: get common source data fixture.
     *
     * @return array
     */
    protected function get_source_data_fixture(): array {
        return [
            'id'              => 1,
            'source_type'     => 'reddit',
            'source_id'       => 'post_123',
            'subreddit'       => 'test_subreddit',
            'title'           => 'Test Post Title',
            'content'         => 'This is the post content.',
            'author'          => 'test_author',
            'score'           => 100,
            'comment_count'   => 50,
            'permalink'       => '/r/test/comments/123',
            'collected_at'    => '2026-04-12 10:00:00',
            'metadata'        => [ 'key' => 'value' ],
        ];
    }

    /**
     * Helper: get common analysis result fixture.
     *
     * @return array
     */
    protected function get_analysis_result_fixture(): array {
        return [
            'id'              => 1,
            'analysis_type'   => 'trending_topic',
            'topic'           => 'Test Topic',
            'summary'         => 'This is a summary.',
            'frequency'       => 10,
            'relevance_score' => 0.85,
            'source_ids'      => [ 1, 2, 3 ],
            'analyzed_at'     => '2026-04-12 10:00:00',
            'metadata'        => [ 'key' => 'value' ],
        ];
    }

    /**
     * Helper: get common article idea fixture.
     *
     * @return array
     */
    protected function get_article_idea_fixture(): array {
        return [
            'topic'            => 'Test Article Topic',
            'article_type'     => 'guide',
            'suggested_title'  => 'Ultimate Guide to Test Topic',
            'summary'          => 'A comprehensive guide to test topic.',
            'score'            => 0.92,
            'analysis_id'      => 1,
            'source_ids'       => [ 1, 2 ],
            'key_points'       => [ 'Point 1', 'Point 2', 'Point 3' ],
            'target_keywords'  => [ 'test', 'keyword', 'example' ],
        ];
    }

    /**
     * Helper: get common content request fixture.
     * Note: PRAutoBlogger_Content_Request takes an ArticleIdea object, not an array.
     *
     * @return array Config array suitable for ContentRequest
     */
    protected function get_content_request_fixture(): array {
        return [
            'pipeline_mode'      => 'auto',
            'tone'               => 'professional',
            'min_word_count'     => 1000,
            'max_word_count'     => 2500,
            'niche_description'  => 'Technology and innovation',
            'topic_exclusions'   => [ 'politics', 'religion' ],
        ];
    }

    /**
     * Helper: get common editorial review fixture.
     *
     * @return array
     */
    protected function get_editorial_review_fixture(): array {
        return [
            'verdict'         => 'approved',
            'notes'           => 'Article is well-structured.',
            'revised_content' => null,
            'quality_score'   => 0.88,
            'seo_score'       => 0.92,
            'issues'          => [],
        ];
    }

    /**
     * Helper: get common generation log fixture.
     *
     * @return array
     */
    protected function get_generation_log_fixture(): array {
        return [
            'id'                => 1,
            'post_id'           => 123,
            'run_id'            => 'run_abc123',
            'stage'             => 'analysis',
            'provider'          => 'openrouter',
            'model'             => 'openai/gpt-4',
            'prompt_tokens'     => 1000,
            'completion_tokens' => 500,
            'estimated_cost'    => 0.02,
            'request_json'      => '{}',
            'response_status'   => 'success',
            'error_message'     => null,
            'created_at'        => '2026-04-12 10:00:00',
        ];
    }

    /**
     * Helper: get common content score fixture.
     *
     * @return array
     */
    protected function get_content_score_fixture(): array {
        return [
            'id'                => 1,
            'post_id'           => 123,
            'pageviews'         => 500,
            'avg_time_on_page'  => 3.5,
            'bounce_rate'       => 0.25,
            'comment_count'     => 15,
            'composite_score'   => 0.82,
            'score_factors'     => [ 'engagement' => 0.8, 'seo' => 0.85 ],
            'measured_at'       => '2026-04-12 10:00:00',
        ];
    }
}
