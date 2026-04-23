<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

/**
 * Value object representing the chief editor agent's review of generated content.
 *
 * Triggered by: Chief_Editor creates these; Publisher consumes them.
 * Dependencies: None — pure data object.
 *
 * @see core/class-chief-editor.php — Creates these after reviewing content.
 * @see core/class-publisher.php    — Uses verdict to decide publish vs. draft.
 */
class PRAutoBlogger_Editorial_Review {

	private string $verdict;
	private string $notes;
	private ?string $revised_content;
	private float $quality_score;
	private float $seo_score;
	private array $issues;

	/**
	 * @param array{
	 *     verdict: string,
	 *     notes: string,
	 *     revised_content?: string|null,
	 *     quality_score?: float,
	 *     seo_score?: float,
	 *     issues?: string[],
	 * } $data
	 */
	public function __construct( array $data ) {
		$this->verdict         = $data['verdict']; // 'approved', 'revised', 'rejected'
		$this->notes           = $data['notes'];
		$this->revised_content = $data['revised_content'] ?? null;
		$this->quality_score   = (float) ( $data['quality_score'] ?? 0.0 );
		$this->seo_score       = (float) ( $data['seo_score'] ?? 0.0 );
		$this->issues          = $data['issues'] ?? array();
	}

	public function get_verdict(): string {
		return $this->verdict;
	}

	public function get_notes(): string {
		return $this->notes;
	}

	public function get_revised_content(): ?string {
		return $this->revised_content;
	}

	public function get_quality_score(): float {
		return $this->quality_score;
	}

	public function get_seo_score(): float {
		return $this->seo_score;
	}

	public function get_issues(): array {
		return $this->issues;
	}
}
