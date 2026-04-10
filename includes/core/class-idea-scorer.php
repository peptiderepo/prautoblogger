<?php
declare(strict_types=1);

/**
 * Ranks and deduplicates article ideas from analysis results.
 *
 * Compares against existing published posts to avoid topic overlap,
 * scores ideas by relevance, frequency, and freshness, then returns
 * the top N ideas matching the daily target.
 *
 * Triggered by: Autoblogger::run_generation_pipeline() (step 3).
 * Dependencies: WordPress $wpdb, WP_Query.
 *
 * @see core/class-content-analyzer.php  — Produces the analysis results we consume.
 * @see core/class-content-generator.php — Consumes the ranked ideas we produce.
 * @see ARCHITECTURE.md                  — Data flow step 3.
 */
class Autoblogger_Idea_Scorer {

	/**
	 * Score, deduplicate, and rank article ideas.
	 *
	 * @param Autoblogger_Analysis_Result[] $analysis_results Raw analysis output.
	 * @param int                           $target_count     Number of ideas to return.
	 *
	 * @return Autoblogger_Article_Idea[] Top-ranked ideas, sorted by score descending.
	 */
	public function score_and_rank( array $analysis_results, int $target_count ): array {
		$exclusions = json_decode( get_option( 'autoblogger_topic_exclusions', '[]' ), true ) ?: [];
		$ideas      = [];

		foreach ( $analysis_results as $result ) {
			// Skip excluded topics.
			if ( $this->is_excluded( $result->get_topic(), $exclusions ) ) {
				continue;
			}

			// Skip if we've already published something very similar.
			if ( $this->has_similar_post( $result->get_topic() ) ) {
				continue;
			}

			$metadata = $result->get_metadata() ?? [];

			$ideas[] = new Autoblogger_Article_Idea( [
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

		// Sort by score descending.
		usort( $ideas, static function ( Autoblogger_Article_Idea $a, Autoblogger_Article_Idea $b ): int {
			return $b->get_score() <=> $a->get_score();
		} );

		return array_slice( $ideas, 0, $target_count );
	}

	/**
	 * Compute a composite score for an analysis result.
	 *
	 * Factors: LLM relevance score (50%), frequency (30%), recency bonus (20%).
	 *
	 * @param Autoblogger_Analysis_Result $result
	 *
	 * @return float Score between 0 and 1.
	 */
	private function compute_score( Autoblogger_Analysis_Result $result ): float {
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
	 * Uses a simple keyword overlap check against existing published post titles.
	 *
	 * @param string $topic Topic to check.
	 *
	 * @return bool True if a similar post exists.
	 */
	private function has_similar_post( string $topic ): bool {
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'post_status'    => [ 'publish', 'draft', 'pending' ],
			'meta_key'       => '_autoblogger_generated',
			'meta_value'     => '1',
			's'              => $topic,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );

		return $query->found_posts > 0;
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
		];
		return $map[ $analysis_type ] ?? 'article';
	}
}
