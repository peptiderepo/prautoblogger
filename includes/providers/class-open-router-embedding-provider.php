<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Fetches text embeddings from OpenRouter for semantic similarity comparisons.
 *
 * Used by the Idea Scorer to detect semantically similar topics that keyword
 * matching would miss (e.g. "BPC-157 dosing" vs "how much BPC-157 to take").
 * OpenRouter exposes an OpenAI-compatible /embeddings endpoint.
 *
 * Triggered by: PRAutoBlogger_Idea_Scorer (semantic dedup check).
 * Dependencies: OpenRouter API key, OpenRouter_Config, OpenRouter_Request_Builder.
 *
 * @see core/class-idea-scorer.php               — Consumes embeddings for dedup.
 * @see providers/class-open-router-config.php    — API base URL resolution.
 * @see providers/class-open-router-request-builder.php — Header + cURL auth.
 */
class PRAutoBlogger_OpenRouter_Embedding_Provider {

	/** Default embedding model — small, fast, nearly free ($0.02/M tokens). */
	private const DEFAULT_MODEL = 'sentence-transformers/all-minilm-l12-v2';

	/** Embedding dimension for MiniLM-L12-v2. Used for validation. */
	private const EXPECTED_DIMENSIONS = 384;

	/**
	 * Generate embeddings for one or more text inputs in a single API call.
	 *
	 * Batches all inputs into one request to minimize HTTP round-trips.
	 * Returns one embedding vector per input, in the same order.
	 *
	 * Side effects: HTTP request to OpenRouter API.
	 *
	 * @param string[] $texts  Array of text strings to embed.
	 * @param string   $model  Optional model override.
	 *
	 * @return float[][] Array of embedding vectors, one per input text.
	 *                   Each vector is an array of floats (384 dimensions for MiniLM).
	 *
	 * @throws \RuntimeException On API error or malformed response.
	 */
	public function get_embeddings( array $texts, string $model = '' ): array {
		if ( empty( $texts ) ) {
			return array();
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			throw new \RuntimeException(
				__( 'OpenRouter API key is not configured.', 'prautoblogger' )
			);
		}

		$model = '' !== $model ? $model : self::DEFAULT_MODEL;

		$config    = new PRAutoBlogger_OpenRouter_Config();
		$base_url  = $config->get_api_base_url();
		$base_host = (string) wp_parse_url( $base_url, PHP_URL_HOST );

		$builder         = new PRAutoBlogger_OpenRouter_Request_Builder();
		$request_headers = $builder->build_headers( $api_key, $config->is_via_gateway(), 0 );
		$curl_filter     = $builder->register_curl_auth_filter( $request_headers, $base_host );

		try {
			$response = wp_remote_post(
				$base_url . '/embeddings',
				array(
					'timeout' => 30,
					'headers' => $request_headers,
					'body'    => wp_json_encode(
						array(
							'model' => $model,
							'input' => array_values( $texts ),
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException(
					sprintf( 'Embedding request failed: %s', $response->get_error_message() )
				);
			}

			$status = wp_remote_retrieve_response_code( $response );
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $status >= 400 ) {
				$msg = $body['error']['message'] ?? wp_remote_retrieve_body( $response );
				throw new \RuntimeException(
					sprintf( 'Embedding API error (HTTP %d): %s', $status, $msg )
				);
			}

			return $this->parse_response( $body, count( $texts ) );
		} finally {
			remove_action( 'http_api_curl', $curl_filter, 99 );
		}
	}

	/**
	 * Compute cosine similarity between two embedding vectors.
	 *
	 * Returns a value between -1 and 1. Values above ~0.7 indicate
	 * high semantic similarity for MiniLM embeddings.
	 *
	 * @param float[] $a First embedding vector.
	 * @param float[] $b Second embedding vector.
	 *
	 * @return float Cosine similarity score.
	 */
	public static function cosine_similarity( array $a, array $b ): float {
		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;
		$len    = min( count( $a ), count( $b ) );

		for ( $i = 0; $i < $len; $i++ ) {
			$dot    += $a[ $i ] * $b[ $i ];
			$norm_a += $a[ $i ] * $a[ $i ];
			$norm_b += $b[ $i ] * $b[ $i ];
		}

		$denom = sqrt( $norm_a ) * sqrt( $norm_b );
		if ( $denom < 1e-10 ) {
			return 0.0;
		}

		return $dot / $denom;
	}

	/**
	 * Parse the OpenAI-compatible embeddings response.
	 *
	 * @param array|null $body           Decoded JSON response body.
	 * @param int        $expected_count Number of embeddings expected.
	 *
	 * @return float[][] Embedding vectors sorted by input order.
	 *
	 * @throws \RuntimeException On malformed response.
	 */
	private function parse_response( ?array $body, int $expected_count ): array {
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			throw new \RuntimeException( 'Embedding response missing "data" field.' );
		}

		if ( count( $body['data'] ) !== $expected_count ) {
			throw new \RuntimeException(
				sprintf( 'Expected %d embeddings, got %d.', $expected_count, count( $body['data'] ) )
			);
		}

		// Sort by index to match input order (API may return out of order).
		usort(
			$body['data'],
			static function ( array $a, array $b ): int {
				return ( $a['index'] ?? 0 ) <=> ( $b['index'] ?? 0 );
			}
		);

		$embeddings = array();
		foreach ( $body['data'] as $item ) {
			if ( ! isset( $item['embedding'] ) || ! is_array( $item['embedding'] ) ) {
				throw new \RuntimeException( 'Embedding item missing "embedding" array.' );
			}
			$embeddings[] = array_map( 'floatval', $item['embedding'] );
		}

		return $embeddings;
	}

	/**
	 * Get the decrypted API key from options.
	 *
	 * @return string Decrypted API key, or empty string if not set.
	 */
	private function get_api_key(): string {
		$encrypted = get_option( 'prautoblogger_openrouter_api_key', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return PRAutoBlogger_Encryption::decrypt( $encrypted );
	}
}
