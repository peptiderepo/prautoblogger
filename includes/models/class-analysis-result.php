<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Value object representing an analyzed topic pattern (recurring question,
 * complaint, or comparison detected in source data).
 *
 * Triggered by: Content_Analyzer creates these; Idea_Scorer consumes them.
 * Dependencies: None — pure data object.
 *
 * @see core/class-content-analyzer.php — Creates these from source data + LLM analysis.
 * @see core/class-idea-scorer.php      — Ranks these into article ideas.
 */
class PRAutoBlogger_Analysis_Result {

	private int $id;
	private string $analysis_type;
	private string $topic;
	private ?string $summary;
	private int $frequency;
	private float $relevance_score;
	private array $source_ids;
	private string $analyzed_at;
	private ?array $metadata;

	/**
	 * @param array{
	 *     id?: int,
	 *     analysis_type: string,
	 *     topic: string,
	 *     summary?: string|null,
	 *     frequency?: int,
	 *     relevance_score?: float,
	 *     source_ids?: int[],
	 *     analyzed_at?: string,
	 *     metadata?: array|null,
	 * } $data
	 */
	public function __construct( array $data ) {
		$this->id              = (int) ( $data['id'] ?? 0 );
		$this->analysis_type   = $data['analysis_type'];
		$this->topic           = $data['topic'];
		$this->summary         = $data['summary'] ?? null;
		$this->frequency       = (int) ( $data['frequency'] ?? 1 );
		$this->relevance_score = (float) ( $data['relevance_score'] ?? 0.0 );
		$this->source_ids      = $data['source_ids'] ?? array();
		$this->analyzed_at     = $data['analyzed_at'] ?? current_time( 'mysql' );
		$this->metadata        = $data['metadata'] ?? null;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_analysis_type(): string {
		return $this->analysis_type;
	}

	public function get_topic(): string {
		return $this->topic;
	}

	public function get_summary(): ?string {
		return $this->summary;
	}

	public function get_frequency(): int {
		return $this->frequency;
	}

	public function get_relevance_score(): float {
		return $this->relevance_score;
	}

	public function get_source_ids(): array {
		return $this->source_ids;
	}

	public function get_analyzed_at(): string {
		return $this->analyzed_at;
	}

	public function get_metadata(): ?array {
		return $this->metadata;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_db_row(): array {
		return array(
			'analysis_type'   => $this->analysis_type,
			'topic'           => $this->topic,
			'summary'         => $this->summary,
			'frequency'       => $this->frequency,
			'relevance_score' => $this->relevance_score,
			'source_ids_json' => wp_json_encode( $this->source_ids ),
			'analyzed_at'     => $this->analyzed_at,
			'metadata_json'   => null !== $this->metadata ? wp_json_encode( $this->metadata ) : null,
		);
	}
}
