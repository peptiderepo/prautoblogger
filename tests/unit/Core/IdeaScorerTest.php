<?php
/**
 * Tests for PRAutoBlogger_Idea_Scorer.
 *
 * Validates idea ranking, deduplication, and score normalization.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class IdeaScorerTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-article-idea.php';
        require_once PRAB_PLUGIN_DIR . 'includes/core/class-prab-idea-scorer.php';
    }

    /**
     * Test rank_ideas returns ideas sorted by score descending.
     */
    public function test_rank_ideas_sorts_by_score_descending(): void {
        $ideas = [
            new \PRAutoBlogger_Article_Idea( 'Low Score', 'Desc', [], 'cat', 30.0 ),
            new \PRAutoBlogger_Article_Idea( 'High Score', 'Desc', [], 'cat', 90.0 ),
            new \PRAutoBlogger_Article_Idea( 'Mid Score', 'Desc', [], 'cat', 60.0 ),
        ];

        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $ranked = $scorer->rank_ideas( $ideas );

        $this->assertSame( 'High Score', $ranked[0]->get_title() );
        $this->assertSame( 'Mid Score', $ranked[1]->get_title() );
        $this->assertSame( 'Low Score', $ranked[2]->get_title() );
    }

    /**
     * Test rank_ideas limits output to requested count.
     */
    public function test_rank_ideas_limits_results(): void {
        $ideas = [];
        for ( $i = 0; $i < 20; $i++ ) {
            $ideas[] = new \PRAutoBlogger_Article_Idea(
                "Idea {$i}", 'Description', [], 'cat', (float) $i
            );
        }

        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $ranked = $scorer->rank_ideas( $ideas, 5 );

        $this->assertCount( 5, $ranked );
        // Should be the top 5 by score.
        $this->assertSame( 'Idea 19', $ranked[0]->get_title() );
    }

    /**
     * Test deduplicate removes ideas with similar titles.
     */
    public function test_deduplicate_removes_similar_titles(): void {
        $ideas = [
            new \PRAutoBlogger_Article_Idea( 'BPC-157 Benefits for Gut Health', 'Desc', [], 'cat', 80.0 ),
            new \PRAutoBlogger_Article_Idea( 'BPC-157 Benefits for Gut Health', 'Different desc', [], 'cat', 75.0 ),
            new \PRAutoBlogger_Article_Idea( 'Thymosin Beta-4 Research Update', 'Desc', [], 'cat', 70.0 ),
        ];

        $scorer      = new \PRAutoBlogger_Idea_Scorer();
        $deduplicated = $scorer->deduplicate( $ideas );

        $this->assertCount( 2, $deduplicated );
    }

    /**
     * Test deduplicate keeps the higher-scored duplicate.
     */
    public function test_deduplicate_keeps_higher_score(): void {
        $ideas = [
            new \PRAutoBlogger_Article_Idea( 'Same Title Here', 'Low version', [], 'cat', 40.0 ),
            new \PRAutoBlogger_Article_Idea( 'Same Title Here', 'High version', [], 'cat', 90.0 ),
        ];

        $scorer      = new \PRAutoBlogger_Idea_Scorer();
        $deduplicated = $scorer->deduplicate( $ideas );

        $this->assertCount( 1, $deduplicated );
        $this->assertSame( 90.0, $deduplicated[0]->get_score() );
    }

    /**
     * Test rank_ideas handles empty input.
     */
    public function test_rank_ideas_handles_empty_input(): void {
        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $ranked = $scorer->rank_ideas( [] );

        $this->assertIsArray( $ranked );
        $this->assertEmpty( $ranked );
    }

    /**
     * Test rank_ideas handles single idea.
     */
    public function test_rank_ideas_handles_single_idea(): void {
        $ideas = [
            new \PRAutoBlogger_Article_Idea( 'Only Idea', 'Desc', [], 'cat', 50.0 ),
        ];

        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $ranked = $scorer->rank_ideas( $ideas );

        $this->assertCount( 1, $ranked );
        $this->assertSame( 'Only Idea', $ranked[0]->get_title() );
    }
}
