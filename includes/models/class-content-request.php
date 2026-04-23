<?php
declare(strict_types=1);

/**
 * Value object encapsulating all parameters for a content generation request.
 *
 * Bundles the article idea with user-configured generation preferences
 * (tone, word count, pipeline mode) into a single object passed through the pipeline.
 *
 * Triggered by: Content_Generator creates these from Article_Ideas + settings.
 * Dependencies: None — pure data object.
 *
 * @see core/class-content-generator.php — Creates and consumes these.
 */
class PRAutoBlogger_Content_Request {

	private PRAutoBlogger_Article_Idea $idea;
	private string $pipeline_mode;
	private string $tone;
	private int $min_word_count;
	private int $max_word_count;
	private string $niche_description;
	private array $topic_exclusions;
	private string $writing_instructions;

	public function __construct(
		PRAutoBlogger_Article_Idea $idea,
		string $pipeline_mode,
		string $tone,
		int $min_word_count,
		int $max_word_count,
		string $niche_description,
		array $topic_exclusions = array(),
		string $writing_instructions = ''
	) {
		$this->idea                 = $idea;
		$this->pipeline_mode        = $pipeline_mode;
		$this->tone                 = $tone;
		$this->min_word_count       = $min_word_count;
		$this->max_word_count       = $max_word_count;
		$this->niche_description    = $niche_description;
		$this->topic_exclusions     = $topic_exclusions;
		$this->writing_instructions = $writing_instructions;
	}

	public function get_idea(): PRAutoBlogger_Article_Idea {
		return $this->idea;
	}

	public function get_pipeline_mode(): string {
		return $this->pipeline_mode;
	}

	public function get_tone(): string {
		return $this->tone;
	}

	public function get_min_word_count(): int {
		return $this->min_word_count;
	}

	public function get_max_word_count(): int {
		return $this->max_word_count;
	}

	public function get_niche_description(): string {
		return $this->niche_description;
	}

	public function get_topic_exclusions(): array {
		return $this->topic_exclusions;
	}

	public function get_writing_instructions(): string {
		return $this->writing_instructions;
	}
}
