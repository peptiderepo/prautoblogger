<?php
declare(strict_types=1);

/**
 * Post-creation assembly helpers: taxonomy, log linking, images, content sanitization.
 *
 * What: After wp_insert_post, assigns categories/tags, links generation logs,
 *       generates and attaches images, and sanitizes raw LLM content.
 * Who calls it: PRAutoBlogger_Publisher::create_post().
 * Dependencies: WordPress taxonomy/meta APIs, PRAutoBlogger_Image_Pipeline, PRAutoBlogger_Logger.
 *
 * @see core/class-publisher.php — Orchestrates post creation and calls these helpers.
 * @see core/class-image-pipeline.php — Image generation for attach_generated_images.
 */
class PRAutoBlogger_Post_Assembler {

	/**
	 * Assign category and tags to a generated post.
	 *
	 * @param int                        $post_id Post ID.
	 * @param PRAutoBlogger_Article_Idea $idea    Article idea with type and keywords.
	 */
	public static function assign_taxonomy_terms( int $post_id, PRAutoBlogger_Article_Idea $idea ): void {
		$type_category_map = [
			'guide'      => 'Guides',
			'solution'   => 'Solutions',
			'comparison' => 'Comparisons',
			'article'    => 'Articles',
		];

		$category_name = $type_category_map[ $idea->get_article_type() ] ?? 'Articles';
		$category      = get_term_by( 'name', $category_name, 'category' );

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
	 * Link generation log entries to the newly created post.
	 * Uses run_id for precise linking; falls back to timestamp for legacy data.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $run_id  Pipeline run identifier, or null for legacy fallback.
	 */
	public static function link_generation_logs( int $post_id, ?string $run_id = null ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		if ( null !== $run_id && '' !== $run_id ) {
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
	 * Strip common LLM output artifacts from generated content.
	 * Removes markdown fences, HTML document wrappers, stray head-level tags.
	 *
	 * @param string $content Raw LLM output.
	 * @return string Cleaned HTML suitable for post_content.
	 */
	public static function sanitize_llm_content( string $content ): string {
		$content = trim( $content );

		// Strip markdown code fences: ```html ... ``` or ``` ... ```
		if ( preg_match( '/^```(?:\w+)?\s*\n(.*?)```\s*$/s', $content, $matches ) ) {
			$content = trim( $matches[1] );
		}

		// Strip full HTML document wrappers — extract only <body> inner content.
		if ( preg_match( '/<body[^>]*>(.*)<\/body>/is', $content, $matches ) ) {
			$content = trim( $matches[1] );
		}

		// Remove stray document-level tags.
		$content = preg_replace( '/<\/?(html|head|body|meta|title|!DOCTYPE)[^>]*>/i', '', $content );
		$content = preg_replace( '/<link\s[^>]*>/i', '', $content );
		$content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		return trim( $content );
	}

	/**
	 * Get the default author ID for generated posts.
	 *
	 * @return int WordPress user ID.
	 */
	public static function get_default_author_id(): int {
		$author_id = absint( get_option( 'prautoblogger_default_author', 0 ) );
		if ( $author_id > 0 ) {
			return $author_id;
		}

		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		return ! empty( $admins ) ? (int) $admins[0] : 1;
	}

	/**
	 * Generate and attach images to a published post.
	 * Non-blocking — logs errors but doesn't re-throw since the post is already created.
	 *
	 * @param int                        $post_id   Post ID.
	 * @param PRAutoBlogger_Article_Idea $idea      Article idea (contains source IDs).
	 * @param array                      $post_data Post data array (for article content).
	 */
	public static function attach_generated_images( int $post_id, PRAutoBlogger_Article_Idea $idea, array $post_data ): void {
		// Fetch the original Reddit source data for Image B's source-driven prompt.
		// The idea's source_ids point to rows in the source_data table collected
		// during the pipeline's collection step.
		$source_data = ( new PRAutoBlogger_Source_Collector() )->get_source_data_for_image( $idea->get_source_ids() );

		try {
			$result = ( new PRAutoBlogger_Image_Pipeline() )->generate_and_attach_images( $post_id, $post_data, $source_data );

			if ( isset( $result['image_a_id'] ) ) {
				set_post_thumbnail( $post_id, $result['image_a_id'] );
				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Set featured image (attachment %d) for post %d', $result['image_a_id'], $post_id ),
					'publisher'
				);
			}

			if ( isset( $result['image_b_id'] ) ) {
				update_post_meta( $post_id, '_prautoblogger_image_b_id', $result['image_b_id'] );
				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Stored Image B (attachment %d) for post %d', $result['image_b_id'], $post_id ),
					'publisher'
				);
			}

			if ( ! empty( $result['errors'] ) ) {
				foreach ( $result['errors'] as $error ) {
					PRAutoBlogger_Logger::instance()->warning( 'Image warning for post ' . $post_id . ': ' . $error, 'publisher' );
				}
			}

			if ( $result['cost_usd'] > 0.0 ) {
				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Image generation cost: $%.4f for post %d', $result['cost_usd'], $post_id ),
					'publisher'
				);
			}
		} catch ( \Exception $e ) {
			PRAutoBlogger_Logger::instance()->warning( 'Image pipeline exception for post ' . $post_id . ': ' . $e->getMessage(), 'publisher' );
		}
	}
}
