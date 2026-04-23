<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

/**
 * Value object representing a single piece of collected social media data
 * (e.g., a Reddit post or comment).
 *
 * Triggered by: Source providers create these; Content_Analyzer consumes them.
 * Dependencies: None — pure data object.
 *
 * @see providers/interface-source-provider.php — Providers return arrays of these.
 * @see core/class-content-analyzer.php         — Consumes these for pattern detection.
 */
class PRAutoBlogger_Source_Data {

	private int $id;
	private string $source_type;
	private string $source_id;
	private ?string $subreddit;
	private ?string $title;
	private ?string $content;
	private ?string $author;
	private int $score;
	private int $comment_count;
	private ?string $permalink;
	private string $collected_at;
	private ?array $metadata;

	/**
	 * @param array{
	 *     id?: int,
	 *     source_type: string,
	 *     source_id: string,
	 *     subreddit?: string|null,
	 *     title?: string|null,
	 *     content?: string|null,
	 *     author?: string|null,
	 *     score?: int,
	 *     comment_count?: int,
	 *     permalink?: string|null,
	 *     collected_at?: string,
	 *     metadata?: array|null,
	 * } $data
	 */
	public function __construct( array $data ) {
		$this->id            = (int) ( $data['id'] ?? 0 );
		$this->source_type   = $data['source_type'];
		$this->source_id     = $data['source_id'];
		$this->subreddit     = $data['subreddit'] ?? null;
		$this->title         = $data['title'] ?? null;
		$this->content       = $data['content'] ?? null;
		$this->author        = $data['author'] ?? null;
		$this->score         = (int) ( $data['score'] ?? 0 );
		$this->comment_count = (int) ( $data['comment_count'] ?? 0 );
		$this->permalink     = $data['permalink'] ?? null;
		$this->collected_at  = $data['collected_at'] ?? current_time( 'mysql' );
		$this->metadata      = $data['metadata'] ?? null;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_source_type(): string {
		return $this->source_type;
	}

	public function get_source_id(): string {
		return $this->source_id;
	}

	public function get_subreddit(): ?string {
		return $this->subreddit;
	}

	public function get_title(): ?string {
		return $this->title;
	}

	public function get_content(): ?string {
		return $this->content;
	}

	public function get_author(): ?string {
		return $this->author;
	}

	public function get_score(): int {
		return $this->score;
	}

	public function get_comment_count(): int {
		return $this->comment_count;
	}

	public function get_permalink(): ?string {
		return $this->permalink;
	}

	public function get_collected_at(): string {
		return $this->collected_at;
	}

	public function get_metadata(): ?array {
		return $this->metadata;
	}

	/**
	 * Convert to array for database insertion.
	 *
	 * @return array<string, mixed>
	 */
	public function to_db_row(): array {
		return array(
			'source_type'   => $this->source_type,
			'source_id'     => $this->source_id,
			'subreddit'     => $this->subreddit,
			'title'         => $this->title,
			'content'       => $this->content,
			'author'        => $this->author,
			'score'         => $this->score,
			'comment_count' => $this->comment_count,
			'permalink'     => $this->permalink,
			'collected_at'  => $this->collected_at,
			'metadata_json' => null !== $this->metadata ? wp_json_encode( $this->metadata ) : null,
		);
	}
}
