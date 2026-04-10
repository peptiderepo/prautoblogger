<?php
declare(strict_types=1);

/**
 * Contract for any LLM provider integration (OpenRouter, direct Anthropic, OpenAI, etc.).
 *
 * Any class implementing this interface can be swapped in without changing the
 * content generation, analysis, or editor logic.
 *
 * @see class-openrouter-provider.php — Primary implementation (OpenRouter).
 * @see CONVENTIONS.md                — "How To: Add a New LLM Provider".
 * @see ARCHITECTURE.md               — External API integrations table.
 */
interface Autoblogger_LLM_Provider_Interface {

	/**
	 * Send a chat completion request to the LLM.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Chat messages array.
	 * @param string $model   Model identifier (e.g., 'anthropic/claude-sonnet-4').
	 * @param array{
	 *     temperature?: float,
	 *     max_tokens?: int,
	 *     response_format?: array{type: string},
	 * } $options Optional parameters for the API call.
	 *
	 * @return array{
	 *     content: string,
	 *     model: string,
	 *     prompt_tokens: int,
	 *     completion_tokens: int,
	 *     total_tokens: int,
	 *     finish_reason: string,
	 * } Normalized response.
	 *
	 * @throws \RuntimeException On API error after retries exhausted.
	 */
	public function send_chat_completion( array $messages, string $model, array $options = [] ): array;

	/**
	 * Get the list of available models from the provider.
	 *
	 * @return array<int, array{id: string, name: string, context_length: int, pricing: array{prompt: float, completion: float}}> Available models.
	 */
	public function get_available_models(): array;

	/**
	 * Estimate the cost of an API call based on token counts.
	 *
	 * @param string $model            Model identifier.
	 * @param int    $prompt_tokens     Number of input tokens.
	 * @param int    $completion_tokens Number of output tokens.
	 *
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $model, int $prompt_tokens, int $completion_tokens ): float;

	/**
	 * Get the human-readable name of this provider.
	 *
	 * @return string Provider name (e.g., 'OpenRouter').
	 */
	public function get_provider_name(): string;

	/**
	 * Validate that the provider's credentials are configured and working.
	 *
	 * @return bool True if credentials are valid.
	 */
	public function validate_credentials(): bool;
}
