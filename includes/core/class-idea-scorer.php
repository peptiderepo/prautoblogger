<?php
declare(strict_types=1);

/**
 * Ranks and deduplicates article ideas from analysis results.
 *
 * Compares against existing published posts to avoid topic overlap,
 * scores ideas by relevance, frequency, and freshness, then returns
 * the top N ideas matching the daily target.
 *
 * Triggered by: PRAutoBlogger::run_generation_pipeline() (step 3).
 * Dependencies: WordPress $wpdb, WP_Query.
 *
 * @see core/class-content-analyzer.php  — Produces the analysis results we consume.
 * @see core/class-content-generator.php — Consumes the ranked ideas we produce.
 * @see ARCHITECTURE.md                  — Data flow step 3.
 */
class PRAutoBlogger_Idea_Scorer {

	/** @var bool Whether to skip the has_similar_post() check. */
	private bool $skip_dedup = false;

	/**
	 * @param bool $skip True to skip deduplication.
	 *
	 * @return $this
	 */
	public function set_skip_dedup( bool $skip ): self {
		$this->skip_dedup = $skip;
		return $this;
	}

	/**
	 * Score, deduplicate, and rank article ideas.
	 *
	 * @param PRAutoBlogger_Analysis_Result[] $analysis_results Raw analysis output.
	 * @param int                           $target_count     Number of ideas to return.
	 *
	 * @return PRAutoBlogger_Article_Idea[] Top-ranked ideas, sorted by score descending.
	 */
	public function score_and_rank( array $analysis_results, int $target_count ): array {
		$exclusions    = json_decode( get_option( 'prautoblogger_topic_exclusions', '[]' ), true ) ?: [];
		$ideas         = [];
		$excluded_count = 0;
		$deduped_count  = 0;

		foreach ( $analysis_results as $result ) {
			// Skip excluded topics.
			if ( $this->is_excluded( $result->get_topic(), $exclusions ) ) {
				$excluded_count++;
				continue;
			}

			// Skip if we've already published something very similar
			// (unless dedup is disabled for manual runs).
			if ( ! $this->skip_dedup && $this->has_similar_post( $result->get_topic() ) ) {
				$deduped_count++;
				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Dedup: skipping "%s" — ≥60%% keyword overlap with existing post.', substr( $result->get_topic(), 0, 80 ) ),
					'scorer'
				);
				continue;
			}

			$metadata = $result->get_metadata() ?? [];

			$ideas[] = new PRAutoBlogger_Article_Idea( [
				'topic'           => $result->get_topic(),
				'article_type'    => $this->map_type( $result->get_analysis_type() ),
				'suggested_title' => $metadata['suggested_title'] ?? $result->get_topic(),
				'summary'         => $result->get_summary() ?? '',
				'score'           => $this->compute_score( $result ),
				'analysis_id'     => $result->get_id(),
				'source_ids'      => $result->get_source_ids(),
				'key_points'      => $metadata['key_points'] ?? [],
				'target_keywords' => $metadata['target_keywords'] ?? [],
			] );
		}

		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Scorer: %d input → %d viable, %d deduped, %d excluded. Returning top %d.',
				count( $analysis_results ),
				count( $ideas ),
				$deduped_count,
				$excluded_count,
				min( $target_count, count( $ideas ) )
			),
			'scorer'
		);

		// Sort by score descending.
		usort( $ideas, static function ( PRAutoBlogger_Article_Idea $a, PRAutoBlogger_Article_Idea $b ): int {
			return $b->get_score() <=> $a->get_score();
		} );

		return array_slice( $ideas, 0, $target_count );
	}

	/**
	 * Compute a composite score for an analysis result.
	 *
	 * Factors: LLM relevance score (50%), frequency (30%), recency bonus (20%).
	 *
	 * @param PRAutoBlogger_Analysis_Result $result
	 *
	 * @return float Score between 0 and 1.
	 */
	private function compute_score( PRAutoBlogger_Analysis_Result $result ): float {
		$relevance = min( $result->get_relevance_score(), 1.0 );
		$frequency = min( $result->get_frequency() / 10.0, 1.0 );

		// Recency: analyzed within last 24h gets full bonus.
		$hours_ago = ( time() - strtotime( $result->get_analyzed_at() ) ) / 3600.0;
		$recency   = max( 0.0, 1.0 - ( $hours_ago / 48.0 ) );

		return ( $relevance * 0.5 ) + ( $frequency * 0.3 ) + ( $recency * 0.2 );
	}

	/**
	 * Check if a topic should be excluded based on user-configured exclusions.
	 *
	 * @param string   $topic      Topic text.
	 * @param string[] $exclusions List of excluded terms/phrases.
	 *
	 * @return bool
	 */
	private function is_excluded( string $topic, array $exclusions ): bool {
		$topic_lower = strtolower( $topic );
		foreach ( $exclusions as $exclusion ) {
			if ( false !== strpos( $topic_lower, strtolower( trim( $exclusion ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a substantially similar post already exists.
	 *
	 * Compares meaningful keywords from the proposed topic against existing
	 * post titles. A match requires ≥60% of the topic's keywords to appear
	 * in a single existing title — not just one shared niche word.
	 *
	 * The previous implementation used WP_Query 's' (WordPress search) which
	 * matches ANY word. On a niche blog every topic shares common vocabulary
	 * (e.g. "BPC-157", "peptide"), so all ideas were false-positive deduped.
	 *
	 * @param string $topic Topic to check.
	 *
	 * @return bool True if a similar post exists.
	 */
	private function has_similar_post( string $topic ): bool {
		$topic_keywords = $this->extract_meaningful_keywords( $topic );
		if ( count( $topic_keywords ) < 2 ) {
			// Topic too short to meaningfully deduplicate.
			return false;
		}

		// Cache the post title list within a single pipeline run to avoid
		// repeating the same query for every idea.
		static $existing_titles = null;
		if ( null === $existing_titles ) {
			$existing_titles = $this->fetch_existing_titles();
		}

		foreach ( $existing_titles as $existing_title ) {
			$title_keywords = $this->extract_meaningful_keywords( $existing_title );
			$overlap        = count( array_intersect( $topic_keywords, $title_keywords ) );
			$overlap_ratio  = $overlap / count( $topic_keywords );

			// 60% keyword overlap = too similar to publish again.
			if ( $overlap_ratio >= 0.6 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fetch titles of all existing generated posts (cached per run).
	 *
	 * @return string[]
	 */
	private function fetch_existing_titles(): array {
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'post_status'    => [ 'publish', 'draft', 'pending' ],
			'meta_key'       => '_prautoblogger_generated',
			'meta_value'     => '1',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$titles = [];
		foreach ( $query->posts as $post_id ) {
			$titles[] = get_the_title( $post_id );
		}
		return $titles;
	}

	/**
	 * Extract meaningful keywords from a text string.
	 *
	 * Strips stopwords and short filler words so dedup focuses on
	 * substantive terms. Returns lowercase unique keywords.
	 *
	 * @param string $text Input text (topic or title).
	 * @return string[] Unique lowercase keywords.
	 */
	private function extract_meaningful_keywords( string $text ): array {
		// Common stopwords that add no topical signal.
		static $stopwords = [
			'a', 'an', 'the', 'and', 'or', 'but', 'is', 'are', 'was', 'were',
			'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'as',
			'it', 'its', 'this', 'that', 'your', 'you', 'how', 'what', 'when',
			'why', 'where', 'which', 'who', 'do', 'does', 'did', 'not', 'no',
			'can', 'will', 'should', 'could', 'would', 'may', 'might', 'have',
			'has', 'had', 'be', 'been', 'being', 'about', 'into', 'through',
			'during', 'before', 'after', 'above', 'below', 'between', 'same',
			'up', 'down', 'out', 'off', 'over', 'under', 'again', 'then',
			'here', 'there', 'all', 'each', 'every', 'both', 'few', 'more',
			'most', 'other', 'some', 'such', 'than', 'too', 'very', 'just',
			'also', 'only', 'own', 'so', 'if', 'while', 'because', 'until',
			'vs', 'versus', 'guide', 'complete', 'ultimate', 'best', 'top',
			'new', 'first', 'need', 'know', 'everything',
		];

		$text  = strtolower( $text );
		// Preserve compound identifiers like "BPC-157" by treating hyphens
		// between alphanumerics as part of the word.
		$words = preg_split( '/[^a-z0-9-]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words = array_filter( $words, static function ( string $w ) use ( $stopwords ): bool {
			return strlen( $w ) >= 2 && ! in_array( $w, $stopwords, true );
		} );

		return array_values( array_unique( $words ) );
	}

	/**
	 * Map analysis type to a reader-friendly article type.
	 *
	 * @param string $analysis_type 'question', 'complaint', 'comparison'.
	 *
	 * @return string Article type label.
	 */
	private function map_type( string $analysis_type ): string {
		$map = [
			'question'   => 'guide',
			'complaint'  => 'solution',
			'comparison' => 'comparison',
			'news'       => 'news',
			'guide'      => 'guide',
		];
		return $map[ $analysis_type ] ?? 'article';
	}
}
