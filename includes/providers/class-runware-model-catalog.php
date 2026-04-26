<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Runware model catalog sync — fetches live model list via Runware's /v1 endpoint,
 * filters to text-to-image tasks, caches normalized results, and falls back to
 * hardcoded list on API failure.
 *
 * What: Syncs Runware's model catalog periodically (daily cron + on-demand AJAX),
 *       caches the results in WP options, and merges pricing data from the pricing
 *       class when Runware's API doesn't expose it.
 * Who triggers it: Daily cron (prautoblogger_sync_runware_models), AJAX "Sync now"
 *                  button in PRAdmin, and lazy-load via PRAutoBlogger_Image_Model_Registry.
 * Dependencies: PRAutoBlogger_Logger, PRAutoBlogger_Runware_Image_Pricing,
 *               PRAutoBlogger_Image_Model_Registry.
 *
 * @see class-runware-image-pricing.php        — Authoritative pricing source & fallback models.
 * @see admin/class-image-model-registry.php   — Caller; merges Runware + OpenRouter lists.
 * @see class-prautoblogger.php                — Registers daily cron + AJAX hook.
 * @see class-activator.php / class-deactivator.php — Schedule / unschedule on activation/deactivation.
 */
class PRAutoBlogger_Runware_Model_Catalog {

	private const RUNWARE_API_URL = 'https://api.runware.ai/v1';
	private const CACHE_TTL_SECONDS = 86400;
	private const HTTP_TIMEOUT_SECONDS = 30;

