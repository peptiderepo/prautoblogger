<?php
declare(strict_types=1);

/**
 * Chief Editor agent — LLM-powered editorial review.
 *
 * Reviews generated articles for quality, accuracy, SEO, tone.
 * Approves, requests revisions, or rejects content.
 * Opik instrumentation: spans per review LLM call.
 *
 * Triggered by: PRAutoBlogger_Article_Worker.
 * Dependencies: LLM_Provider_Interface, Cost_Tracker.
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
	 * @param string                     $content The generated HTML content.
	 * @param PRAutoBlogger_Article_Idea $idea    The original article idea.
	 * @return PRAutoBlogger_Editorial_Review
	 */
	public function review( string $content, PRAutoBlogger_Article_Idea $idea ): PRAutoBlogger_Editorial_Review {
		$model = get_option( 'prautoblogger_editor_model', PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL );
		$niche = get_option( 'prautoblogger_niche_description', '' );

		$system_prompt = $this->build_system_prompt( $niche );
		$user_prompt   = $this->build_review_prompt( $content, $idea );

		$ctx = PRAutoBlogger_Opik_Trace_Context::current();
		$span_id = $ctx->start_span(
			array(
				'name'     => 'editorial_review',
				'type'     => 'llm',
				'model'    => $model,
				'provider' => 'openrouter',
			)
		);

		$response = $this->llm->send_chat_completion(
			array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			$model,
			array(
				'temperature'     => 0.3,
				'max_tokens'      => 5000,
				'response_format' => array( 'type' => 'json_object' ),
			)
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
	 * Build system prompt for editorial review.
	 */
	private function build_system_prompt( string $niche ): string {
		$prompt = 'You are a senior blog editor';
		if ( '' !== $niche ) {
			$prompt .= " specializing in {$niche} content";
		}
		$prompt .= ". Review article drafts before publication.\n\n";

		$prompt .= "Evaluate on: QUALITY, ACCURACY, SEO, COMPLETENESS, READABILITY.\n";
		$prompt .= "Respond with JSON: {\n";
		$prompt .= '  "verdict": "approved" | "revised" | "rejected",' . "\n";
		$prompt .= '  "quality_score": 0.0-1.0,' . "\n";
		$prompt .= '  "seo_score": 0.0-1.0,' . "\n";
		$prompt .= '  "issues": ["issue1", "issue2"],' . "\n";
		$prompt .= '  "notes": "Editorial notes",' . "\n";
		$prompt .= '  "revised_content": "Full revised HTML if revised, null otherwise"' . "\n";
		$prompt .= "}\n\n";

		$prompt .= "Rules:\n";
		$prompt .= "- APPROVE if quality_score >= 0.7 and seo_score >= 0.6\n";
		$prompt .= "- REVISE if fixable issues exist — provide full revised HTML\n";
		$prompt .= "- REJECT if fundamentally flawed\n";
		$prompt .= "- Preserve formatting, links, lists when revising\n";

		$instructions = trim( (string) get_option( 'prautoblogger_editor_instructions', '' ) );
		if ( '' !== $instructions ) {
			$prompt .= "\nAdditional instructions:\n" . $instructions . "\n";
		}

		return $prompt;
	}

	/**
	 * Build user prompt for editorial review.
	 */
	private function build_review_prompt( string $content, PRAutoBlogger_Article_Idea $idea ): string {
		return sprintf(
			"Review this article draft:\n\n" .
			"BRIEF: Title: %s | Topic: %s | Type: %s\n" .
			"KEY POINTS: %s\n" .
			"TARGET KEYWORDS: %s\n\n" .
			"CONTENT:\n%s",
			$idea->get_suggested_title(),
			$idea->get_topic(),
			$idea->get_article_type(),
			implode( ', ', $idea->get_key_points() ),
			implode( ', ', $idea->get_target_keywords() ),
			$content
		);
	}

	/**
	 * Parse LLM editorial review response.
	 */
	private function parse_review_response( string $content ): PRAutoBlogger_Editorial_Review {
		$data = PRAutoBlogger_Json_Extractor::decode( $content );

		if ( ! is_array( $data ) || ! isset( $data['verdict'] ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'Chief editor response unparseable (first 200 chars): ' . mb_substr( $content, 0, 200 ),
				'editor'
			);
			return new PRAutoBlogger_Editorial_Review(
				array(
					'verdict'       => 'rejected',
					'notes'         => 'Editor response unparseable',
					'quality_score' => 0.0,
					'seo_score'     => 0.0,
					'issues'        => array( 'Unparseable editor response' ),
				)
			);
		}

		$verdict = sanitize_text_field( $data['verdict'] ?? 'rejected' );
		if ( ! in_array( $verdict, array( 'approved', 'revised', 'rejected' ), true ) ) {
			$verdict = 'rejected';
		}

		return new PRAutoBlogger_Editorial_Review(
			array(
				'verdict'         => $verdict,
				'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ),
				'revised_content' => isset( $data['revised_content'] ) ? wp_kses_post( $data['revised_content'] ) : null,
				'quality_score'   => (float) ( $data['quality_score'] ?? 0.0 ),
				'seo_score'       => (float) ( $data['seo_score'] ?? 0.0 ),
				'issues'          => array_map( 'sanitize_text_field', $data['issues'] ?? array() ),
			)
		);
	}
}
