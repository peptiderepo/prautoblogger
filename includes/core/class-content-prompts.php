<?php
declare(strict_types=1);

/**
 * Builds all LLM prompts used by the content generation pipeline.
 *
 * Centralises prompt construction so Content_Generator stays lean and
 * prompt logic (system prompt, stage prompts, linking rules) can evolve
 * independently of the generation orchestration.
 *
 * Triggered by: PRAutoBlogger_Content_Generator (called for every stage).
 * Dependencies: WordPress query functions (get_posts, get_the_title, get_permalink).
 *
 * @see core/class-content-generator.php — Consumer of these prompts.
 * @see models/class-content-request.php — Data bag passed to every builder.
 */
class PRAutoBlogger_Content_Prompts {

	/**
	 * Build the system prompt shared across all writing stages.
	 *
	 * Includes niche, tone, mandatory style guide, internal link reference,
	 * peptide database links, and the "never fabricate URLs" rule.
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @return string Complete system prompt.
	 */
	public static function build_system( PRAutoBlogger_Content_Request $request ): string {
		$niche  = $request->get_niche_description();
		$prompt = 'You are an expert blog writer';
		if ( '' !== $niche ) {
			$prompt .= " specializing in {$niche}";
		}
		$prompt .= '. Write well-researched, engaging, SEO-friendly content. ';
		$prompt .= "Use a {$request->get_tone()} tone. ";
		$prompt .= 'Output HTML content only — no markdown, no code fences, no commentary.';

		// Append user-defined writing instructions as a mandatory style guide.
		$instructions = trim( $request->get_writing_instructions() );
		if ( '' !== $instructions ) {
			$prompt .= "\n\n--- MANDATORY STYLE GUIDE ---\n";
			$prompt .= "You MUST follow every requirement below. These override any conflicting defaults:\n\n";
			$prompt .= $instructions;
			$prompt .= "\n--- END STYLE GUIDE ---";
		}

		$prompt .= "\n\n" . self::build_linking_rules();

		return $prompt;
	}

	/**
	 * Build the user prompt for single-pass generation.
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @return string User prompt.
	 */
	public static function build_single_pass( PRAutoBlogger_Content_Request $request ): string {
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
			"- Output HTML only, no markdown or commentary\n" .
			'- Follow EVERY formatting and structural requirement from your system prompt style guide',
			$idea->get_suggested_title(),
			$idea->get_topic(),
			$idea->get_article_type(),
			implode( "\n- ", $idea->get_key_points() ),
			implode( ', ', $idea->get_target_keywords() ),
			$request->get_min_word_count(),
			$request->get_max_word_count()
		);
	}

	/**
	 * Build the outline stage prompt (multi-step stage 1).
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @return string Outline prompt.
	 */
	public static function build_outline( PRAutoBlogger_Content_Request $request ): string {
		$idea = $request->get_idea();

		return sprintf(
			"Create a detailed outline for a blog post titled: \"%s\"\n\n" .
			"Topic: %s\nArticle type: %s\n\nKey points to cover:\n%s\n\n" .
			"Target keywords: %s\n\n" .
			'The outline should have 4-6 main sections with bullet points under each. ' .
			'Include an introduction hook and a conclusion with a call to action. ' .
			"Word count target: %d-%d words.\n\n" .
			'Plan the structure to satisfy EVERY requirement in your system prompt style guide.',
			$idea->get_suggested_title(),
			$idea->get_topic(),
			$idea->get_article_type(),
			implode( "\n- ", $idea->get_key_points() ),
			implode( ', ', $idea->get_target_keywords() ),
			$request->get_min_word_count(),
			$request->get_max_word_count()
		);
	}

	/**
	 * Build the draft stage prompt (multi-step stage 2).
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @param string                        $outline The outline from stage 1.
	 * @return string Draft prompt.
	 */
	public static function build_draft( PRAutoBlogger_Content_Request $request, string $outline ): string {
		return sprintf(
			"Using this outline, write the full blog post in HTML format.\n\n" .
			"OUTLINE:\n%s\n\n" .
			"Requirements:\n" .
			"- Write in a %s tone\n" .
			"- Target %d-%d words\n" .
			"- Use proper HTML headings (h2, h3), paragraphs, and lists\n" .
			"- Include an engaging introduction and strong conclusion\n" .
			"- Naturally incorporate these keywords: %s\n" .
			"- Do NOT include the title in the HTML (it will be set separately)\n" .
			"- Do NOT wrap in <html>, <head>, or <body> tags — just the article content\n" .
			'- Follow EVERY formatting and structural requirement from your system prompt style guide',
			$outline,
			$request->get_tone(),
			$request->get_min_word_count(),
			$request->get_max_word_count(),
			implode( ', ', $request->get_idea()->get_target_keywords() )
		);
	}

	/**
	 * Build the polish stage prompt (multi-step stage 3).
	 *
	 * @param string $draft The draft from stage 2.
	 * @return string Polish prompt.
	 */
	public static function build_polish( string $draft ): string {
		return "Review and polish this blog post draft. Improve:\n" .
			"1. Flow and readability\n" .
			"2. SEO optimization (headings, keyword placement)\n" .
			"3. Engagement (hooks, transitions, call-to-action)\n" .
			"4. Accuracy and clarity\n" .
			"5. Remove any filler or redundant sentences\n\n" .
			'IMPORTANT: Preserve all bullet points, numbered lists, hyperlinks, and ' .
			'structural elements from the draft. Do NOT flatten lists into prose or ' .
			'remove links. Ensure every requirement from your system prompt style ' .
			"guide is satisfied in the final output.\n\n" .
			"Return the polished HTML content only. Do not add commentary.\n\n" .
			"DRAFT:\n" . $draft;
	}

	/**
	 * Build the linking rules section (internal articles + peptide database).
	 *
	 * Fetches published blog posts and peptide pages, formats them as a
	 * reference list so the model uses real URLs instead of fabricating them.
	 *
	 * @return string Complete linking rules block.
	 */
	private static function build_linking_rules(): string {
		$rules  = "--- LINKING RULES ---\n";
		$rules .= "NEVER fabricate or invent URLs. You do NOT have access to external sources.\n";
		$rules .= "Only use the internal links listed below when linking within the article.\n";
		$rules .= "If no listed article is relevant to a section, do not insert a link.\n";
		$rules .= "Do NOT add links for peptide names — those are injected automatically after generation.\n\n";

		$rules .= self::build_article_links();

		$rules .= '--- END LINKING RULES ---';

		return $rules;
	}

	/**
	 * Build a reference list of published blog posts for internal linking.
	 *
	 * @return string Formatted list, or a fallback note.
	 */
	private static function build_article_links(): string {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return "No published articles available for internal linking yet.\n";
		}

		$lines = array( "Available article links (use where topically relevant):\n" );
		foreach ( $posts as $post_id ) {
			$title = get_the_title( $post_id );
			$url   = get_permalink( $post_id );
			if ( $title && $url ) {
				$lines[] = "- {$title}: {$url}";
			}
		}

		return implode( "\n", $lines ) . "\n";
	}
}
