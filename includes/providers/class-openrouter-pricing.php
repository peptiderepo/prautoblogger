<?php
declare(strict_types=1);

/**
 * OpenRouter model pricing and availability lookup.
 *
 * Manages pricing tables, model lists, and cost estimation. Decoupled from
 * the request logic for easy updates and testing.
 *
 * Triggered by: Autoblogger_OpenRouter_Provider calls pricing methods.
 * Dependencies: None — pure data + calculation, no external calls by default.
 *
 * @see providers/class-openrouter-provider.php — Instantiates this class.
 * @see ARCHITECTURE.md                         — Model selection flow.
 */
class Autoblogger_OpenRouter_Pricing {

	/**
	 * Known model pricing (per million tokens, in USD).
	 * Updated periodically. Falls back to API-reported pricing if available.
	 *
	 * @var array<string, array{prompt: float, completion: float}>
	 */
	private const MODEL_PRICING = [
		'anthropic/claude-3.5-haiku'  => [ 'prompt' => 0.80,  'completion' => 4.00 ],
		'anthropic/claude-sonnet-4'   => [ 'prompt' => 3.00,  'completion' => 15.00 ],
		'anthropic/claude-opus-4'     => [ 'prompt' => 15.00, 'completion' => 75.00 ],
		'openai/gpt-4o'              => [ 'prompt' => 2.50,  'completion' => 10.00 ],
		'openai/gpt-4o-mini'         => [ 'prompt' => 0.15,  'completion' => 0.60 ],
		'google/gemini-2.0-flash'    => [ 'prompt' => 0.10,  'completion' => 0.40 ],
		'meta-llama/llama-3.3-70b'   => [ 'prompt' => 0.40,  'completion' => 0.40 ],
	];

	/**
	 * Get available models from OpenRouter's /models endpoint.
	 *
	 * Caches the result in a transient for 24 hours to avoid repeated API calls.
	 *
	 * Side effects: HTTP request (if cache miss), sets transient.
	 *
	 * @return array<int, array{id: string, name: string, context_length: int, pricing: array{prompt: float, completion: float}}>
	 */
	public function get_available_models(): array {
		$cached = get_transient( 'autoblogger_openrouter_models' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return [];
		}

		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/models',
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			Autoblogger_Logger::instance()->warning( 'Failed to fetch OpenRouter models list.', 'openrouter' );
			return [];
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = [];

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $model ) {
				$models[] = [
					'id'             => $model['id'] ?? '',
					'name'           => $model['name'] ?? $model['id'] ?? '',
					'context_length' => (int) ( $model['context_length'] ?? 0 ),
					'pricing'        => [
						'prompt'     => (float) ( $model['pricing']['prompt'] ?? 0 ) * 1000000,
						'completion' => (float) ( $model['pricing']['completion'] ?? 0 ) * 1000000,
					],
				];
			}
		}

		set_transient( 'autoblogger_openrouter_models', $models, DAY_IN_SECONDS );
		return $models;
	}

	/**
	 * Estimate the cost of an API call in USD.
	 *
	 * Uses hardcoded pricing first, falls back to cached model data.
	 *
	 * @param string $model            Model identifier.
	 * @param int    $prompt_tokens     Input tokens.
	 * @param int    $completion_tokens Output tokens.
	 *
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $model, int $prompt_tokens, int $completion_tokens ): float {
		$pricing = self::MODEL_PRICING[ $model ] ?? null;

		// Fall back to cached model list pricing.
		if ( null === $pricing ) {
			$models = $this->get_available_models();
			foreach ( $models as $m ) {
				if ( $m['id'] === $model && isset( $m['pricing'] ) ) {
					$pricing = $m['pricing'];
					break;
				}
			}
		}

		if ( null === $pricing ) {
			// Unknown model — use a conservative estimate.
			Autoblogger_Logger::instance()->warning( "Unknown model pricing for '{$model}'. Using conservative estimate.", 'openrouter' );
			$pricing = [ 'prompt' => 10.0, 'completion' => 30.0 ];
		}

		return ( $prompt_tokens * $pricing['prompt'] / 1000000 )
			+ ( $completion_tokens * $pricing['completion'] / 1000000 );
	}

	/**
	 * Get the decrypted API key from options.
	 *
	 * @return string Decrypted API key, or empty string if not set.
	 */
	private function get_api_key(): string {
		$encrypted = get_option( 'autoblogger_openrouter_api_key', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return Autoblogger_Encryption::decrypt( $encrypted );
	}
}
