<?php
declare(strict_types=1);

/**
 * Chief Editor agent — LLM-powered editorial review of generated content.
 *
 * Reviews articles for quality, accuracy, SEO, and tone before publishing.
 * Can approve, request revisions (and produce them), or reject content.
 * Rejected content is saved as a draft for human review.
 *
 * Triggered by: PRAutoBlogger::run_generation_pipeline() (step 5).
 * Dependencies: LLM_Provider_Interface, Cost_Tracker.
 *
 * @see core/class-content-generator.php — Produces the content we review.
 * @see core/class-json-extractor.php    — Tolerant JSON parsing for LLM output.
 * @see core/class-publisher.php         — Publishes approved content.
 * @see ARCHITECTURE.md                  — Data flow step 5.
 */
class PRAutoBlogger_Chief_Editor {

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
	 * Review generated content and return an editorial verdict.
	 *
	 * The editor evaluates: content quality, factual tone, SEO elements,
	 * readability, and alignment with the article idea. It can approve,
	 * revise (and return revised content), or reject.
	 *
	 * Side effects: LLM API call(s), cost logging.
	 *
	 * @param string                     $content The generated HTML content.
	 * @param PRAutoBlogger_Article_Idea   $idea    The original article idea.
	 *
	 * @return PRAutoBlogger_Editorial_Review
	 */
	public function review( string $content, PRAutoBlogger_Article_Idea $idea ): PRAutoBlogger_Editorial_Review {
		$model = get_option( 'prautoblogger_editor_model', PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL );
		$niche = get_option( 'prautoblogger_niche_description', '' );

		$system_prompt = $this->build_system_prompt( $niche );
		$user_prompt   = $this->build_review_prompt( $content, $idea );

		$response = $this->llm->send_chat_completion(
			[
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user', 'content' => $user_prompt ],
			],
			$model,
			[
				'temperature'     => 0.3,
				'max_tokens'      => 5000,
				'response_format' => [ 'type' => 'json_object' ],
			]
		);

		$this->cost_tracker->log_api_call(
			null,
			'review',
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		return $this->parse_review_response( $response['content'] );
	}

	/**
	 * @param string $niche
	 *
	 * @return string
	 */
	private function build_system_prompt( string $niche ): string {
		$prompt = "You are a senior blog editor";
		if ( '' !== $niche ) {
			$prompt .= " specializing in {$niche} content";
		}
		$prompt .= ". Your job is to review article drafts before publication.\n\n";

		$prompt .= "Evaluate the article on:\n";
		$prompt .= "1. QUALITY: Is the writing clear, engaging, and well-structured?\n";
		$prompt .= "2. ACCURACY: Does the content avoid making unsupported health/medical claims? Is the tone appropriately cautious for the topic?\n";
		$prompt .= "3. SEO: Are headings properly structured? Are target keywords naturally used?\n";
		$prompt .= "4. COMPLETENESS: Does it cover the key points? Does it have a proper intro and conclusion?\n";
		$prompt .= "5. READABILITY: Is sentence variety good? Are paragraphs appropriate length?\n\n";

		$prompt .= "You must respond with JSON in this format:\n";
		$prompt .= "{\n";
		$prompt .= '  "verdict": "approved" | "revised" | "rejected",' . "\n";
		$prompt .= '  "quality_score": 0.0-1.0,' . "\n";
		$prompt .= '  "seo_score": 0.0-1.0,' . "\n";
		$prompt .= '  "issues": ["issue 1", "issue 2"],' . "\n";
		$prompt .= '  "notes": "Brief editorial notes",' . "\n";
		$prompt .= '  "revised_content": "Full revised HTML if verdict is revised, null otherwise"' . "\n";
		$prompt .= "}\n\n";

		$prompt .= "Rules:\n";
		$prompt .= "- APPROVE if quality_score >= 0.7 and seo_score >= 0.6 and no critical issues\n";
		$prompt .= "- REVISE if fixable issues exist — you MUST provide the full revised HTML\n";
		$prompt .= "- REJECT only if the content is fundamentally flawed or off-topic\n";
		$prompt .= "- When revising, fix the issues yourself. Don't just describe them.\n";
		$prompt .= "- When revising, PRESERVE all bullet points, numbered lists, hyperlinks, and structural formatting from the original. Do NOT flatten lists into prose or remove links.\n";

		// Append user-defined editor instructions if configured.
		$instructions = trim( (string) get_option( 'prautoblogger_editor_instructions', '' ) );
		if ( '' !== $instructions ) {
			$prompt .= "\nAdditional instructions:\n" . $instructions . "\n";
		}

		return $prompt;
	}

	/**
	 * @param string                     $content
	 * @param PRAutoBlogger_Article_Idea   $idea
	 *
	 * @return string
	 */
	private function build_review_prompt( string $content, PRAutoBlogger_Article_Idea $idea ): string {
		return sprintf(
			"Review this article draft:\n\n" .
			"ORIGINAL BRIEF:\n" .
			"- Title: %s\n" .
			"- Topic: %s\n" .
			"- Type: %s\n" .
			"- Key points: %s\n" .
			"- Target keywords: %s\n\n" .
			"ARTICLE CONTENT:\n%s",
			$idea->get_suggested_title(),
			$idea->get_topic(),
			$idea->get_article_type(),
			implode( ', ', $idea->get_key_points() ),
			implode( ', ', $idea->get_target_keywords() ),
			$content
		);
	}

	/**
	 * Parse the LLM's editorial review response.
	 *
	 * @param string $content JSON response from LLM.
	 *
	 * @return PRAutoBlogger_Editorial_Review
	 */
	private function parse_review_response( string $content ): PRAutoBlogger_Editorial_Review {
		$data = PRAutoBlogger_Json_Extractor::decode( $content );

		if ( ! is_array( $data ) || ! isset( $data['verdict'] ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'Chief editor response was not valid JSON. Defaulting to rejected. Raw (first 500 chars): '
				. mb_substr( $content, 0, 500 ),
				'editor'
			);
			return new PRAutoBlogger_Editorial_Review( [
				'verdict'       => 'rejected',
				'notes'         => 'Editor response could not be parsed.',
				'quality_score' => 0.0,
				'seo_score'     => 0.0,
				'issues'        => [ 'Unparseable editor response' ],
			] );
		}

		$verdict = sanitize_text_field( $data['verdict'] ?? 'rejected' );
		if ( ! in_array( $verdict, [ 'approved', 'revised', 'rejected' ], true ) ) {
			$verdict = 'rejected';
		}

		return new PRAutoBlogger_Editorial_Review( [
			'verdict'          => $verdict,
			'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
			'revised_content'  => isset( $data['revised_content'] ) ? wp_kses_post( $data['revised_content'] ) : null,
			'quality_score'    => (float) ( $data['quality_score'] ?? 0.0 ),
			'seo_score'        => (float) ( $data['seo_score'] ?? 0.0 ),
			'issues'           => array_map( 'sanitize_text_field', $data['issues'] ?? [] ),
		] );
	}
}
