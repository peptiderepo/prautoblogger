<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Writer agent — generates blog post content from article ideas.
 *
 * Supports two configurable pipeline modes:
 * - single_pass: One LLM call produces the complete article (cheaper, faster).
 * - multi_step: Outline → Draft → Polish pipeline (higher quality, more cost).
 *
 * Triggered by: PRAutoBlogger::run_generation_pipeline() (step 4).
 * Dependencies: LLM_Provider_Interface, Cost_Tracker, Content_Prompts.
 *
 * @see core/class-content-prompts.php      — Builds all LLM prompts used here.
 * @see core/class-chief-editor.php         — Reviews output of this class.
 * @see providers/interface-llm-provider.php — LLM used for generation.
 * @see ARCHITECTURE.md                     — Data flow step 4.
 */
class PRAutoBlogger_Content_Generator {

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
	 * Generate a blog post from an article idea.
	 *
	 * Reads pipeline mode from settings and dispatches to the appropriate method.
	 * Side effects: LLM API calls (1-3 depending on mode), cost logging.
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The scored idea to generate content for.
	 * @return string Generated HTML content.
	 * @throws \RuntimeException On generation failure.
	 */
	public function generate( PRAutoBlogger_Article_Idea $idea ): string {
		$mode = get_option( 'prautoblogger_writing_pipeline', 'multi_step' );

		$request = new PRAutoBlogger_Content_Request(
			$idea,
			$mode,
			get_option( 'prautoblogger_tone', 'informational' ),
			absint( get_option( 'prautoblogger_min_word_count', 800 ) ),
			absint( get_option( 'prautoblogger_max_word_count', 2000 ) ),
			get_option( 'prautoblogger_niche_description', '' ),
			json_decode( get_option( 'prautoblogger_topic_exclusions', '[]' ), true ) ?? array(),
			get_option( 'prautoblogger_writing_instructions', '' )
		);

		if ( 'single_pass' === $mode ) {
			return $this->generate_single_pass( $request );
		}

		return $this->generate_multi_step( $request );
	}

	/**
	 * Single-pass generation: one LLM call produces the complete article.
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @return string Generated HTML content.
	 */
	private function generate_single_pass( PRAutoBlogger_Content_Request $request ): string {
		$model = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );

		$response = $this->llm->send_chat_completion(
			array(
				array(
					'role'    => 'system',
					'content' => PRAutoBlogger_Content_Prompts::build_system( $request ),
				),
				array(
					'role'    => 'user',
					'content' => PRAutoBlogger_Content_Prompts::build_single_pass( $request ),
				),
			),
			$model,
			array(
				'temperature' => 0.7,
				'max_tokens'  => 4000,
			)
		);

		$this->cost_tracker->log_api_call(
			null,
			'draft',
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		return $response['content'];
	}

	/** Multi-step generation: Outline → Draft → Polish. */
	private function generate_multi_step( PRAutoBlogger_Content_Request $request ): string {
		$model = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );

		$outline  = $this->stage_outline( $request, $model );
		$draft    = $this->stage_draft( $request, $outline, $model );
		$polished = $this->stage_polish( $request, $draft, $model );

		return $polished;
	}

	/** Multi-step stage 1: Generate an article outline. */
	private function stage_outline( PRAutoBlogger_Content_Request $request, string $model ): string {
		$system = PRAutoBlogger_Content_Prompts::build_system( $request );

		$response = $this->llm->send_chat_completion(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => PRAutoBlogger_Content_Prompts::build_outline( $request ),
				),
			),
			$model,
			array(
				'temperature' => 0.5,
				'max_tokens'  => 1500,
			)
		);

		$this->cost_tracker->log_api_call(
			null,
			'outline',
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		return $response['content'];
	}

	/** Multi-step stage 2: Write the full draft from the outline. */
	private function stage_draft( PRAutoBlogger_Content_Request $request, string $outline, string $model ): string {
		$system = PRAutoBlogger_Content_Prompts::build_system( $request );

		$response = $this->llm->send_chat_completion(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => PRAutoBlogger_Content_Prompts::build_draft( $request, $outline ),
				),
			),
			$model,
			array(
				'temperature' => 0.7,
				'max_tokens'  => 4000,
			)
		);

		$this->cost_tracker->log_api_call(
			null,
			'draft',
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		return $response['content'];
	}

	/** Multi-step stage 3: Polish and refine the draft. */
	private function stage_polish( PRAutoBlogger_Content_Request $request, string $draft, string $model ): string {
		$system = PRAutoBlogger_Content_Prompts::build_system( $request );

		$response = $this->llm->send_chat_completion(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => PRAutoBlogger_Content_Prompts::build_polish( $draft ),
				),
			),
			$model,
			array(
				'temperature' => 0.4,
				'max_tokens'  => 4000,
			)
		);

		$this->cost_tracker->log_api_call(
			null,
			'polish',
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		return $response['content'];
	}
}