	/**
	 * Fetch the Runware model catalog, normalize to the Image_Model_Registry
	 * shape, and write to WP options cache. Returns true on success, false on
	 * API failure or parse error.
	 *
	 * Side effects: makes HTTP request to Runware API, writes WP options.
	 * Error handling: logs via PRAutoBlogger_Logger, never throws, always
	 *                 returns bool to allow fallback to cached list.
	 *
	 * @return bool True on successful fetch and cache update; false on API or parse error.
	 */
	public function sync(): bool {
		try {
			$api_key = $this->get_api_key();
			if ( '' === $api_key ) {
				PRAutoBlogger_Logger::instance()->warning(
					'Runware model catalog sync: API key not configured. Using cached/fallback models.',
					'runware-catalog'
				);
				return false;
			}

			$raw_models = $this->fetch_models_from_api( $api_key );
			if ( empty( $raw_models ) ) {
				PRAutoBlogger_Logger::instance()->warning(
					'Runware model catalog fetch returned empty list. Using cached/fallback models.',
					'runware-catalog'
				);
				return false;
			}

			$normalized = $this->normalize_models( $raw_models );
			$with_pricing = $this->merge_pricing( $normalized );

			if ( empty( $with_pricing ) ) {
				PRAutoBlogger_Logger::instance()->warning(
					'Runware model catalog normalization resulted in empty list. Using cached/fallback models.',
					'runware-catalog'
				);
				return false;
			}

			update_option( 'prautoblogger_runware_model_cache', $with_pricing, false );
			update_option( 'prautoblogger_runware_model_cache_updated_at', time(), false );

			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Runware model catalog synced: %d models cached.', count( $with_pricing ) ),
				'runware-catalog'
			);
			return true;
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Runware model catalog sync failed: %s (%s)', $e->getMessage(), get_class( $e ) ),
				'runware-catalog'
			);
			return false;
		}
	}

	/**
	 * Get the normalized model list, with smart caching logic:
	 * - If cache is fresh (< 24h), return it.
	 * - If cache is stale or absent, trigger a sync.
	 * - If sync fails, return the last-known-good cache.
	 * - If no cache exists, return the hardcoded fallback (never empty).
	 *
	 * @return array<int, array<string, mixed>> Normalized model list.
	 */
	public function get_models(): array {
		if ( ! $this->is_stale() ) {
			$cached = get_option( 'prautoblogger_runware_model_cache', array() );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		if ( $this->sync() ) {
			$cached = get_option( 'prautoblogger_runware_model_cache', array() );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$cached = get_option( 'prautoblogger_runware_model_cache', array() );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			PRAutoBlogger_Logger::instance()->info(
				'Using stale Runware model cache (live sync failed).',
				'runware-catalog'
			);
			return $cached;
		}

		$fallback = PRAutoBlogger_Image_Model_Registry::get_runware_fallback_models();
		PRAutoBlogger_Logger::instance()->warning(
			sprintf(
				'No Runware model cache available; using hardcoded fallback (%d models).',
				count( $fallback )
			),
			'runware-catalog'
		);
		return $fallback;
	}

	/**
	 * Get the Unix timestamp of the last successful sync, or null if never synced.
	 *
	 * @return int|null Unix timestamp, or null if never synced.
	 */
	public function get_last_synced_at(): ?int {
		$ts = get_option( 'prautoblogger_runware_model_cache_updated_at', null );
		if ( null === $ts ) {
			return null;
		}
		$ts = (int) $ts;
		return $ts > 0 ? $ts : null;
	}

	/**
	 * Check if the cache is stale (older than 24h) or absent.
	 *
	 * @return bool True if cache should be refreshed; false if still fresh.
	 */
	public function is_stale(): bool {
		$updated_at = $this->get_last_synced_at();
		if ( null === $updated_at ) {
			return true;
		}
		return ( time() - $updated_at ) > self::CACHE_TTL_SECONDS;
	}

	/**
	 * Fetch the raw model list from Runware's /v1 endpoint via a models task.
	 *
	 * @param string $api_key Decrypted Runware API key.
	 * @return array Raw model list (may be empty).
	 * @throws \RuntimeException On HTTP error or malformed response.
	 */
	private function fetch_models_from_api( string $api_key ): array {
		$body = wp_json_encode(
			array(
				array(
					'taskType' => 'models',
					'apiKey'   => $api_key,
				),
			)
		);

		$response = wp_remote_post(
			self::RUNWARE_API_URL,
			array(
				'timeout' => self::HTTP_TIMEOUT_SECONDS,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf( 'Runware models endpoint unreachable: %s', $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$raw = wp_remote_retrieve_body( $response );
			throw new \RuntimeException(
				sprintf( 'Runware models endpoint returned HTTP %d', $status )
			);
		}

		$raw = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Runware models response is not valid JSON.' );
		}

		if ( isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) && ! empty( $decoded['errors'] ) ) {
			throw new \RuntimeException( 'Runware API returned an error.' );
		}

		$data = $decoded['data'] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Normalize raw Runware model objects to the PRAutoBlogger_Image_Model_Registry
	 * shape: id, name, provider, cost_per_image, capabilities, description.
	 *
	 * Filters to taskType='imageInference' (text-to-image only), excluding
	 * inpainting (imageInference + requiresImage=true), img2img, video, upscalers.
	 *
	 * @param array<int, array<string, mixed>> $raw_models Raw API response.
	 * @return array<int, array<string, mixed>> Normalized models.
	 */
	private function normalize_models( array $raw_models ): array {
		$normalized = array();

		foreach ( $raw_models as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$task_type = $raw['taskType'] ?? '';
			if ( 'imageInference' !== $task_type ) {
				continue;
			}

			if ( isset( $raw['requiresImage'] ) && true === $raw['requiresImage'] ) {
				continue;
			}

			$model_id = (string) ( $raw['id'] ?? '' );
			if ( '' === $model_id ) {
				continue;
			}

			$normalized[] = array(
				'id'             => $model_id,
				'name'           => (string) ( $raw['name'] ?? $model_id ),
				'provider'       => 'runware',
				'cost_per_image' => null,
				'capabilities'   => array( 'image_generation' ),
				'description'    => (string) ( $raw['description'] ?? '' ),
			);
		}

		return $normalized;
	}

	/**
	 * Merge pricing data from PRAutoBlogger_Runware_Image_Pricing into the
	 * normalized catalog. Models without pricing data get cost_per_image=null.
	 *
	 * @param array<int, array<string, mixed>> $normalized Normalized models.
	 * @return array<int, array<string, mixed>> Models with pricing merged.
	 */
	private function merge_pricing( array $normalized ): array {
		$pricing_table = PRAutoBlogger_Runware_Image_Pricing::get_model_costs();

		foreach ( $normalized as &$model ) {
			$model_id = $model['id'] ?? '';
			if ( isset( $pricing_table[ $model_id ] ) ) {
				$model['cost_per_image'] = (float) $pricing_table[ $model_id ];
			}
		}

		return $normalized;
	}

	/**
	 * Get the decrypted Runware API key from settings.
	 *
	 * @return string Plaintext key, or empty string if not configured.
	 */
	private function get_api_key(): string {
		$stored = (string) get_option( 'prautoblogger_runware_api_key', '' );
		if ( '' === $stored ) {
			return '';
		}

		if ( PRAutoBlogger_Encryption::is_encrypted( $stored ) ) {
			$decrypted = PRAutoBlogger_Encryption::decrypt( $stored );
			return '' === $decrypted ? '' : $decrypted;
		}

		return $stored;
	}
}
