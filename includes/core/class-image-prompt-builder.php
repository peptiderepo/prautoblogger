<?php
declare(strict_types=1);

/**
 * Builds image prompts from article content or source data.
 *
 * Uses a cheap LLM (via OpenRouter) to distill article content into concise
 * visual scene descriptions optimised for FLUX.1. Falls back to rule-based
 * synthesis if the LLM call fails, so image generation never blocks on a
 * prompt-rewriting outage.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline during content generation run.
 * Dependencies: PRAutoBlogger_OpenRouter_Provider (LLM call),
 *               PRAutoBlogger_Cost_Tracker (logs prompt-rewrite cost),
 *               PRAutoBlogger_Logger (diagnostics).
 *
 * @see core/class-image-pipeline.php — Consumes build_article_prompt() and build_source_prompt().
 * @see providers/class-open-router-provider.php — LLM provider used for rewriting.
 * @see ARCHITECTURE.md              — Image generation data flow.
 */
class PRAutoBlogger_Image_Prompt_Builder {

	/**
	 * System prompt that teaches the LLM how to write image-gen prompts.
	 *
	 * Why this lives here instead of in wp_options: it's engineering-level
	 * instruction, not a user-facing setting. Changing it should require a
	 * code review, not an admin-panel click.
	 */
	private const REWRITER_SYSTEM_PROMPT = <<<'PROMPT'
You are a comedy writer and single-panel cartoon creator, like Gary Larson (The Far Side) meets science humor.

Given an article title and summary about peptides, supplements, or biohacking, create a SINGLE-PANEL COMIC concept. Output TWO parts separated by a blank line:

SCENE: One sentence describing the visual gag — a funny, absurd, or ironic situation related to the article's topic. Use anthropomorphized molecules, lab-coat-wearing animals, befuddled scientists, gym bros encountering peptide science, supplement bottles with personality, or everyday situations with a peptide twist. Keep it simple — one clear visual joke, 1-3 characters max.

CAPTION: A short, punchy caption or speech bubble line (under 15 words) that delivers the punchline. The humor should be dry, nerdy, and accessible — the reader should chuckle even if they only half-understand the science.

Rules:
- The joke MUST relate to the article's actual subject matter, not generic science humor
- Characters can have simple cartoon faces (round heads, dot eyes, expressive eyebrows)
- Keep the scene physically simple — few objects, clear staging, easy to read at thumbnail size
- The caption should work as a standalone joke even without the image
- No logos, watermarks, or branding
- Output ONLY the scene and caption. No preamble, no explanation.

Example output format:
A muscular gym bro stares in confusion at a tiny vial while a peptide molecule with arms and legs flexes next to him, clearly outperforming him.

"I've been doing this wrong for three years."
PROMPT;

	/**
	 * Max tokens for the rewriter response. Comic concepts need more room
	 * for the scene description plus the caption punchline.
	 */
	private const REWRITER_MAX_TOKENS = 180;

	/**
	 * OpenRouter provider for LLM calls. Null until first use.
	 *
	 * @var PRAutoBlogger_OpenRouter_Provider|null
	 */
	private ?PRAutoBlogger_OpenRouter_Provider $llm = null;

	/**
	 * Build a visual prompt from finished article content.
	 *
	 * Tries LLM rewriting first; falls back to rule-based synthesis on failure.
	 * Appends the style suffix from plugin options.
	 *
	 * @param array{
	 *     post_title?: string,
	 *     post_content?: string,
	 *     suggested_title?: string,
	 * } $article_data Article data with title and HTML content.
	 *
	 * @return string Concise prompt with style suffix appended.
	 */
	public function build_article_prompt( array $article_data ): string {
		$title      = $article_data['post_title'] ?? $article_data['suggested_title'] ?? 'Product';
		$content    = $article_data['post_content'] ?? '';
		$first_para = $this->extract_first_paragraph( $content );

		$concepts = $this->rewrite_via_llm( $title, $first_para );

		$style_suffix = get_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );

