<?php
declare(strict_types=1);

/**
 * Opik settings UI and registration.
 *
 * Registers settings fields in the PRAutoBlogger Settings page under
 * a new "Observability" section. Fields include:
 * - Enable Opik tracing (checkbox, default off)
 * - Opik project name (text, default 'prautoblogger')
 * - Capture full prompts (checkbox, default off)
 * - Status display (read-only: API key configured, last dispatch, queue depth)
 *
 * Never renders the API key value in admin HTML.
 *
 * @see includes/admin/class-admin-page.php — Main settings page loader.
 */
class PRAutoBlogger_Opik_Settings {

	/**
	 * Constructor.
	 *
	 * Register hooks in __construct so they fire when the plugin loads.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register Opik settings section and fields.
	 *
	 * Hooked to 'admin_init'.
	 */
	public function register_settings(): void {
		// Register the section.
		add_settings_section(
			'prautoblogger_observability',
			__( 'Observability (Opik)', 'prautoblogger' ),
			array( $this, 'render_section_help' ),
			'prautoblogger_settings'
		);

		// Register fields.
		add_settings_field(
			'prautoblogger_opik_enabled',
			__( 'Enable Opik tracing', 'prautoblogger' ),
			array( $this, 'render_field_enabled' ),
			'prautoblogger_settings',
			'prautoblogger_observability'
		);

		register_setting(
			'prautoblogger_settings',
			'prautoblogger_opik_enabled',
			array( 'type' => 'boolean' )
		);

		add_settings_field(
			'prautoblogger_opik_project_name',
			__( 'Opik project name', 'prautoblogger' ),
			array( $this, 'render_field_project_name' ),
			'prautoblogger_settings',
			'prautoblogger_observability'
		);

		register_setting(
			'prautoblogger_settings',
			'prautoblogger_opik_project_name',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		add_settings_field(
			'prautoblogger_opik_capture_full_prompt',
			__( 'Capture full prompt text', 'prautoblogger' ),
			array( $this, 'render_field_capture_full' ),
			'prautoblogger_settings',
			'prautoblogger_observability'
		);

		register_setting(
			'prautoblogger_settings',
			'prautoblogger_opik_capture_full_prompt',
			array( 'type' => 'boolean' )
		);

		add_settings_field(
			'prautoblogger_opik_status',
			__( 'Status', 'prautoblogger' ),
			array( $this, 'render_field_status' ),
			'prautoblogger_settings',
			'prautoblogger_observability'
		);
	}

	/**
	 * Render section help text.
	 */
	public function render_section_help(): void {
		echo wp_kses_post(
			'<p>Optional LLM observability via <a href="https://www.comet.com/" target="_blank" rel="noopener">Comet Opik</a>. ' .
			'Captures detailed traces of article generation with per-LLM-call metrics. ' .
			'Requires API credentials in <code>wp-config.php</code>.</p>'
		);
	}

	/**
	 * Render the "Enable Opik" checkbox.
	 */
	public function render_field_enabled(): void {
		$enabled = get_option( 'prautoblogger_opik_enabled', false );
		$checked = $enabled ? 'checked' : '';
		?>
		<input type="checkbox" name="prautoblogger_opik_enabled" value="1" <?php echo esc_attr( $checked ); ?> />
		<label for="prautoblogger_opik_enabled">
			<?php esc_html_e( 'Send article generation traces to Opik', 'prautoblogger' ); ?>
		</label>
		<?php
		if ( ! $this->has_credentials() ) {
			echo '<p style="color: #d63638;"><strong>' .
				esc_html__( 'Note: API credentials must be set in wp-config.php (PRAUTOBLOGGER_OPIK_API_KEY, PRAUTOBLOGGER_OPIK_WORKSPACE)', 'prautoblogger' ) .
				'</strong></p>';
		}
	}

	/**
	 * Render the project name field.
	 */
	public function render_field_project_name(): void {
		$value = get_option( 'prautoblogger_opik_project_name', 'prautoblogger' );
		?>
		<input type="text" name="prautoblogger_opik_project_name" value="<?php echo esc_attr( $value ); ?>" style="width: 300px;" />
		<p class="description">
			<?php esc_html_e( 'Opik auto-creates this project on first trace (lowercase, no spaces)', 'prautoblogger' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "Capture full prompt" checkbox.
	 */
	public function render_field_capture_full(): void {
		$enabled = get_option( 'prautoblogger_opik_capture_full_prompt', false );
		$checked = $enabled ? 'checked' : '';
		?>
		<input type="checkbox" name="prautoblogger_opik_capture_full_prompt" value="1" <?php echo esc_attr( $checked ); ?> />
		<label>
			<?php esc_html_e( 'Include full prompt text in spans (increases payload size)', 'prautoblogger' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'By default, only prompt hash is logged. Enable this for debugging.' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the status display (read-only).
	 */
	public function render_field_status(): void {
		$has_api_key = $this->has_credentials();
		$queue       = new PRAutoBlogger_Opik_Span_Queue();
		$queue_depth = $queue->get_queue_depth();
		$last_dispatch = get_option( 'prautoblogger_opik_last_dispatch', 0 );
		$last_dispatch_str = $last_dispatch
			? sprintf(
				/* translators: %s = human-readable time */
				__( '%s ago', 'prautoblogger' ),
				human_time_diff( (int) $last_dispatch )
			)
			: __( 'Never', 'prautoblogger' );

		?>
		<div style="background: #f1f1f1; padding: 10px; border-radius: 3px; max-width: 600px;">
			<p>
				<strong><?php esc_html_e( 'API key configured:', 'prautoblogger' ); ?></strong>
				<?php echo $has_api_key ? '<span style="color: green;">✓ Yes</span>' : '<span style="color: red;">✗ No</span>'; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Last dispatch:', 'prautoblogger' ); ?></strong>
				<?php echo esc_html( $last_dispatch_str ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Queue depth:', 'prautoblogger' ); ?></strong>
				<?php echo esc_html( (string) $queue_depth ); ?>
				<?php esc_html_e( 'item(s) pending', 'prautoblogger' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Check if Opik credentials are configured in wp-config.php.
	 *
	 * @return bool
	 */
	private function has_credentials(): bool {
		return defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) &&
			defined( 'PRAUTOBLOGGER_OPIK_WORKSPACE' ) &&
			! empty( PRAUTOBLOGGER_OPIK_API_KEY ) &&
			! empty( PRAUTOBLOGGER_OPIK_WORKSPACE );
	}
}
