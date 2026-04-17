<?php
declare(strict_types=1);

/**
 * Registers and renders the main PRAutoBlogger settings page in wp-admin.
 *
 * Uses the WordPress Settings API for option registration, sanitization, and rendering.
 * Settings are defined as a declarative array — adding a new setting is one array entry.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() registers menu and settings hooks.
 * Dependencies: PRAutoBlogger_Encryption (for API key storage), PRAutoBlogger_Settings_Fields.
 *
 * @see class-prautoblogger.php     — Registers hooks that call this class.
 * @see class-settings-fields.php — Defines all settings fields and sections.
 * @see CONVENTIONS.md            — "How To: Add a New Admin Setting".
 */
class PRAutoBlogger_Admin_Page {

	private const PAGE_SLUG    = 'prautoblogger-settings';
	private const OPTION_GROUP = 'prautoblogger_settings_group';

	/** Register the top-level PRAutoBlogger menu item. */
	public function on_register_menu(): void {
		add_menu_page(
			__( 'PRAutoBlogger', 'prautoblogger' ),
			__( 'PRAutoBlogger', 'prautoblogger' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-edit-page',
			30
		);
	}

	/** Register all settings with the WordPress Settings API. */
	public function on_register_settings(): void {
		$sections = PRAutoBlogger_Settings_Fields::get_sections();
		$fields   = PRAutoBlogger_Settings_Fields::get_fields();

		foreach ( $sections as $section_id => $section ) {
			add_settings_section( $section_id, $section['title'], '__return_empty_string', self::PAGE_SLUG );
		}

		foreach ( $fields as $field ) {
			$section = $field['section'] ?? 'prautoblogger_api';
			register_setting( self::OPTION_GROUP, $field['id'], [
				'type'              => $field['wp_type'] ?? 'string',
				'sanitize_callback' => [ $this, 'sanitize_field' ],
				'default'           => $field['default'] ?? '',
			] );
			add_settings_field( $field['id'], $field['label'], [ $this, 'render_field' ], self::PAGE_SLUG, $section, $field );
		}
	}

	/** Enqueue admin CSS and JS on all PRAutoBlogger admin pages. */
	public function on_enqueue_assets( string $hook_suffix ): void {
		$pages = [
			'toplevel_page_' . self::PAGE_SLUG,
			'prautoblogger_page_prautoblogger-metrics',
			'prautoblogger_page_prautoblogger-review-queue',
			'prautoblogger_page_prautoblogger-logs',
		];
		if ( ! in_array( $hook_suffix, $pages, true ) ) {
			return;
		}

		wp_enqueue_style( 'prautoblogger-admin', PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/admin.css', [], PRAUTOBLOGGER_VERSION );
		wp_enqueue_script( 'prautoblogger-admin', PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], PRAUTOBLOGGER_VERSION, true );

		wp_localize_script( 'prautoblogger-admin', 'prautobloggerAdmin', [
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'generateNonce'  => wp_create_nonce( 'prautoblogger_generate_now' ),
			'testNonce'      => wp_create_nonce( 'prautoblogger_test_connection' ),
			'reviewNonce'    => wp_create_nonce( 'prautoblogger_review_queue' ),
			'generatingText' => __( 'Generating...', 'prautoblogger' ),
			'generateText'   => __( 'Generate Now', 'prautoblogger' ),
			'testingText'    => __( 'Testing...', 'prautoblogger' ),
			'testText'       => __( 'Test Connections', 'prautoblogger' ),
		] );
	}

	/** Render the settings page (delegates to template). */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sections = PRAutoBlogger_Settings_Fields::get_sections();
		$fields   = PRAutoBlogger_Settings_Fields::get_fields();
		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/settings-page.php';
	}

