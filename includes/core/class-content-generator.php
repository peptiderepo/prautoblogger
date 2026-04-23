<?php
declare(strict_types=1);

/**
 * Writer agent — generates blog post content from article ideas.
 *
 * Supports: single_pass (one LLM call) or multi_step (outline → draft → polish).
 * Opik instrumentation: spans per LLM call when feature-flag enabled.
 *
 * Triggered by: PRAutoBlogger_Article_Worker.
 * Dependencies: LLM_Provider_Interface, Cost_Tracker, Content_Prompts.
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
	 * @param PRAutoBlogger_Article_Idea $idea The scored idea to generate content for.
	 * @return string Generated HTML content.
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

		return 'single_pass' === $mode
			? $this->generate_single_pass( $request )
			: $this->generate_multi_step( $request );
	}

	/**
	 * Single-pass: one LLM call produces complete article.
	 */
	private function generate_single_pass( PRAutoBlogger_Content_Request $request ): string {
		$model = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );
		$ctx = PRAutoBlogger_Opik_Trace_Context::current();

		$span_id = $ctx->start_span(
			array(
				'name'     => 'single_pass_generation',
				'type'     => 'llm',
				'model'    => $model,
				'provider' => 'openrouter',
			)
		);

		$response = $this->llm->send_chat_completion(
			array(
				array( 'role' => 'system', 'content' => PRAutoBlogger_Content_Prompts::build_system( $request ) ),
				array( 'role' => 'user', 'content' => PRAutoBlogger_Content_Prompts::build_single_pass( $request ) ),
			),
			$model,
			array( 'temperature' => 0.7, 'max_tokens' => 4000 )
		);

		$ctx->end_span(
			$span_id,
			array(
				'usage' => array(
					'prompt_tokens'     => $response['prompt_tokens'],
					'completion_tokens' => $response['completion_tokens'],
					'total_tokens'      => $response['prompt_tokens'] + $response['completion_tokens'],
				),
			)
		);

		$this->cost_tracker->log_api_call(
			null, 'draft', $this->llm->get_provider_name(),
			$response['model'], $response['prompt_tokens'], $response['completion_tokens']
		);

		return $response['content'];
	}

	/**
	 * Multi-step: outline → draft → polish.
	 */
	private function generate_multi_step( PRAutoBlogger_Content_Request $request ): string {
		$model = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );

		$outline  = $this->stage_outline( $request, $model );
		$draft    = $this->stage_draft( $request, $outline, $model );
		$polished = $this->stage_polish( $request, $draft, $model );

		return $polished;
	}

	/**
	 * Stage 1: Generate article outline.
	 */
	private function stage_outline( PRAutoBlogger_Content_Request $request, string $model ): string {
		$ctx = PRAutoBlogger_Opik_Trace_Context::current();

		$span_id = $ctx->start_span(
			array(
				'name'     => 'outline_generation',
				'type'     => 'llm',
				'model'    => $model,
				'provider' => 'openrouter',
			)
		);

		$response = $this->llm->send_chat_completion(
			array(
				array( 'role' => 'system', 'content' => PRAutoBlogger_Content_Prompts::build_system( $request ) ),
				array( 'role' => 'user', 'content' => PRAutoBlogger_Content_Prompts::build_outline( $request ) ),
			),
			$model,
			array( 'temperature' => 0.5, 'max_tokens' => 1500 )
		);

		$ctx->end_span(
			$span_id,
			array(
				'usage' => array(
					'prompt_tokens'     => $response['prompt_tokens'],
					'completion_tokens' => $response['completion_tokens'],
					'total_tokens'      => $response['prompt_tokens'] + $response['completion_tokens'],
				),
			)
		);

		$this->cost_tracker->log_api_call(
			null, 'outline', $this->llm->get_provider_name(),
			$response['model'], $response['prompt_tokens'], $response['completion_tokens']
		);

		return $response['content'];
	}

	/**
	 * Stage 2: Write full draft from outline.
	 */
	private function stage_draft( PRAutoBlogger_Content_Request $request, string $outline, string $model ): string {
		$ctx = PRAutoBlogger_Opik_Trace_Context::current();

		$span_id = $ctx->start_span(
			array(
				'name'     => 'draft_generation',
				'type'     => 'llm',
				'model'    => $model,
				'provider' => 'openrouter',
			)
		);

		$response = $this->llm->send_chat_completion(
			array(
				array( 'role' => 'system', 'content' => PRAutoBlogger_Content_Prompts::build_system( $request ) ),
				array( 'role' => 'user', 'content' => PRAutoBlogger_Content_Prompts::build_draft( $request, $outline ) ),
			),
			$model,
			array( 'temperature' => 0.7, 'max_tokens' => 4000 )
		);

		$ctx->end_span(
			$span_id,
			array(
				'usage' => array(
					'prompt_tokens'     => $response['prompt_tokens'],
					'completion_tokens' => $response['completion_tokens'],
					'total_tokens'      => $response['prompt_tokens'] + $response['completion_tokens'],
				),
			)
		);

		$this->cost_tracker->log_api_call(
			null, 'draft', $this->llm->get_provider_name(),
			$response['model'], $response['prompt_tokens'], $response['completion_tokens']
		);

		return $response['content'];
	}

	/**
	 * Stage 3: Polish and refine draft.
	 */
	private function stage_polish( PRAutoBlogger_Content_Request $request, string $draft, string $model ): string {
		$ctx = PRAutoBlogger_Opik_Trace_Context::current();

		$span_id = $ctx->start_span(
			array(
				'name'     => 'polish_generation',
				'type'     => 'llm',
				'model'    => $model,
				'provider' => 'openrouter',
			)
		);

		$response = $this->llm->send_chat_completion(
			array(
				array( 'role' => 'system', 'content' => PRAutoBlogger_Content_Prompts::build_system( $request ) ),
				array( 'role' => 'user', 'content' => PRAutoBlogger_Content_Prompts::build_polish( $draft ) ),
			),
			$model,
			array( 'temperature' => 0.4, 'max_tokens' => 4000 )
		);

		$ctx->end_span(
			$span_id,
			array(
				'usage' => array(
					'prompt_tokens'     => $response['prompt_tokens'],
					'completion_tokens' => $response['completion_tokens'],
					'total_tokens'      => $response['prompt_tokens'] + $response['completion_tokens'],
				),
			)
		);

		$this->cost_tracker->log_api_call(
			null, 'polish', $this->llm->get_provider_name(),
			$response['model'], $response['prompt_tokens'], $response['completion_tokens']
		);

		return $response['content'];
	}
}
