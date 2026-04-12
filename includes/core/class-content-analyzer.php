<?php
declare(strict_types=1);

/**
 * Analyzes collected source data using an LLM to detect recurring patterns:
 * questions people ask, complaints/pain points, and product comparisons.
 *
 * Uses a cheap model (configurable) to keep analysis costs low.
 * Batches source data into manageable chunks for the LLM context window.
 *
 * Triggered by: PRAutoBlogger::run_generation_pipeline() (step 2).
 * Dependencies: LLM_Provider_Interface, Cost_Tracker, WordPress $wpdb.
 *
 * @see providers/interface-llm-provider.php — LLM used for analysis.
 * @see core/class-cost-tracker.php          — Logs API costs.
 * @see core/class-idea-scorer.php           — Consumes analysis results.
 * @see ARCHITECTURE.md                      — Data flow step 2.
 */
class PRAutoBlogger_Content_Analyzer {

	private PRAutoBlogger_LLM_Provider_Interface $llm;
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	public function __construct(
		PRAutoBlogger_LLM_Provider_Interface $llm,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	) {
		$this->llm          = $llm;
		$this->cost_tracker = $cost_tracker;
	}

	/**
	 * Analyze recently collected source data for content patterns.
	 *
	 * Fetches unanalyzed data from the last 24 hours, batches it, sends to LLM
	 * for pattern detection, and stores results in ab_analysis_results.
	 *
	 * Side effects: LLM API call, database reads/writes.
	 *
	 * @return PRAutoBlogger_Analysis_Result[] Detected patterns.
	 */
	/**
	 * @param int $target_idea_count How many distinct topic ideas the LLM should produce.
	 *                               Defaults to 6 so the scorer has enough to pick from.
	 */
	public function analyze_recent_data( int $target_idea_count = 6 ): array {
		$source_items = $this->fetch_recent_source_data();

		if ( empty( $source_items ) ) {
			PRAutoBlogger_Logger::instance()->info( 'No new source data to analyze.', 'analyzer' );
			return [];
		}

		$niche = get_option( 'prautoblogger_niche_description', '' );
		$model = get_option( 'prautoblogger_analysis_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );

		// Build the source data summary for the LLM (trim to avoid huge contexts).
		$summary = $this->build_source_summary( $source_items );

		// Include past performance data for self-improvement.
		$performance_context = $this->get_performance_context();

		$system_prompt = $this->build_system_prompt( $niche, $performance_context, $target_idea_count );
		$user_prompt   = $this->build_user_prompt( $summary, $target_idea_count );

		$response = $this->llm->send_chat_completion(
			[
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user', 'content' => $user_prompt ],
			],
			$model,
			[
				'temperature'     => 0.5,
				'max_tokens'      => 8000,
				'response_format' => [ 'type' => 'json_object' ],
			]
		);

		// Log the cost.
		$this->cost_tracker->log_api_call(
			null,
			'analysis',
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		// Parse LLM response into Analysis_Result objects.
		$results = $this->parse_analysis_response( $response['content'], $source_items );

		// Store in database.
		$this->store_results( $results );

		return $results;
	}

	/**
	 * Fetch source data collected in the last 24 hours.
	 *
	 * @return array<int, array<string, mixed>> Raw database rows.
	 */
	private function fetch_recent_source_data(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_source_data';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE collected_at >= %s ORDER BY score DESC LIMIT 200",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Build a text summary of source data for the LLM to analyze.
	 *
	 * Prioritizes high-engagement content and keeps total text under ~8000 tokens.
	 *
	 * @param array<int, array<string, mixed>> $items Source data rows.
	 *
	 * @return string Formatted text summary.
	 */
	private function build_source_summary( array $items ): string {
		$parts = [];
		$char_limit = 30000; // Roughly ~7500 tokens.
		$current_chars = 0;

		foreach ( $items as $item ) {
			$entry = sprintf(
				"[%s] r/%s | Score: %d | Comments: %d\nTitle: %s\n%s\n---",
				$item['source_type'],
				$item['subreddit'] ?? 'unknown',
				(int) $item['score'],
				(int) $item['comment_count'],
				$item['title'] ?? '(comment)',
				mb_substr( $item['content'] ?? '', 0, 500 )
			);

			if ( $current_chars + strlen( $entry ) > $char_limit ) {
				break;
			}

			$parts[] = $entry;
			$current_chars += strlen( $entry );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Get past content performance data to feed into analysis for self-improvement.
	 *
	 * @return string Summary of what topics performed well/poorly.
	 */
	private function get_performance_context(): string {
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

	/**
	 * Build the system prompt for content analysis.
	 *
	 * @param string $niche               Niche description from settings.
	 * @param string $performance_context  Past performance data.
	 *
	 * @return string
	 */
	private function build_system_prompt( string $niche, string $performance_context, int $target_count = 6 ): string {
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
	 * @param string $summary Formatted source data summary.
	 *
	 * @return string
	 */
	private function build_user_prompt( string $summary, int $target_count = 6 ): string {
		return "Here are the recent social media posts and comments to analyze. "
			. "Find {$target_count} distinct, diverse article ideas from this data:\n\n"
			. $summary;
	}

	/**
	 * Parse the LLM's JSON response into Analysis_Result objects.
	 *
	 * @param string                          $content      LLM response content (JSON).
	 * @param array<int, array<string, mixed>> $source_items Original source data for ID mapping.
	 *
	 * @return PRAutoBlogger_Analysis_Result[]
	 */
	private function parse_analysis_response( string $content, array $source_items ): array {
		$data = json_decode( $content, true );
		if ( ! isset( $data['patterns'] ) || ! is_array( $data['patterns'] ) ) {
			PRAutoBlogger_Logger::instance()->error( 'Analysis response was not valid JSON or missing patterns key.', 'analyzer' );
			return [];
		}

		$source_ids = array_column( $source_items, 'id' );
		$results    = [];

		foreach ( $data['patterns'] as $pattern ) {
			$results[] = new PRAutoBlogger_Analysis_Result( [
				'analysis_type'   => sanitize_text_field( $pattern['type'] ?? 'question' ),
				'topic'           => sanitize_text_field( $pattern['topic'] ?? '' ),
				'summary'         => sanitize_textarea_field( $pattern['summary'] ?? '' ),
				'frequency'       => absint( $pattern['frequency'] ?? 1 ),
				'relevance_score' => (float) ( $pattern['relevance_score'] ?? 0.0 ),
				'source_ids'      => $source_ids,
				'analyzed_at'     => current_time( 'mysql' ),
				'metadata'        => [
					'suggested_title'  => $pattern['suggested_title'] ?? '',
					'key_points'       => $pattern['key_points'] ?? [],
					'target_keywords'  => $pattern['target_keywords'] ?? [],
				],
			] );
		}

		return $results;
	}

	/**
	 * Store analysis results in the database.
	 *
	 * Side effects: database inserts.
	 *
	 * @param PRAutoBlogger_Analysis_Result[] $results
	 *
	 * @return void
	 */
	private function store_results( array $results ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_analysis_results';

		foreach ( $results as $result ) {
			$row = $result->to_db_row();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
		}
	}
}
