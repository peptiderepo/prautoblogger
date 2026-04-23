<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

/**
 * Value object for a post's composite performance score.
 *
 * Triggered by: Metrics_Collector creates these; Content_Analyzer reads them for self-improvement.
 * Dependencies: None — pure data object.
 *
 * @see core/class-metrics-collector.php — Creates these from WP + GA4 data.
 * @see core/class-content-analyzer.php  — Reads past scores to refine analysis.
 */
class PRAutoBlogger_Content_Score {

	private int $id;
	private int $post_id;
	private int $pageviews;
	private float $avg_time_on_page;
	private float $bounce_rate;
	private int $comment_count;
	private float $composite_score;
	private ?array $score_factors;
	private string $measured_at;

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct( array $data ) {
		$this->id               = (int) ( $data['id'] ?? 0 );
		$this->post_id          = (int) ( $data['post_id'] ?? 0 );
		$this->pageviews        = (int) ( $data['pageviews'] ?? 0 );
		$this->avg_time_on_page = (float) ( $data['avg_time_on_page'] ?? 0.0 );
		$this->bounce_rate      = (float) ( $data['bounce_rate'] ?? 0.0 );
		$this->comment_count    = (int) ( $data['comment_count'] ?? 0 );
		$this->composite_score  = (float) ( $data['composite_score'] ?? 0.0 );
		$this->score_factors    = $data['score_factors'] ?? null;
		$this->measured_at      = $data['measured_at'] ?? current_time( 'mysql' );
	}

	public function get_id(): int {
		return $this->id; }
	public function get_post_id(): int {
		return $this->post_id; }
	public function get_pageviews(): int {
		return $this->pageviews; }
	public function get_avg_time_on_page(): float {
		return $this->avg_time_on_page; }
	public function get_bounce_rate(): float {
		return $this->bounce_rate; }
	public function get_comment_count(): int {
		return $this->comment_count; }
	public function get_composite_score(): float {
		return $this->composite_score; }
	public function get_score_factors(): ?array {
		return $this->score_factors; }
	public function get_measured_at(): string {
		return $this->measured_at; }

	/**
	 * @return array<string, mixed>
	 */
	public function to_db_row(): array {
		return array(
			'post_id'            => $this->post_id,
			'pageviews'          => $this->pageviews,
			'avg_time_on_page'   => $this->avg_time_on_page,
			'bounce_rate'        => $this->bounce_rate,
			'comment_count'      => $this->comment_count,
			'composite_score'    => $this->composite_score,
			'score_factors_json' => null !== $this->score_factors ? wp_json_encode( $this->score_factors ) : null,
			'measured_at'        => $this->measured_at,
		);
	}
}
