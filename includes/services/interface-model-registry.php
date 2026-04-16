<?php
declare(strict_types=1);

/**
 * Contract for a provider-specific AI model registry.
 *
 * Phase 1 (v1): single implementation for OpenRouter (`class-open-router-model-registry.php`).
 * Phase 3: a second implementation wraps Cloudflare Workers AI FLUX model data. A
 * higher-level dispatcher iterates registered registries, calls each with the same
 * capability, and unions the results — the per-registry interface stays unchanged.
 *
 * Implementations MUST:
 *   - Store the normalized registry as a WP option + transient cache layer.
 *   - Degrade gracefully when the source API is unreachable (serve last-good cache).
 *   - Never throw on public read methods — empty arrays are acceptable fallbacks.
 *   - Log refresh failures via PRAutoBlogger_Logger.
 *
 * Triggered by: Settings page field renderer (commit 2), AJAX refresh endpoint
 *               (commit 3), daily cron via the main plugin loader.
 * Dependencies: None at interface level — implementations wrap an HTTP API.
 *
 * @see services/class-open-router-model-registry.php — Default implementation.
 * @see admin/fields/class-openrouter-model-field.php — Field renderer that queries this interface.
 * @see ARCHITECTURE.md                               — AI Model Registries section.
 */
interface PRAutoBlogger_Model_Registry_Interface {

	/**
	 * Return all models in the registry, normalized.
	 *
	 * @return array<int, array{
	 *     id: string,
	 *     name: string,
	 *     provider: string,
	 *     context_length: int,
	 *     input_price_per_m: float,
	 *     output_price_per_m: float,
	 *     capabilities: string[],
	 *     deprecated: bool,
	 *     updated_at: int,
	 * }> Normalized model records, sorted by name ascending.
	 */
	public function get_models(): array;

	/**
	 * Return only models matching a given capability string.
	 *
	 * @param string $capability One of the standardized capability strings:
	 *                           'text→text', 'text+image→text', 'text+audio→text',
	 *                           'text→image', 'text→audio', 'text→video',
	 *                           'text→embedding'.
	 *
	 * @return array<int, array> Same record shape as get_models(), filtered.
	 */
	public function get_models_with_capability( string $capability ): array;

	/**
	 * Look up one model by its provider-qualified id (e.g. 'anthropic/claude-3.5-haiku').
	 *
	 * @param string $id Model identifier.
	 *
	 * @return ?array Normalized model record, or null if not in the registry.
	 */
	public function find_model( string $id ): ?array;

	/**
	 * Fetch fresh data from the upstream source and replace the cached payload.
	 *
	 * When `$force` is false, implementations SHOULD skip the HTTP call if the
	 * payload is younger than a configured idempotency window (default 12h).
	 *
	 * Side effects: HTTP GET to the upstream API, wp_options write, transient set,
	 *               log line on failure, admin notice flag on stale-and-failed.
	 *
	 * @param bool $force True to bypass the idempotency window (e.g. manual admin refresh).
	 *
	 * @return array{count: int, fetched_at: int} Number of models stored and unix timestamp.
	 */
	public function refresh( bool $force = false ): array;

	/**
	 * Unix timestamp of the last successful refresh. Zero if never refreshed.
	 *
	 * @return int
	 */
	public function get_fetched_at(): int;

	/**
	 * Machine-readable provider identifier (e.g. 'openrouter', 'cloudflare_workers_ai').
	 *
	 * @return string
	 */
	public function get_provider_name(): string;
}