		return trim( $concepts . ' ' . $style_suffix );
	}

	/**
	 * Build a visual prompt from source Reddit thread data.
	 *
	 * Tries LLM rewriting first; falls back to rule-based synthesis on failure.
	 * Appends the style suffix from plugin options.
	 *
	 * @param array{
	 *     title?: string,
	 *     selftext?: string,
	 *     comments?: string[],
	 * } $source_data Reddit source data with title and comments.
	 *
	 * @return string Concise prompt with style suffix appended.
	 */
	public function build_source_prompt( array $source_data ): string {
		$title    = $source_data['title'] ?? 'Reddit Discussion';
		$comments = $source_data['comments'] ?? [];
		$context  = is_array( $comments ) && ! empty( $comments ) ? $comments[0] : '';

		$concepts = $this->rewrite_via_llm( $title, $context );

		$style_suffix = get_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );

		return trim( $concepts . ' ' . $style_suffix );
	}

	/**
	 * Use a cheap LLM to distill title + context into a visual scene.
	 *
	 * Falls back to rule-based synthesis if the LLM call fails for any
	 * reason (network, auth, timeout, unexpected response shape).
	 *
	 * Side effects: one OpenRouter API call; one cost-tracker log entry.
	 *
	 * @param string $title   Article or thread title.
	 * @param string $context First paragraph or top comment.
	 * @return string Visual scene description (without style suffix).
	 */
	private function rewrite_via_llm( string $title, string $context ): string {
		$title   = trim( sanitize_text_field( $title ) );
		$context = trim( sanitize_text_field( $context ) );

		// Truncate context to keep prompt tokens low.
		if ( strlen( $context ) > 300 ) {
			$context = substr( $context, 0, 300 ) . '...';
		}

		$user_message = "Article title: {$title}";
		if ( '' !== $context ) {
			$user_message .= "\n\nSummary: {$context}";
		}

		try {
			$llm   = $this->get_llm_provider();
			$model = get_option( 'prautoblogger_analysis_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );

			$result = $llm->send_chat_completion(
				[
					[ 'role' => 'system', 'content' => self::REWRITER_SYSTEM_PROMPT ],
					[ 'role' => 'user', 'content' => $user_message ],
				],
				$model,
				[
					'temperature' => 0.7,
					'max_tokens'  => self::REWRITER_MAX_TOKENS,
				]
			);

			$scene = trim( $result['content'] ?? '' );

			// Log the rewrite cost so it shows in the analytics dashboard.
			( new PRAutoBlogger_Cost_Tracker() )->log_api_call(
				null,
				'image_prompt_rewrite',
				'openrouter',
				$model,
				$result['prompt_tokens'] ?? 0,
				$result['completion_tokens'] ?? 0
			);

			$cost = $llm->estimate_cost(
				$model,
				$result['prompt_tokens'] ?? 0,
				$result['completion_tokens'] ?? 0
			);

			PRAutoBlogger_Logger::instance()->debug(
				sprintf( 'Image prompt rewritten (%d→%d chars, $%.6f): %s', strlen( $user_message ), strlen( $scene ), $cost, substr( $scene, 0, 120 ) ),
				'image_prompt_builder'
			);

			if ( '' !== $scene ) {
				return $scene;
			}
		} catch ( \Throwable $e ) {
			// LLM failure is not fatal — fall back to rule-based synthesis.
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Image prompt LLM rewrite %s, using fallback: %s', get_class( $e ), $e->getMessage() ),
				'image_prompt_builder'
			);
		}

		return $this->synthesize_visual_concepts_fallback( $title, $context );
	}

	/**
	 * Extract the first paragraph from HTML content.
	 *
	 * Removes all tags and captures the text up to the first double-newline
	 * or 200 characters, whichever comes first.
	 *
	 * @param string $html HTML content.
	 * @return string Plain text of the first paragraph.
	 */
	private function extract_first_paragraph( string $html ): string {
		$text  = wp_strip_all_tags( $html );
		$paras = preg_split( '/\n\n+/', $text );
		$para  = isset( $paras[0] ) ? trim( $paras[0] ) : '';

		if ( strlen( $para ) > 200 ) {
			$para = substr( $para, 0, 200 ) . '...';
		}

		return $para;
	}

	/**
	 * Rule-based fallback when LLM rewriting is unavailable.
	 *
	 * Kept deliberately simple — this only runs if OpenRouter is down.
	 *
	 * @param string $title           Main heading / topic.
	 * @param string $supporting_text Additional context.
	 * @return string Synthesized visual prompt concept.
	 */
	private function synthesize_visual_concepts_fallback( string $title, string $supporting_text ): string {
		// Fallback produces a simple comic concept when the LLM is unavailable.
		$prompt = sprintf(
			'A cartoon scientist in a lab coat looking bewildered while examining something related to: %s. Caption below reads: "Science is full of surprises."',
			$title
		);

		return trim( $prompt );
	}

	/**
	 * Lazy-load the OpenRouter provider.
	 *
	 * @return PRAutoBlogger_OpenRouter_Provider
	 */
	private function get_llm_provider(): PRAutoBlogger_OpenRouter_Provider {
		if ( null === $this->llm ) {
			$this->llm = new PRAutoBlogger_OpenRouter_Provider();
		}
		return $this->llm;
	}
}
