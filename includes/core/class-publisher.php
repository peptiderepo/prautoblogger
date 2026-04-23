<?php
declare(strict_types=1);

/**
 * Creates WordPress posts from generated and editor-approved content.
 *
 * Handles both publishing (editor-approved) and saving as draft (editor-rejected).
 * Stores all generation metadata as post_meta for transparency and auditability.
 *
 * Triggered by: PRAutoBlogger_Pipeline_Runner (step 6).
 * Dependencies: PRAutoBlogger_Post_Assembler (taxonomy, images, sanitization),
 *               WordPress wp_insert_post(), post meta API.
 *
 * @see core/class-post-assembler.php   — Post-creation helpers (taxonomy, images, logs).
 * @see core/class-peptide-linker.php   — Injects peptide database hyperlinks into content.
 * @see core/class-chief-editor.php     — Produces the editorial review we consume.
 * @see core/class-content-generator.php — Produces the content we publish.
 * @see ARCHITECTURE.md                  — Data flow step 6.
 */
class PRAutoBlogger_Publisher {

	/**
	 * Publish an editor-approved article.
	 *
	 * @param string                        $content The final HTML content.
	 * @param PRAutoBlogger_Article_Idea      $idea    The original article idea.
	 * @param PRAutoBlogger_Editorial_Review  $review  The editor's review.
	 * @param string|null                   $run_id  Pipeline run ID for log linking.
	 * @return int The created post ID.
	 * @throws \RuntimeException If post creation fails.
	 */
	public function publish(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		?string $run_id = null
	): int {
		return $this->create_post( $content, $idea, $review, 'publish', $run_id );
	}

	/**
	 * Save an editor-rejected article as a draft for human review.
	 *
	 * @param string                        $content The generated HTML content (pre-revision).
	 * @param PRAutoBlogger_Article_Idea      $idea    The original article idea.
	 * @param PRAutoBlogger_Editorial_Review  $review  The editor's review with rejection notes.
	 * @param string|null                   $run_id  Pipeline run ID for log linking.
	 * @return int The created post ID.
	 * @throws \RuntimeException If post creation fails.
	 */
	public function save_as_draft(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		?string $run_id = null
	): int {
		return $this->create_post( $content, $idea, $review, 'draft', $run_id );
	}

	/**
	 * Create a WordPress post with generation metadata.
	 *
	 * @param string                        $content     HTML content.
	 * @param PRAutoBlogger_Article_Idea      $idea        Article idea.
	 * @param PRAutoBlogger_Editorial_Review  $review      Editorial review.
	 * @param string                        $post_status 'publish' or 'draft'.
	 * @param string|null                   $run_id      Pipeline run ID for log linking.
	 * @return int Post ID.
	 * @throws \RuntimeException If wp_insert_post fails.
	 */
	private function create_post(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		string $post_status,
		?string $run_id = null
	): int {
		// Clean LLM artifacts, then inject peptide hyperlinks deterministically
		// before the content enters WordPress. Peptide linker no-ops gracefully
		// if PR Core is not active.
		$clean_content  = PRAutoBlogger_Post_Assembler::sanitize_llm_content( $content );
		$linked_content = PRAutoBlogger_Peptide_Linker::inject_links( $clean_content );

		$post_data = array(
			'post_title'   => sanitize_text_field( $idea->get_suggested_title() ),
			'post_content' => wp_kses_post( $linked_content ),
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'post_author'  => PRAutoBlogger_Post_Assembler::get_default_author_id(),
			'meta_input'   => $this->build_meta( $idea, $review, $run_id ),
		);

		/** @see class-prautoblogger.php — listeners registered in main loader. */
		$post_data = apply_filters( 'prautoblogger_filter_post_data', $post_data, $idea, $review );

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException(
				sprintf( __( 'Failed to create post: %s', 'prautoblogger' ), $post_id->get_error_message() )
			);
		}

		PRAutoBlogger_Post_Assembler::assign_taxonomy_terms( $post_id, $idea );
		PRAutoBlogger_Post_Assembler::link_generation_logs( $post_id, $run_id );

		if ( 'publish' === $post_status ) {
			PRAutoBlogger_Post_Assembler::attach_generated_images( $post_id, $idea, $post_data );
		}

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Post created: ID=%d, status=%s, title="%s"', $post_id, $post_status, $idea->get_suggested_title() ),
			'publisher'
		);

		do_action( 'prautoblogger_post_created', $post_id, $post_status, $idea, $review );

		return $post_id;
	}

	/**
	 * Build post_meta array for generation metadata.
	 *
	 * The `_prautoblogger_run_id` key (added v0.8.1) lets the orphan-research
	 * reaper attribute an orphan `llm_research` row back to its sibling
	 * articles without re-walking the gen_log table. See
	 * core/class-research-reaper.php.
	 *
	 * @param PRAutoBlogger_Article_Idea     $idea
	 * @param PRAutoBlogger_Editorial_Review $review
	 * @param string|null                    $run_id Pipeline run UUID, or null in legacy paths.
	 * @return array<string, mixed>
	 */
	private function build_meta(
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		?string $run_id = null
	): array {
		$meta = array(
			'_prautoblogger_generated'       => '1',
			'_prautoblogger_analysis_id'     => $idea->get_analysis_id(),
			'_prautoblogger_source_ids'      => wp_json_encode( $idea->get_source_ids() ),
			'_prautoblogger_model_used'      => get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL ),
			'_prautoblogger_pipeline_mode'   => get_option( 'prautoblogger_writing_pipeline', 'multi_step' ),
			'_prautoblogger_editor_verdict'  => $review->get_verdict(),
			'_prautoblogger_editor_notes'    => $review->get_notes(),
			'_prautoblogger_quality_score'   => $review->get_quality_score(),
			'_prautoblogger_seo_score'       => $review->get_seo_score(),
			'_prautoblogger_generated_at'    => gmdate( 'c' ),
			'_prautoblogger_topic'           => $idea->get_topic(),
			'_prautoblogger_article_type'    => $idea->get_article_type(),
			'_prautoblogger_target_keywords' => wp_json_encode( $idea->get_target_keywords() ),
		);
		if ( null !== $run_id && '' !== $run_id ) {
			$meta['_prautoblogger_run_id'] = $run_id;
		}
		return $meta;
	}
}