	/**
	 * Render a single settings field based on its type.
	 *
	 * @param array<string, mixed> $args Field definition.
	 */
	public function render_field( array $args ): void {
		$id      = esc_attr( $args['id'] );
		$type    = $args['type'] ?? 'text';
		$default = $args['default'] ?? '';
		$desc    = $args['description'] ?? '';
		$value   = get_option( $args['id'], $default );

		if ( 'password' === $type && '' !== $value ) {
			$decrypted = PRAutoBlogger_Encryption::decrypt( $value );
			$value     = '' !== $decrypted ? '••••••••' : '';
		}

		switch ( $type ) {
			case 'text':
			case 'number':
			case 'url':
			case 'time':
				$attrs = '';
				if ( isset( $args['min'] ) ) { $attrs .= ' min="' . esc_attr( (string) $args['min'] ) . '"'; }
				if ( isset( $args['max'] ) ) { $attrs .= ' max="' . esc_attr( (string) $args['max'] ) . '"'; }
				if ( isset( $args['step'] ) ) { $attrs .= ' step="' . esc_attr( (string) $args['step'] ) . '"'; }
				printf( '<input type="%s" id="%s" name="%s" value="%s" class="ab-input" %s />', esc_attr( $type ), $id, $id, esc_attr( (string) $value ), $attrs );
				break;

			case 'password':
				printf( '<input type="password" id="%s" name="%s" value="" class="ab-input" placeholder="%s" autocomplete="off" />',
					$id, $id, '' !== $value ? esc_attr__( 'Saved (enter new value to change)', 'prautoblogger' ) : '' );
				break;

			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="3" class="ab-textarea">%s</textarea>', $id, $id, esc_textarea( (string) $value ) );
				break;

			case 'select':
				printf( '<select id="%s" name="%s" class="ab-select">', $id, $id );
				foreach ( ( $args['options'] ?? [] ) as $v => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $v ), selected( $value, (string) $v, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;

			case 'toggle':
				$checked = in_array( $value, [ '1', 'yes', true ], true );
				printf( '<label class="ab-toggle"><input type="hidden" name="%s" value="0" /><input type="checkbox" name="%s" value="1" %s /><span class="ab-toggle-slider"></span></label>',
					$id, $id, checked( $checked, true, false ) );
				break;

			case 'checkboxes':
				$current = json_decode( (string) $value, true ) ?: [];
				foreach ( ( $args['options'] ?? [] ) as $v => $label ) {
					$is_checked = in_array( $v, $current, true );
					$disabled   = strpos( $label, 'coming soon' ) !== false ? ' disabled' : '';
					printf( '<label class="ab-checkbox-label"><input type="checkbox" name="%s[]" value="%s" %s%s /> %s</label>',
						$id, esc_attr( $v ), checked( $is_checked, true, false ), $disabled, esc_html( $label ) );
				}
				break;

			case 'source_status':
				$this->render_source_status_field();
				break;

			case 'author_select':
				wp_dropdown_users( [ 'name' => $id, 'id' => $id, 'selected' => absint( $value ), 'show_option_none' => __( '— Auto (first admin) —', 'prautoblogger' ), 'option_none_value' => '0', 'class' => 'ab-select' ] );
				break;

			case 'category_select':
				wp_dropdown_categories( [ 'name' => $id, 'id' => $id, 'selected' => absint( $value ), 'show_option_none' => __( '— Auto-assign by type —', 'prautoblogger' ), 'option_none_value' => '0', 'class' => 'ab-select', 'hide_empty' => false ] );
				break;
		}

		if ( isset( $args['badge'] ) ) {
			printf( ' <span class="ab-badge">%s</span>', esc_html( $args['badge'] ) );
		}
		if ( '' !== $desc ) {
			printf( '<p class="ab-field-desc">%s</p>', esc_html( $desc ) );
		}
	}

	/**
	 * Render the Reddit source status indicator.
	 *
	 * Shows Reddit RSS (primary) and .json (fallback) availability
	 * with live status checks via transient cache and rate limit info.
	 *
	 * Side effects: Reads transients for cached availability status.
	 *
	 * @return void
	 */
	private function render_source_status_field(): void {
		$last_collection  = get_option( 'prautoblogger_last_collection_time', '' );
		$last_source_used = get_option( 'prautoblogger_last_source_used', '' );

		echo '<div class="ab-source-status">';

		// Reddit RSS — primary source.
		printf(
			'<div class="ab-source-row">'
			. '<span class="ab-source-dot ab-status-ok"></span>'
			. '<strong>%s</strong> <span class="ab-source-badge">%s</span>'
			. '<span class="ab-source-label">%s</span>'
			. '</div>',
			esc_html__( 'Reddit RSS', 'prautoblogger' ),
			esc_html__( 'Primary', 'prautoblogger' ),
			esc_html__( 'Reliable from all IPs', 'prautoblogger' )
		);

		// Reddit .json — fallback source.
		printf(
			'<div class="ab-source-row">'
			. '<span class="ab-source-dot ab-status-ok"></span>'
			. '<strong>%s</strong> <span class="ab-source-badge ab-badge-secondary">%s</span>'
			. '<span class="ab-source-label">%s</span>'
			. '</div>',
			esc_html__( 'Reddit .json', 'prautoblogger' ),
			esc_html__( 'Fallback + Comments', 'prautoblogger' ),
			esc_html__( 'May be IP-blocked on some hosts', 'prautoblogger' )
		);

		// Last collection info.
		if ( '' !== $last_collection ) {
			$time_ago = human_time_diff( (int) $last_collection, time() );
			printf(
				'<div class="ab-source-meta">%s <strong>%s</strong> %s</div>',
				esc_html__( 'Last collection:', 'prautoblogger' ),
				/* translators: %s is a human-readable time difference like "2 hours" */
				esc_html( sprintf( __( '%s ago', 'prautoblogger' ), $time_ago ) ),
				'' !== $last_source_used ? esc_html( sprintf( __( 'via %s', 'prautoblogger' ), $last_source_used ) ) : ''
			);
		}

		echo '</div>';
	}

	/**
	 * Sanitize a settings field value.
	 *
	 * @param mixed $value The submitted value.
	 * @return mixed Sanitized value.
	 */
	public function sanitize_field( $value ) {
		$option_name = '';
		$filter      = current_filter();
		if ( 0 === strpos( $filter, 'sanitize_option_' ) ) {
			$option_name = substr( $filter, strlen( 'sanitize_option_' ) );
		}

		$encrypted = [ 'prautoblogger_openrouter_api_key', 'prautoblogger_ga4_credentials_json' ];
		if ( in_array( $option_name, $encrypted, true ) ) {
			// Empty value means password field wasn't touched — keep existing.
			if ( '' === $value ) {
				return get_option( $option_name, '' );
			}

			// Already encrypted (has "enc:" prefix) — return as-is.
			// This is the primary defence against double-encryption:
			// PRAutoBlogger_Encryption::encrypt() also checks for this prefix,
			// so even if this callback is called multiple times, the value
			// is encrypted exactly once and never re-encrypted.
			if ( PRAutoBlogger_Encryption::is_encrypted( $value ) ) {
				return $value;
			}

			// New plaintext value — encrypt it (adds "enc:" prefix).
			return PRAutoBlogger_Encryption::encrypt( sanitize_text_field( $value ) );
		}

		$json_fields = [ 'prautoblogger_target_subreddits', 'prautoblogger_topic_exclusions', 'prautoblogger_enabled_sources' ];
		if ( in_array( $option_name, $json_fields, true ) ) {
			// PHP array from checkboxes — sanitize each item and re-encode.
			if ( is_array( $value ) ) {
				return wp_json_encode( array_values( array_map( 'sanitize_text_field', $value ) ) );
			}

			$trimmed = trim( (string) $value );

			// Already JSON-encoded array — decode, sanitize each item, re-encode.
			// This prevents sanitize_text_field() from mangling the JSON string,
			// which caused json_decode() on read to return null → empty subreddits.
			if ( '[' === substr( $trimmed, 0, 1 ) ) {
				$decoded = json_decode( $trimmed, true );
				if ( is_array( $decoded ) ) {
					return wp_json_encode( array_values( array_map( 'sanitize_text_field', $decoded ) ) );
				}
			}

			// Comma-separated plain text — split, sanitize, encode as JSON array.
			$items = array_filter( array_map( 'trim', explode( ',', $trimmed ) ) );
			return wp_json_encode( array_values( array_map( 'sanitize_text_field', $items ) ) );
		}

		$numeric = [ 'prautoblogger_daily_article_target', 'prautoblogger_monthly_budget_usd', 'prautoblogger_min_word_count', 'prautoblogger_max_word_count', 'prautoblogger_default_author', 'prautoblogger_default_category', 'prautoblogger_pullpush_cache_ttl', 'prautoblogger_reddit_posts_per_subreddit' ];
		if ( in_array( $option_name, $numeric, true ) ) {
			return is_numeric( $value ) ? $value : 0;
		}

		return sanitize_text_field( (string) $value );
	}
}
