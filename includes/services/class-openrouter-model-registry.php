<?php
declare(strict_types=1);

/**
 * OpenRouter model registry — fetches, normalizes, and caches the model list.
 *
 * All config is constructor-injected. No PRAUTOBLOGGER_* constants referenced
 * inside this class — Phase 2 lifts it into a shared Composer package with
 * zero internal edits.
 *
 * Storage: WP option (durable) fronted by a transient (fast reads, 24h TTL).
 * On stale-and-fetch-fails, the option payload is served and a flag is set.
 *
 * Triggered by: `prautoblogger_refresh_model_registry` action, manual AJAX
 *               (commit 3), PRAutoBlogger_Activator::activate().
 * Dependencies: wp_remote_get(), PRAutoBlogger_Logger,
 *               PRAutoBlogger_OpenRouter_Model_Normalizer.
 *
 * @see interface-model-registry.php               — Interface.
 * @see class-openrouter-model-normalizer.php       — Normalization logic.
 * @see class-prautoblogger.php                     — Hooks the refresh action.
 * @see ARCHITECTURE.md                             — AI Model Registries section.
 */
class PRAutoBlogger_OpenRouter_Model_Registry implements PRAutoBlogger_Model_Registry_Interface {

	private const PROVIDER_ID       = 'openrouter';
	private const LOG_CONTEXT       = 'model-registry';
	private const HTTP_TIMEOUT      = 15;
	private const ADMIN_NOTICE_FLAG = 'prautoblogger_model_registry_stale';

	private string $option_name;
	private string $fetched_at_option;
	private string $transient_name;
	private string $endpoint;
	private int $cache_ttl;
	private int $idempotency_window;
	private PRAutoBlogger_OpenRouter_Model_Normalizer $normalizer;

	/**
	 * @param string $option_name         WP option for the durable payload.
	 * @param string $transient_name      WP transient for fast reads.
	 * @param string $endpoint            OpenRouter models API URL.
	 * @param int    $cache_ttl_seconds   Transient TTL (default 86400 = 24h).
	 * @param int    $idempotency_seconds Skip refresh if younger than this (default 43200 = 12h).
	 */
	public function __construct(
		string $option_name,
		string $transient_name,
		string $endpoint = 'https://openrouter.ai/api/v1/models',
		int $cache_ttl_seconds = 86400,
		int $idempotency_seconds = 43200
	) {
		$this->option_name        = $option_name;
		$this->fetched_at_option  = $option_name . '_fetched_at';
		$this->transient_name    = $transient_name;
		$this->endpoint          = $endpoint;
		$this->cache_ttl         = $cache_ttl_seconds;
		$this->idempotency_window = $idempotency_seconds;
		$this->normalizer        = new PRAutoBlogger_OpenRouter_Model_Normalizer();
	}

	/** {@inheritDoc} */
	public function get_models(): array {
		return $this->load_payload();
	}

	/** {@inheritDoc} */
	public function get_models_with_capability( string $capability ): array {
		return array_values(
			array_filter(
				$this->load_payload(),
				static function ( array $model ) use ( $capability ): bool {
					return in_array( $capability, $model['capabilities'] ?? [], true );
				}
			)
		);
	}

	/** {@inheritDoc} */
	public function find_model( string $id ): ?array {
		foreach ( $this->load_payload() as $model ) {
			if ( ( $model['id'] ?? '' ) === $id ) {
				return $model;
			}
		}
		return null;
	}

	/** {@inheritDoc} */
	public function refresh( bool $force = false ): array {
		$fetched_at = $this->get_fetched_at();

		if ( ! $force && $fetched_at > 0 && ( time() - $fetched_at ) < $this->idempotency_window ) {
			$payload = $this->load_payload();
			return [ 'count' => count( $payload ), 'fetched_at' => $fetched_at ];
		}

		$raw_models = $this->fetch_from_api();
		if ( null === $raw_models ) {
			$this->flag_stale();
			$stale = $this->load_from_option();
			return [ 'count' => count( $stale ), 'fetched_at' => $fetched_at ];
		}

		$normalized = $this->normalizer->normalize( $raw_models );
		$now        = time();

		$this->save_payload( $normalized, $now );
		$this->clear_stale_flag();

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Model registry refreshed: %d models.', count( $normalized ) ),
			self::LOG_CONTEXT
		);

		return [ 'count' => count( $normalized ), 'fetched_at' => $now ];
	}

	/** {@inheritDoc} */
	public function get_fetched_at(): int {
		return (int) get_option( $this->fetched_at_option, 0 );
	}

	/** {@inheritDoc} */
	public function get_provider_name(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * Load from transient (fast) falling back to option (durable).
	 *
	 * @return array<int, array>
	 */
	private function load_payload(): array {
		$cached = get_transient( $this->transient_name );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		return $this->load_from_option();
	}

	/** @return array<int, array> */
	private function load_from_option(): array {
		$data = get_option( $this->option_name, [] );
		return is_array( $data ) ? $data : [];
	}

	private function save_payload( array $payload, int $now ): void {
		update_option( $this->option_name, $payload, false );
		update_option( $this->fetched_at_option, $now, false );
		set_transient( $this->transient_name, $payload, $this->cache_ttl );
	}

	/**
	 * Fetch the model list from the OpenRouter API with exponential-backoff retry.
	 *
	 * @return ?array Raw 'data' array, or null on failure.
	 */
	private function fetch_from_api(): ?array {
		$max_retries = defined( 'PRAUTOBLOGGER_MAX_RETRIES' ) ? PRAUTOBLOGGER_MAX_RETRIES : 3;
		$base_delay  = defined( 'PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS' ) ? PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS : 2;

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$response = wp_remote_get( $this->endpoint, [ 'timeout' => self::HTTP_TIMEOUT ] );

			if ( is_wp_error( $response ) ) {
				PRAutoBlogger_Logger::instance()->warning(
					sprintf( 'Model registry fetch attempt %d: %s', $attempt, $response->get_error_message() ),
					self::LOG_CONTEXT
				);
				$this->backoff( $attempt, $base_delay );
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );

			if ( $status >= 500 || 429 === $status ) {
				PRAutoBlogger_Logger::instance()->warning(
					sprintf( 'Model registry fetch attempt %d: HTTP %d', $attempt, $status ),
					self::LOG_CONTEXT
				);
				$this->backoff( $attempt, $base_delay );
				continue;
			}

			if ( $status >= 400 ) {
				PRAutoBlogger_Logger::instance()->error(
					sprintf( 'Model registry: HTTP %d: %s', $status, substr( $body, 0, 300 ) ),
					self::LOG_CONTEXT
				);
				return null;
			}

			$decoded = json_decode( $body, true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
				PRAutoBlogger_Logger::instance()->error( 'Model registry: unexpected response shape.', self::LOG_CONTEXT );
				return null;
			}

			return $decoded['data'];
		}

		PRAutoBlogger_Logger::instance()->error(
			sprintf( 'Model registry fetch failed after %d attempts.', $max_retries ),
			self::LOG_CONTEXT
		);
		return null;
	}

	private function backoff( int $attempt, int $base_delay ): void {
		$delay = $base_delay * (int) pow( 2, $attempt - 1 );
		if ( $delay > 0 ) {
			sleep( $delay );
		}
	}

	private function flag_stale(): void {
		update_option( self::ADMIN_NOTICE_FLAG, '1', false );
		PRAutoBlogger_Logger::instance()->warning( 'Model registry: serving stale data after refresh failure.', self::LOG_CONTEXT );
	}

	private function clear_stale_flag(): void {
		delete_option( self::ADMIN_NOTICE_FLAG );
	}
}
