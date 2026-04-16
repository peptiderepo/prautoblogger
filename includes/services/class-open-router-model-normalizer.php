<?php
declare(strict_types=1);

/**
 * Normalizes raw OpenRouter model data into the standardized record shape.
 *
 * Extracted from PRAutoBlogger_OpenRouter_Model_Registry so both classes stay
 * under the 300-line cap. The registry orchestrates fetch + cache; this helper
 * owns the mapping logic.
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Model_Registry::refresh().
 * Dependencies: None — pure data transformation, no I/O.
 *
 * @see services/class-open-router-model-registry.php — Sole consumer.
 * @see interface-model-registry.php                  — Defines the normalized record shape.
 * @see ARCHITECTURE.md                               — Capability vocabulary.
 */
class PRAutoBlogger_OpenRouter_Model_Normalizer {

	/**
	 * Standardized capability vocabulary (CTO-locked). Derived from OpenRouter's
	 * `architecture.input_modalities` + `architecture.output_modalities`.
	 *
	 * @var array<string, array{in: string[], out: string[]}>
	 */
	private const CAPABILITY_MAP = [
		'text→text'        => [ 'in' => [ 'text' ],            'out' => [ 'text' ] ],
		'text+image→text'  => [ 'in' => [ 'text', 'image' ],   'out' => [ 'text' ] ],
		'text+audio→text'  => [ 'in' => [ 'text', 'audio' ],   'out' => [ 'text' ] ],
		'text→image'       => [ 'in' => [ 'text' ],            'out' => [ 'image' ] ],
		'text→audio'       => [ 'in' => [ 'text' ],            'out' => [ 'audio' ] ],
		'text→video'       => [ 'in' => [ 'text' ],            'out' => [ 'video' ] ],
		'text→embedding'   => [ 'in' => [ 'text' ],            'out' => [ 'embedding' ] ],
	];

	/**
	 * Normalize raw OpenRouter model records into the standardized shape.
	 *
	 * Unknown architectures degrade to 'text→text' capability — never throws.
	 * Pricing is converted from per-token (OpenRouter format) to per-million.
	 *
	 * @param array $raw_models Raw 'data' array from the OpenRouter API.
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
	 * }> Normalized records, sorted by name ascending.
	 */
	public function normalize( array $raw_models ): array {
		$normalized = [];

		foreach ( $raw_models as $model ) {
			if ( ! is_array( $model ) || ! isset( $model['id'] ) ) {
				continue;
			}

			$input_modalities  = (array) ( $model['architecture']['input_modalities'] ?? [ 'text' ] );
			$output_modalities = (array) ( $model['architecture']['output_modalities'] ?? [ 'text' ] );

			$name_parts = explode( '/', (string) $model['id'], 2 );

			// OpenRouter prices are per-token; convert to per-million.
			$prompt_price     = (float) ( $model['pricing']['prompt'] ?? 0 );
			$completion_price = (float) ( $model['pricing']['completion'] ?? 0 );

			$normalized[] = [
				'id'                 => (string) $model['id'],
				'name'               => (string) ( $model['name'] ?? $model['id'] ),
				'provider'           => $name_parts[0] ?? 'unknown',
				'context_length'     => (int) ( $model['context_length'] ?? 0 ),
				'input_price_per_m'  => round( $prompt_price * 1_000_000, 4 ),
				'output_price_per_m' => round( $completion_price * 1_000_000, 4 ),
				'capabilities'       => $this->derive_capabilities( $input_modalities, $output_modalities ),
				'deprecated'         => false,
				'updated_at'         => (int) ( $model['created'] ?? 0 ),
			];
		}

		usort( $normalized, static function ( array $a, array $b ): int {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return $normalized;
	}

	/**
	 * Derive capability strings from input/output modality arrays.
	 *
	 * @param string[] $input_modalities  e.g. ['text', 'image']
	 * @param string[] $output_modalities e.g. ['text']
	 *
	 * @return string[] Matched capability labels. Defaults to ['text→text'].
	 */
	private function derive_capabilities( array $input_modalities, array $output_modalities ): array {
		$caps = [];

		foreach ( self::CAPABILITY_MAP as $label => $requirements ) {
			$input_match  = empty( array_diff( $requirements['in'], $input_modalities ) );
			$output_match = empty( array_diff( $requirements['out'], $output_modalities ) );
			if ( $input_match && $output_match ) {
				$caps[] = $label;
			}
		}

		return empty( $caps ) ? [ 'text→text' ] : $caps;
	}
}
