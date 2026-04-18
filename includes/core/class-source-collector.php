<?php
declare(strict_types=1);

/**
 * Orchestrates data collection from all enabled source providers.
 *
 * Iterates through enabled sources (currently Reddit), calls their
 * collect_data() method, and stores results in the ab_source_data table.
 * Handles deduplication via UNIQUE index on (source_type, source_id).
 *
 * Triggered by: PRAutoBlogger::run_generation_pipeline() (step 1).
 * Dependencies: Source provider implementations, WordPress $wpdb.
 *
 * @see providers/interface-source-provider.php — Interface providers implement.
 * @see providers/class-reddit-provider.php     — Primary source provider.
 * @see ARCHITECTURE.md                         — Data flow step 1.
 */
class PRAutoBlogger_Source_Collector {

	/**
	 * Collect data from all enabled source providers and store in database.
	 *
	 * Side effects: HTTP requests to source APIs, database inserts.
	 *
	 * @return int Total number of new records inserted.
	 */
	public function collect_from_all_sources(): int {
		$enabled = json_decode( get_option( 'prautoblogger_enabled_sources', '["reddit"]' ), true );
		if ( ! is_array( $enabled ) ) {
			$enabled = [ 'reddit' ];
		}

		$total_inserted = 0;

		foreach ( $enabled as $source_type ) {
			$provider = $this->get_provider( $source_type );
			if ( null === $provider ) {
				PRAutoBlogger_Logger::instance()->warning( "No provider found for source type: {$source_type}", 'collector' );
				continue;
			}

			try {
				$data     = $provider->collect_data( $this->get_config_for_source( $source_type ) );
				$inserted = $this->store_data( $data );
				$total_inserted += $inserted;

				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Collected %d items from %s (%d new).', count( $data ), $source_type, $inserted ),
					'collector'
				);
			} catch ( \Throwable $e ) {
				PRAutoBlogger_Logger::instance()->error( sprintf( 'Collection %s from %s: %s', get_class( $e ), $source_type, $e->getMessage() ), 'collector' );
				// Continue with other sources — one failure shouldn't stop everything.
			}
		}

		return $total_inserted;
	}

	/**
	 * Retrieve source data rows by their database IDs.
	 *
	 * Used by the image pipeline to build Image B's source-driven prompt from
	 * the source_ids stored on the Article_Idea. Returns the first matching
	 * row's title and content; these map directly to what
	 * Image_Prompt_Builder::build_source_prompt() expects.
	 *
	 * Side effects: one SELECT query.
	 *
	 * @param int[] $ids Database row IDs from the source_data table.
	 * @return array{title: string, selftext: string}|null First source row, or null if none found.
	 */
	public function get_source_data_for_image( array $ids ): ?array {
		if ( empty( $ids ) ) {
			return null;
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'prautoblogger_source_data';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT title, content, subreddit FROM {$table} WHERE id IN ({$placeholders}) ORDER BY score DESC LIMIT 1",
				...$ids
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		return [
			'title'    => $row['title'] ?? 'Reddit Discussion',
			'selftext' => $row['content'] ?? '',
		];
	}

	/**
	 * Store collected source data in the database.
	 *
	 * Uses INSERT IGNORE to handle duplicates gracefully (UNIQUE index).
	 *
	 * Side effects: database inserts.
	 *
	 * @param PRAutoBlogger_Source_Data[] $items
	 *
	 * @return int Number of new records inserted.
	 */
	private function store_data( array $items ): int {
		global $wpdb;
		$table    = $wpdb->prefix . 'prautoblogger_source_data';
		$inserted = 0;

		foreach ( $items as $item ) {
			$row = $item->to_db_row();

			// Use INSERT IGNORE so duplicate (source_type, source_id) pairs are silently skipped.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
					(source_type, source_id, subreddit, title, content, author, score, comment_count, permalink, collected_at, metadata_json)
					VALUES (%s, %s, %s, %s, %s, %s, %d, %d, %s, %s, %s)",
					$row['source_type'],
					$row['source_id'],
					$row['subreddit'],
					$row['title'],
					$row['content'],
					$row['author'],
					$row['score'],
					$row['comment_count'],
					$row['permalink'],
					$row['collected_at'],
					$row['metadata_json']
				)
			);

			if ( false !== $result && $result > 0 ) {
				$inserted++;
			}
		}

		return $inserted;
	}

	/**
	 * Get the provider instance for a given source type.
	 *
	 * @param string $source_type Source type identifier.
	 *
	 * @return PRAutoBlogger_Source_Provider_Interface|null
	 */
	private function get_provider( string $source_type ): ?PRAutoBlogger_Source_Provider_Interface {
		$providers = [
			'reddit' => PRAutoBlogger_Reddit_Provider::class,
			// New providers can be added here — see CONVENTIONS.md for how-to.
		];

		/**
		 * Filter the registered source providers.
		 *
		 * @param array<string, string> $providers Map of source_type => class name.
		 */
		$providers = apply_filters( 'prautoblogger_filter_source_providers', $providers );

		if ( ! isset( $providers[ $source_type ] ) ) {
			return null;
		}

		$class = $providers[ $source_type ];

		// Guard against unloadable provider classes (e.g. stub providers not yet
		// autoloadable, or classes removed during development).
		if ( ! class_exists( $class ) ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Provider class "%s" for source type "%s" does not exist.', $class, $source_type ),
				'collector'
			);
			return null;
		}

		return new $class();
	}

	/**
	 * Build the configuration array for a specific source type from plugin settings.
	 *
	 * @param string $source_type Source type identifier.
	 *
	 * @return array<string, mixed> Configuration for the provider.
	 */
	private function get_config_for_source( string $source_type ): array {
		if ( 'reddit' === $source_type ) {
			return [
				'subreddits'       => json_decode( get_option( 'prautoblogger_target_subreddits', '[]' ), true ) ?: [],
				'limit'            => 25,
				'time_filter'      => 'day',
				'include_comments' => true,
				'comments_per_post' => 10,
			];
		}

		return [];
	}
}
