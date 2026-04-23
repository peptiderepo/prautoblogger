<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Renders the interactive model-picker field for OpenRouter models.
 *
 * What: a button showing the current model's name + pricing. Clicking it
 *       opens a JS-powered popup (model-picker.js) with a searchable list.
 * Who calls it: PRAutoBlogger_Admin_Page::render_field() delegates here
 *               for fields with type 'model_select'.
 * Dependencies: PRAutoBlogger_Model_Registry_Interface (cached registry lookup).
 *
 * @see admin/class-admin-page.php          — Calls render() from the main field switch.
 * @see assets/js/model-picker.js           — JS popup that fetches + displays models.
 * @see services/interface-model-registry.php — Registry interface for find_model().
 */
class PRAutoBlogger_OpenRouter_Model_Field {

	/**
	 * Render the model picker trigger button and hidden input.
	 *
	 * @param string               $id    Field HTML id/name attribute.
	 * @param string               $value Current model id (e.g. 'google/gemini-2.5-flash-lite').
	 * @param array<string, mixed> $args  Field definition from settings fields.
	 */
	public static function render( string $id, string $value, array $args ): void {
		$capability    = $args['capability'] ?? 'text→text';
		$display_name  = $value ?: __( '— Select a model —', 'prautoblogger' );
		$display_price = '';

		if ( 'image_generation' === $capability ) {
			// Resolve from the static image model list defined in settings.
			$image_models = PRAutoBlogger_Settings_Fields_Extended::get_image_models();
			foreach ( $image_models as $im ) {
				if ( ( $im['id'] ?? '' ) === $value ) {
					$display_name  = $im['name'] ?? $value;
					$display_price = '$' . number_format( (float) ( $im['cost_per_image'] ?? 0 ), 4 ) . '/image';
					break;
				}
			}
		} else {
			// Resolve from the OpenRouter model registry cache.
			$registry = prautoblogger()->get_executor()->get_model_registry();
			$model    = $registry->find_model( $value );
			if ( null !== $model ) {
				$display_name  = $model['name'] ?? $value;
				$in            = (float) ( $model['input_price_per_m'] ?? 0 );
				$out           = (float) ( $model['output_price_per_m'] ?? 0 );
				$display_price = self::format_price( $in ) . ' / ' . self::format_price( $out );
			}
		}

		// Hidden input carries the actual value for the form submission.
		printf(
			'<input type="hidden" id="%s" name="%s" value="%s" />',
			esc_attr( $id ),
			esc_attr( $id ),
			esc_attr( $value )
		);

		// Trigger button — opens the model picker popup via JS.
		printf(
			'<button type="button" class="ab-mp-trigger" data-field-id="%s" data-capability="%s">'
			. '<span class="ab-mp-display-name">%s</span>'
			. '<span class="ab-mp-display-price">%s</span>'
			. '<span class="ab-mp-display-arrow">&#9662;</span>'
			. '</button>',
			esc_attr( $id ),
			esc_attr( $capability ),
			esc_html( $display_name ),
			esc_html( $display_price )
		);
	}

	/**
	 * Format a price-per-million-tokens value for display.
	 *
	 * @param float $price Price per 1M tokens.
	 * @return string Formatted price string like "$0.25" or "Free".
	 */
	private static function format_price( float $price ): string {
		if ( $price <= 0 ) {
			return __( 'Free', 'prautoblogger' );
		}
		if ( $price < 0.01 ) {
			return '$' . number_format( $price, 4 );
		}
		return '$' . number_format( $price, 2 );
	}
}
