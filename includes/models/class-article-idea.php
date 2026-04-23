<?php
declare(strict_types=1);

/**
 * Value object representing a scored article idea ready for content generation.
 *
 * Created by the Idea_Scorer from Analysis_Results. Consumed by Content_Generator.
 *
 * Triggered by: Idea_Scorer creates these; Content_Generator and Chief_Editor consume them.
 * Dependencies: None — pure data object.
 *
 * @see core/class-idea-scorer.php      — Creates and ranks these.
 * @see core/class-content-generator.php — Uses these as generation input.
 */
class PRAutoBlogger_Article_Idea {

	private string $topic;
	private string $article_type;
	private string $suggested_title;
	private string $summary;
	private float $score;
	private int $analysis_id;
	private array $source_ids;
	private array $key_points;
	private array $target_keywords;

	/**
	 * @param array{
	 *     topic: string,
	 *     article_type: string,
	 *     suggested_title: string,
	 *     summary: string,
	 *     score?: float,
	 *     analysis_id?: int,
	 *     source_ids?: int[],
	 *     key_points?: string[],
	 *     target_keywords?: string[],
	 * } $data
	 */
	public function __construct( array $data ) {
		$this->topic           = $data['topic'];
		$this->article_type    = $data['article_type'];
		$this->suggested_title = $data['suggested_title'];
		$this->summary         = $data['summary'];
		$this->score           = (float) ( $data['score'] ?? 0.0 );
		$this->analysis_id     = (int) ( $data['analysis_id'] ?? 0 );
		$this->source_ids      = $data['source_ids'] ?? array();
		$this->key_points      = $data['key_points'] ?? array();
		$this->target_keywords = $data['target_keywords'] ?? array();
	}

	public function get_topic(): string {
		return $this->topic;
	}

	public function get_article_type(): string {
		return $this->article_type;
	}

	public function get_suggested_title(): string {
		return $this->suggested_title;
	}

	public function get_summary(): string {
		return $this->summary;
	}

	public function get_score(): float {
		return $this->score;
	}

	public function get_analysis_id(): int {
		return $this->analysis_id;
	}

	public function get_source_ids(): array {
		return $this->source_ids;
	}

	public function get_key_points(): array {
		return $this->key_points;
	}

	public function get_target_keywords(): array {
		return $this->target_keywords;
	}

	/**
	 * Serialize to array for storage in options/transients.
	 *
	 * Used by the pipeline queue to persist ideas across chained cron jobs.
	 *
	 * @return array<string, mixed> Constructor-compatible array.
	 */
	public function to_array(): array {
		return array(
			'topic'           => $this->topic,
			'article_type'    => $this->article_type,
			'suggested_title' => $this->suggested_title,
			'summary'         => $this->summary,
			'score'           => $this->score,
			'analysis_id'     => $this->analysis_id,
			'source_ids'      => $this->source_ids,
			'key_points'      => $this->key_points,
			'target_keywords' => $this->target_keywords,
		);
	}
}
