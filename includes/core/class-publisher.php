<?php
declare(strict_types=1);

/**
 * Creates WordPress posts from generated and editor-approved content.
 *
 * Handles both publishing (editor-approved) and saving as draft (editor-rejected).
 * Stores all generation metadata as post_meta for transparency and auditability.
 *
 * Triggered by: PRAutoBlogger::run_generation_pipeline() (step 6).
 * Dependencies: WordPress wp_insert_post(), post meta API.
 *
 * @see core/class-chief-editor.php     — Produces the editorial review we consume.
 * @see core/class-content-generator.php — Produces the content we publish.
 * @see ARCHITECTURE.md                  — Data flow step 6.
 */
class PRAutoBlogger_Publisher {

	/**
	 * Publish an editor-approved article.
	 *
	 * Creates a WordPress post with 'publish' status and stores all generation
	 * metadata as post_meta.
	 *
	 * Side effects: Creates a WordPress post, writes post_meta.
	 *
	 * @param string                        $content The final HTML content.
	 * @param PRAutoBlogger_Article_Idea      $idea    The original article idea.
	 * @param PRAutoBlogger_Editorial_Review  $review  The editor's review.
	 * @param string|null                   $run_id  Pipeline run ID for log linking.
	 *
	 * @return int The created post ID.
	 *
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
	 *
	 * @return int The created post ID.
	 *
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
	 *
	 * @return int Post ID.
	 *
	 * @throws \RuntimeException If wp_insert_post fails.
	 */
	private function create_post(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		string $post_status,
		?string $run_id = null
	): int {
		$post_data = [
			'post_title'   => sanitize_text_field( $idea->get_suggested_title() ),
			'post_content' => wp_kses_post( $content ),
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'post_author'  => $this->get_default_author_id(),
			'meta_input'   => $this->build_meta( $idea, $review ),
		];

		/**
		 * Fires before an PRAutoBlogger post is created.
		 *
		 * Listeners registered in: class-prautoblogger.php (main loader).
		 *
		 * @param array                        $post_data Post data array.
		 * @param PRAutoBlogger_Article_Idea     $idea      The article idea.
		 * @param PRAutoBlogger_Editorial_Review $review    The editorial review.
		 */
		$post_data = apply_filters( 'prautoblogger_filter_post_data', $post_data, $idea, $review );

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: WordPress error message */
					__( 'Failed to create post: %s', 'prautoblogger' ),
					$post_id->get_error_message()
				)
			);
		}

		// Assign categories/tags based on article type and keywords.
		$this->assign_taxonomy_terms( $post_id, $idea );

		// Update generation log entries to reference this post.
		$this->link_generation_logs( $post_id, $run_id );

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Post created: ID=%d, status=%s, title="%s"', $post_id, $post_status, $idea->get_suggested_title() ),
			'publisher'
		);

		/**
		 * Fires after an PRAutoBlogger post is successfully created.
		 *
		 * @param int                          $post_id     The new post ID.
		 * @param string                       $post_status 'publish' or 'draft'.
		 * @param PRAutoBlogger_Article_Idea     $idea        The article idea.
		 * @param PRAutoBlogger_Editorial_Review $review      The editorial review.
		 */
		do_action( 'prautoblogger_post_created', $post_id, $post_status, $idea, $review );

		return $post_id;
	}

	/**
	 * Build post_meta array for generation metadata.
	 *
	 * @param PRAutoBlogger_Article_Idea     $idea
	 * @param PRAutoBlogger_Editorial_Review $review
	 *
	 * @return array<string, mixed>
	 */
	private function build_meta(
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review
	): array {
		$pipeline_mode = get_option( 'prautoblogger_writing_pipeline', 'multi_step' );
		$model         = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );

		return [
			'_prautoblogger_generated'        => '1',
			'_prautoblogger_analysis_id'      => $idea->get_analysis_id(),
			'_prautoblogger_source_ids'       => wp_json_encode( $idea->get_source_ids() ),
			'_prautoblogger_model_used'       => $model,
			'_prautoblogger_pipeline_mode'    => $pipeline_mode,
			'_prautoblogger_editor_verdict'   => $review->get_verdict(),
			'_prautoblogger_editor_notes'     => $review->get_notes(),
			'_prautoblogger_quality_score'    => $review->get_quality_score(),
			'_prautoblogger_seo_score'        => $review->get_seo_score(),
			'_prautoblogger_generated_at'     => gmdate( 'c' ),
			'_prautoblogger_topic'            => $idea->get_topic(),
			'_prautoblogger_article_type'     => $idea->get_article_type(),
			'_prautoblogger_target_keywords'  => wp_json_encode( $idea->get_target_keywords() ),
		];
	}

	/**
	 * Assign category and tags to a generated post.
	 *
	 * @param int                      $post_id
	 * @param PRAutoBlogger_Article_Idea $idea
	 *
	 * @return void
	 */
	private function assign_taxonomy_terms( int $post_id, PRAutoBlogger_Article_Idea $idea ): void {
		$type_category_map = [
			'guide'      => 'Guides',
			'solution'   => 'Solutions',
			'comparison' => 'Comparisons',
			'article'    => 'Articles',
		];

		$category_name = $type_category_map[ $idea->get_article_type() ] ?? 'Articles';

		$category = get_term_by( 'name', $category_name, 'category' );
		if ( ! $category ) {
			$result = wp_insert_term( $category_name, 'category' );
			if ( ! is_wp_error( $result ) ) {
				wp_set_post_categories( $post_id, [ $result['term_id'] ] );
			}
		} else {
			wp_set_post_categories( $post_id, [ $category->term_id ] );
		}

		$keywords = $idea->get_target_keywords();
		if ( ! empty( $keywords ) ) {
			wp_set_post_tags( $post_id, $keywords, true );
		}
	}

	/**
	 * Link generation log entries to the newly created post using run_id.
	 *
	 * The previous timestamp-based approach could misattribute costs in batch runs
	 * where multiple articles are generated in the same pipeline execution. Using
	 * run_id ensures each post is linked only to the log entries from its own run.
	 *
	 * Falls back to timestamp-based linking if no run_id is set (e.g. legacy data
	 * from before the run_id migration).
	 *
	 * @param int         $post_id The newly created post ID.
	 * @param string|null $run_id  Pipeline run identifier, or null for legacy fallback.
	 *
	 * @return void
	 */
	private function link_generation_logs( int $post_id, ?string $run_id = null ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		if ( null !== $run_id && '' !== $run_id ) {
			// Precise linking via run_id — no risk of cross-article misattribution.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET post_id = %d WHERE post_id IS NULL AND run_id = %s",
					$post_id,
					$run_id
				)
			);
		} else {
			// Legacy fallback: timestamp-based linking for pre-migration log entries.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET post_id = %d WHERE post_id IS NULL AND created_at >= %s",
					$post_id,
					gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
				)
			);
		}
	}

	/**
	 * Get the default author ID for generated posts.
	 *
	 * @return int WordPress user ID.
	 */
	private function get_default_author_id(): int {
		$author_id = absint( get_option( 'prautoblogger_default_author', 0 ) );
		if ( $author_id > 0 ) {
			return $author_id;
		}

		$admins = get_users( [
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		] );

		return ! empty( $admins ) ? (int) $admins[0] : 1;
	}
}
