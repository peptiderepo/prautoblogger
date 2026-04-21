<?php
declare(strict_types=1);

/**
 * Contract for any image generation provider (Runware FLUX, OpenRouter Gemini, etc.).
 *
 * Consumers depend on this interface, never on a concrete provider — swapping
 * providers means writing one new class, not refactoring the pipeline. This
 * mirrors the LLM provider pattern in `interface-llm-provider.php` (see CTO
 * rule #7 — interface for external service integrations).
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline (added in commit #1b) during the
 *               content generation run, after editorial review, before publish.
 * Dependencies: None at interface level — implementations wrap a specific HTTP API.
 *
 * @see class-runware-image-provider.php    — Runware FLUX implementation (default).
 * @see class-open-router-image-provider.php — OpenRouter image implementation.
 * @see interface-llm-provider.php          — Parallel pattern for LLM providers.
 * @see ARCHITECTURE.md                     — External API Integrations table.
 */
interface PRAutoBlogger_Image_Provider_Interface {

	/**
	 * Generate a single image from a prompt at the requested dimensions.
	 *
	 * Implementations MUST:
	 *   - Apply retry + exponential backoff on transient failures (5xx, network).
	 *   - Fail loudly on client errors (4xx) rather than silently returning empty.
	 *   - Log each attempt via PRAutoBlogger_Logger.
	 *   - Persist a structured event row via PRAutoBlogger_Event_Log (added in a
	 *     later commit — safe to no-op until then).
	 *
	 * Side effects: HTTP request to the provider API.
	 *
	 * @param string $prompt Full prompt (concept + style suffix already concatenated).
	 * @param int    $width  Target width in pixels (>= 64, <= 2048).
	 * @param int    $height Target height in pixels (>= 64, <= 2048).
	 * @param array{
	 *     seed?: int,
	 *     steps?: int,
	 *     model?: string,
	 * } $options Optional tuning. `seed` lets the caller ask for visually
	 *           coherent variants across placements. `steps` trades quality
	 *           for cost (default model-dependent). `model` picks a specific
	 *           model identifier; implementations may ignore values they
	 *           don't support.
	 *
	 * @return array{
	 *     bytes: string,
	 *     mime_type: string,
	 *     width: int,
	 *     height: int,
	 *     model: string,
	 *     seed: ?int,
	 *     cost_usd: float,
	 *     latency_ms: int,
	 * } Raw image bytes (PNG or JPEG) plus metadata. Metadata is used by the
	 *   caller to write post meta and to bill against the plugin's cost budget.
	 *
	 * @throws \RuntimeException On API error after retries exhausted, or on
	 *                            an invalid response shape.
	 * @throws \InvalidArgumentException If dimensions are out of range.
	 */
	public function generate_image( string $prompt, int $width, int $height, array $options = [] ): array;

	/**
	 * Estimate the USD cost of a single generation call before making it.
	 *
	 * Used by the cost tracker to pre-check budget and to show an expected
	 * spend figure in the dry-run preview.
	 *
	 * @param int   $width  Target width in pixels.
	 * @param int   $height Target height in pixels.
	 * @param array $options Same shape as generate_image() options.
	 *
	 * @return float Estimated cost in USD for a single image at the given dimensions.
	 */
	public function estimate_cost( int $width, int $height, array $options = [] ): float;

	/**
	 * Machine-readable provider identifier used in logs and settings.
	 *
	 * @return string Lowercase identifier, e.g. 'runware' or 'openrouter'.
	 */
	public function get_provider_name(): string;

	/**
	 * Generate multiple images in parallel (or sequentially as fallback).
	 *
	 * Each entry in $requests describes one image to generate. The return array
	 * is indexed identically — callers can zip input and output by key.
	 *
	 * Implementations that support concurrent HTTP (e.g. curl_multi) SHOULD
	 * override the default sequential behaviour for a wall-clock speedup.
	 *
	 * Side effects: HTTP requests (possibly concurrent), Logger lines per image.
	 *
	 * @param array<string, array{
	 *     prompt: string,
	 *     width: int,
	 *     height: int,
	 *     options?: array{seed?: int, steps?: int, model?: string},
	 * }> $requests Keyed image generation requests.
	 *
	 * @return array<string, array{
	 *     bytes: string,
	 *     mime_type: string,
	 *     width: int,
	 *     height: int,
	 *     model: string,
	 *     seed: ?int,
	 *     cost_usd: float,
	 *     latency_ms: int,
	 * }|array{error: string}> Each key maps to either image data or an error.
	 */
	public function generate_image_batch( array $requests ): array;

	/**
	 * Verify the provider credentials are present and the API is reachable.
	 *
	 * Used by the admin "Test Connections" action. Implementations should make
	 * a lightweight request (e.g. a models list or token verify endpoint) — NOT
	 * generate a real image.
	 *
	 * Side effects: HTTP request, logs the result.
	 *
	 * @return array{status: string, message: string, debug?: string}
	 *         status is 'ok' or 'error'; message is human-readable for the
	 *         admin UI; debug is optional diagnostic detail for logs.
	 */
	public function validate_credentials_detailed(): array;
}
