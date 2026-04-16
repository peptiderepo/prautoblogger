<?php
declare(strict_types=1);

/**
 * Builds image prompts from article content or source data.
 *
 * Generates concise visual concepts suitable for FLUX.1 image generation,
 * extracting key themes from article titles/content and Reddit source data.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline during content generation run.
 * Dependencies: None — pure data transformation.
 *
 * @see core/class-image-pipeline.php — Consumes build_article_prompt() and build_source_prompt().
 * @see ARCHITECTURE.md              — Image generation data flow.
 */
class PRAutoBlogger_Image_Prompt_Builder {

	/**
	 * Build a visual prompt from finished article content.
	 *
	 * Extracts the title and first paragraph to synthesize a product-marketing
	 * angle visual concept suitable for a featured image. Appends the style suffix
	 * from plugin options.
	 *
	 * @param array{
	 *     post_title?: string,
	 *     post_content?: string,
	 *     suggested_title?: string,
	 * } $article_data Article data with title and HTML content.
	 *
	 * @return string Concise prompt (under 200 words) with style suffix appended.
	 */
	public function build_article_prompt( array $article_data ): string {
		$title   = $article_data['post_title'] ?? $article_data['suggested_title'] ?? 'Product';
		$content = $article_data['post_content'] ?? '';

		// Extract first paragraph from HTML content.
		$first_para = $this->extract_first_paragraph( $content );

		// Build visual concepts from title + first paragraph.
		$concepts = $this->synthesize_visual_concepts( $title, $first_para );

		// Append style suffix.
		$style_suffix = get_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );

		return trim( $concepts . ' ' . $style_suffix );
	}

	/**
	 * Build a visual prompt from source Reddit thread data.
	 *
	 * Extracts the Reddit thread title and top comments to synthesize an
	 * editorial-cartoonist angle visual concept. Appends the style suffix
	 * from plugin options.
	 *
	 * @param array{
	 *     title?: string,
	 *     selftext?: string,
	 *     comments?: string[],
	 * } $source_data Reddit source data with title and comments.
	 *
	 * @return string Concise prompt (under 200 words) with style suffix appended.
	 */
	public function build_source_prompt( array $source_data ): string {
		$title    = $source_data['title'] ?? 'Reddit Discussion';
		$comments = $source_data['comments'] ?? [];

		// Build visual concepts from title + top comments.
		$concepts = $this->synthesize_visual_concepts(
			$title,
			is_array( $comments ) && ! empty( $comments ) ? $comments[0] : ''
		);

		// Append style suffix.
		$style_suffix = get_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );

		return trim( $concepts . ' ' . $style_suffix );
	}

	/**
	 * Extract the first paragraph from HTML content.
	 *
	 * Removes all tags and captures the text up to the first double-newline
	 * or 200 characters, whichever comes first.
	 *
	 * @param string $html HTML content.
	 *
	 * @return string Plain text of the first paragraph.
	 */
	private function extract_first_paragraph( string $html ): string {
		// Remove HTML tags.
		$text = wp_strip_all_tags( $html );

		// Split on double-newline and take the first paragraph.
		$paras  = preg_split( '/\n\n+/', $text );
		$para   = isset( $paras[0] ) ? trim( $paras[0] ) : '';

		// Limit to first 200 chars to keep prompt concise.
		if ( strlen( $para ) > 200 ) {
			$para = substr( $para, 0, 200 ) . '...';
		}

		return $para;
	}

	/**
	 * Synthesize visual concepts from a title and supporting text.
	 *
	 * Combines key words and themes into a short visual narrative that FLUX.1
	 * can render. This is intentionally rule-based rather than LLM-driven to
	 * avoid another API call per image generation.
	 *
	 * @param string $title Main heading / topic.
	 * @param string $supporting_text Additional context (first para or comment).
	 *
	 * @return string Synthesized visual prompt concept.
	 */
	private function synthesize_visual_concepts( string $title, string $supporting_text ): string {
		$title = trim( sanitize_text_field( $title ) );
		$text  = trim( sanitize_text_field( $supporting_text ) );

		// Build a simple declarative concept from title and text snippets.
		$prompt = "A visual representation of: {$title}";

		if ( ! empty( $text ) ) {
			// Take first 150 chars to avoid bloating the prompt.
			$snippet = strlen( $text ) > 150
				? substr( $text, 0, 150 ) . '...'
				: $text;

			$prompt .= ". Context: {$snippet}";
		}

		return trim( $prompt );
	}
}
