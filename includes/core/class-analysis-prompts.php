<?php
declare(strict_types=1);

/**
 * Prompt construction for the content analysis LLM call.
 *
 * What: Builds system and user prompts for source-data analysis, including
 *       past-performance context for self-improvement feedback loops.
 * Who calls it: PRAutoBlogger_Content_Analyzer::analyze_recent_data().
 * Dependencies: WordPress $wpdb (for performance lookup), no external APIs.
 *
 * @see core/class-content-analyzer.php — Orchestrates analysis and calls these builders.
 * @see ARCHITECTURE.md                 — Data flow step 2.
 */
class PRAutoBlogger_Analysis_Prompts {

	/**
	 * Build the system prompt for content analysis.
	 *
	 * @param string $niche               Niche description from settings.
	 * @param string $performance_context  Past performance data summary.
	 * @param int    $target_count         Number of ideas the LLM should produce.
	 * @return string System prompt text.
	 */
	public static function build_system_prompt( string $niche, string $performance_context, int $target_count = 6 ): string {
		$prompt = "You are a content strategist analyzing social media discussions to find article ideas for a blog";
		if ( '' !== $niche ) {
			$prompt .= " in the {$niche} niche";
		}
		$prompt .= ".\n\n";

		$prompt .= "IMPORTANT: You must identify exactly {$target_count} DISTINCT article ideas. ";
		$prompt .= "Each idea must cover a substantially different topic — no two ideas should overlap ";
		$prompt .= "in their main subject. Aim for diversity across these categories:\n";
		$prompt .= "1. QUESTIONS: Recurring questions people ask (\"How do I...\", \"What is...\", \"Is it safe to...\")\n";
		$prompt .= "2. COMPLAINTS: Pain points, frustrations, or problems people report\n";
		$prompt .= "3. COMPARISONS: Product/method comparisons people discuss (\"X vs Y\", \"Which is better\")\n";
		$prompt .= "4. NEWS: Recent developments, rule changes, or trends people are discussing\n";
		$prompt .= "5. GUIDES: How-to topics, best practices, or educational content people need\n\n";

		$prompt .= "Spread your {$target_count} ideas across multiple categories. ";
		$prompt .= "Be specific — \"peptide dosing for BPC-157\" is better than \"peptide information\". ";
		$prompt .= "Each idea should be narrow enough to be a single focused blog post.\n\n";

		$prompt .= "For each idea, provide:\n";
		$prompt .= "- type: 'question', 'complaint', 'comparison', 'news', or 'guide'\n";
		$prompt .= "- topic: A clear, specific, narrow topic description\n";
		$prompt .= "- summary: 1-2 sentence summary of why this topic matters\n";
		$prompt .= "- frequency: How many of the provided posts relate to this topic\n";
		$prompt .= "- relevance_score: 0.0 to 1.0 indicating how relevant and article-worthy this is\n";
		$prompt .= "- suggested_title: A compelling, unique blog post title\n";
		$prompt .= "- key_points: Array of 3-5 key points the article should cover\n";
		$prompt .= "- target_keywords: Array of SEO keywords for this topic\n\n";

		if ( '' !== $performance_context ) {
			$prompt .= $performance_context . "\n\n";
		}

		$prompt .= "Respond with valid JSON containing exactly {$target_count} patterns:\n";
		$prompt .= '{"patterns": [{"type": "...", "topic": "...", "summary": "...", "frequency": N, "relevance_score": 0.X, "suggested_title": "...", "key_points": [...], "target_keywords": [...]}]}';

		return $prompt;
	}

	/**
	 * Build the user prompt containing the actual source data.
	 *
	 * @param string $summary      Formatted source data summary.
	 * @param int    $target_count Number of ideas requested.
	 * @return string User prompt text.
	 */
	public static function build_user_prompt( string $summary, int $target_count = 6 ): string {
		return "Here are the recent social media posts and comments to analyze. "
			. "Find {$target_count} distinct, diverse article ideas from this data:\n\n"
			. $summary;
	}

	/**
	 * Get past content performance data to feed into analysis for self-improvement.
	 *
	 * Side effects: database read.
	 *
	 * @return string Summary of what topics performed well, or empty string if no data.
	 */
	public static function get_performance_context(): string {
		global $wpdb;
		$scores_table = $wpdb->prefix . 'prautoblogger_content_scores';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$top_posts = $wpdb->get_results(
			"SELECT cs.post_id, cs.composite_score, p.post_title
			FROM {$scores_table} cs
			JOIN {$wpdb->posts} p ON p.ID = cs.post_id
			WHERE cs.composite_score > 0
			ORDER BY cs.composite_score DESC
			LIMIT 5",
			ARRAY_A
		);

		if ( empty( $top_posts ) ) {
			return '';
		}

		$lines = [ 'Top performing past articles (learn from these):' ];
		foreach ( $top_posts as $post ) {
			$lines[] = sprintf(
				'- "%s" (score: %.1f)',
				$post['post_title'],
				(float) $post['composite_score']
			);
		}

		return implode( "\n", $lines );
	}
}
