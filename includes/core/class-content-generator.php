<?php
declare(strict_types=1);

/**
 * Writer agent — generates blog post content from article ideas.
 *
 * Supports two configurable pipeline modes:
 * - single_pass: One LLM call produces the complete article (cheaper, faster).
 * - multi_step: Outline → Draft → Polish pipeline (higher quality, more cost).
 *
 * Triggered by: PRAutoBlogger::run_generation_pipeline() (step 4).
 * Dependencies: LLM_Provider_Interface, Cost_Tracker.
 *
 * @see core/class-chief-editor.php     — Reviews output of this class.
 * @see providers/interface-llm-provider.php — LLM used for generation.
 * @see ARCHITECTURE.md                 — Data flow step 4.
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
	 *
	 * Side effects: LLM API calls (1-3 depending on mode), cost logging.
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The scored idea to generate content for.
	 *
	 * @return string Generated HTML content.
	 *
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
			json_decode( get_option( 'prautoblogger_topic_exclusions', '[]' ), true ) ?: []
		);

		if ( 'single_pass' === $mode ) {
			return $this->generate_single_pass( $request );
		}

		return $this->generate_multi_step( $request );
	}

	/**
	 * Single-pass generation: one LLM call produces the complete article.
	 *
	 * @param PRAutoBlogger_Content_Request $request
	 *
	 * @return string Generated HTML content.
	 */
	private function generate_single_pass( PRAutoBlogger_Content_Request $request ): string {
		$model    = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );
		$idea     = $request->get_idea();

		$system = $this->build_writer_system_prompt( $request );
		$user   = $this->build_single_pass_prompt( $request );

		$response = $this->llm->send_chat_completion(
			[
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => $user ],
			],
			$model,
			[
				'temperature' => 0.7,
				'max_tokens'  => 4000,
			]
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

	/**
	 * Multi-step generation: Outline → Draft → Polish.
	 *
	 * @param PRAutoBlogger_Content_Request $request
	 *
	 * @return string Generated HTML content.
	 */
	private function generate_multi_step( PRAutoBlogger_Content_Request $request ): string {
		$model = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );

		// Step 1: Generate outline.
		$outline = $this->stage_outline( $request, $model );

		// Step 2: Generate draft from outline.
		$draft = $this->stage_draft( $request, $outline, $model );

		// Step 3: Polish the draft.
		$polished = $this->stage_polish( $request, $draft, $model );

		return $polished;
	}

	/**
	 * Multi-step stage 1: Generate an article outline.
	 *
	 * @param PRAutoBlogger_Content_Request $request
	 * @param string                      $model
	 *
	 * @return string The outline text.
	 */
	private function stage_outline( PRAutoBlogger_Content_Request $request, string $model ): string {
		$idea = $request->get_idea();

		$prompt = sprintf(
			"Create a detailed outline for a blog post titled: \"%s\"\n\n" .
			"Topic: %s\nArticle type: %s\n\nKey points to cover:\n%s\n\n" .
			"Target keywords: %s\n\n" .
			"The outline should have 4-6 main sections with bullet points under each. " .
			"Include an introduction hook and a conclusion with a call to action. " .
			"Word count target: %d-%d words.",
			$idea->get_suggested_title(),
			$idea->get_topic(),
			$idea->get_article_type(),
			implode( "\n- ", $idea->get_key_points() ),
			implode( ', ', $idea->get_target_keywords() ),
			$request->get_min_word_count(),
			$request->get_max_word_count()
		);

		$response = $this->llm->send_chat_completion(
			[
				[ 'role' => 'system', 'content' => $this->build_writer_system_prompt( $request ) ],
				[ 'role' => 'user', 'content' => $prompt ],
			],
			$model,
			[ 'temperature' => 0.5, 'max_tokens' => 1500 ]
		);

		$this->cost_tracker->log_api_call(
			null, 'outline', $this->llm->get_provider_name(),
			$response['model'], $response['prompt_tokens'], $response['completion_tokens']
		);

		return $response['content'];
	}

	/**
	 * Multi-step stage 2: Write the full draft from the outline.
	 *
	 * @param PRAutoBlogger_Content_Request $request
	 * @param string                      $outline
	 * @param string                      $model
	 *
	 * @return string Draft HTML content.
	 */
	private function stage_draft( PRAutoBlogger_Content_Request $request, string $outline, string $model ): string {
		$prompt = sprintf(
			"Using this outline, write the full blog post in HTML format.\n\n" .
			"OUTLINE:\n%s\n\n" .
			"Requirements:\n" .
			"- Write in a %s tone\n" .
			"- Target %d-%d words\n" .
			"- Use proper HTML headings (h2, h3), paragraphs, and lists\n" .
			"- Include an engaging introduction and strong conclusion\n" .
			"- Naturally incorporate these keywords: %s\n" .
			"- Do NOT include the title in the HTML (it will be set separately)\n" .
			"- Do NOT wrap in <html>, <head>, or <body> tags — just the article content",
			$outline,
			$request->get_tone(),
			$request->get_min_word_count(),
			$request->get_max_word_count(),
			implode( ', ', $request->get_idea()->get_target_keywords() )
		);

		$response = $this->llm->send_chat_completion(
			[
				[ 'role' => 'system', 'content' => $this->build_writer_system_prompt( $request ) ],
				[ 'role' => 'user', 'content' => $prompt ],
			],
			$model,
			[ 'temperature' => 0.7, 'max_tokens' => 4000 ]
		);

		$this->cost_tracker->log_api_call(
			null, 'draft', $this->llm->get_provider_name(),
			$response['model'], $response['prompt_tokens'], $response['completion_tokens']
		);

		return $response['content'];
	}

	/**
	 * Multi-step stage 3: Polish and refine the draft.
	 *
	 * @param PRAutoBlogger_Content_Request $request
	 * @param string                      $draft
	 * @param string                      $model
	 *
	 * @return string Polished HTML content.
	 */
	private function stage_polish( PRAutoBlogger_Content_Request $request, string $draft, string $model ): string {
		$prompt = "Review and polish this blog post draft. Improve:\n" .
			"1. Flow and readability\n" .
			"2. SEO optimization (headings, keyword placement)\n" .
			"3. Engagement (hooks, transitions, call-to-action)\n" .
			"4. Accuracy and clarity\n" .
			"5. Remove any filler or redundant sentences\n\n" .
			"Return the polished HTML content only. Do not add commentary.\n\n" .
			"DRAFT:\n" . $draft;

		$response = $this->llm->send_chat_completion(
			[
				[ 'role' => 'system', 'content' => $this->build_writer_system_prompt( $request ) ],
				[ 'role' => 'user', 'content' => $prompt ],
			],
			$model,
			[ 'temperature' => 0.4, 'max_tokens' => 4000 ]
		);

		$this->cost_tracker->log_api_call(
			null, 'polish', $this->llm->get_provider_name(),
			$response['model'], $response['prompt_tokens'], $response['completion_tokens']
		);

		return $response['content'];
	}

	/**
	 * Build the system prompt shared across all writing stages.
	 *
	 * @param PRAutoBlogger_Content_Request $request
	 *
	 * @return string
	 */
	private function build_writer_system_prompt( PRAutoBlogger_Content_Request $request ): string {
		$niche = $request->get_niche_description();
		$prompt = "You are an expert blog writer";
		if ( '' !== $niche ) {
			$prompt .= " specializing in {$niche}";
		}
		$prompt .= ". Write well-researched, engaging, SEO-friendly content. ";
		$prompt .= "Use a {$request->get_tone()} tone. ";
		$prompt .= "Output HTML content only — no markdown, no code fences, no commentary.";
		return $prompt;
	}

	/**
	 * Build the user prompt for single-pass generation.
	 *
	 * @param PRAutoBlogger_Content_Request $request
	 *
	 * @return string
	 */
	private function build_single_pass_prompt( PRAutoBlogger_Content_Request $request ): string {
		$idea = $request->get_idea();

		return sprintf(
			"Write a complete blog post in HTML format.\n\n" .
			"Title: %s\nTopic: %s\nType: %s\n\nKey points:\n- %s\n\n" .
			"Keywords: %s\n\n" .
			"Requirements:\n" .
			"- %d-%d words\n" .
			"- Proper HTML (h2, h3, p, ul/li)\n" .
			"- Engaging intro, strong conclusion with CTA\n" .
			"- Do NOT include the title or <html>/<body> tags\n" .
			"- Output HTML only, no markdown or commentary",
			$idea->get_suggested_title(),
			$idea->get_topic(),
			$idea->get_article_type(),
			implode( "\n- ", $idea->get_key_points() ),
			implode( ', ', $idea->get_target_keywords() ),
			$request->get_min_word_count(),
			$request->get_max_word_count()
		);
	}
}
