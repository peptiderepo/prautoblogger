<?php
declare(strict_types=1);

/**
 * Declarative settings fields and sections for the AutoBlogger admin page.
 *
 * Centralizes all field and section definitions in one place, making it trivial
 * to add new settings (just add one array entry). Decoupled from page rendering logic.
 *
 * Triggered by: Autoblogger_Admin_Page::on_register_settings() calls static methods here.
 * Dependencies: None — contains only data definitions and static methods.
 *
 * @see admin/class-admin-page.php — Calls get_sections() and get_fields() to register settings.
 * @see CONVENTIONS.md             — "How To: Add a New Admin Setting".
 */
class Autoblogger_Settings_Fields {

	/**
	 * Get all settings sections. Each section maps to a tab in the admin UI.
	 *
	 * @return array<string, array{title: string, icon: string, description: string}>
	 */
	public static function get_sections(): array {
		return [
			'autoblogger_api' => [
				'title'       => __( 'API Keys', 'autoblogger' ),
				'icon'        => 'dashicons-admin-network',
				'description' => __( 'Connect your external services. Keys are encrypted at rest.', 'autoblogger' ),
			],
			'autoblogger_models' => [
				'title'       => __( 'AI Models', 'autoblogger' ),
				'icon'        => 'dashicons-superhero-alt',
				'description' => __( 'Choose which models power each stage of the pipeline.', 'autoblogger' ),
			],
			'autoblogger_content' => [
				'title'       => __( 'Content', 'autoblogger' ),
				'icon'        => 'dashicons-edit-large',
				'description' => __( 'Control tone, length, pipeline mode, and topic guardrails.', 'autoblogger' ),
			],
			'autoblogger_sources' => [
				'title'       => __( 'Sources', 'autoblogger' ),
				'icon'        => 'dashicons-rss',
				'description' => __( 'Configure where AutoBlogger finds trending topics.', 'autoblogger' ),
			],
			'autoblogger_schedule' => [
				'title'       => __( 'Schedule & Budget', 'autoblogger' ),
				'icon'        => 'dashicons-calendar-alt',
				'description' => __( 'Set daily generation schedule, volume, and spending limits.', 'autoblogger' ),
			],
			'autoblogger_publishing' => [
				'title'       => __( 'Publishing', 'autoblogger' ),
				'icon'        => 'dashicons-megaphone',
				'description' => __( 'Control how generated content is published.', 'autoblogger' ),
			],
			'autoblogger_analytics' => [
				'title'       => __( 'Analytics', 'autoblogger' ),
				'icon'        => 'dashicons-chart-area',
				'description' => __( 'Connect Google Analytics 4 for post performance scoring.', 'autoblogger' ),
			],
		];
	}

	/**
	 * Get all settings fields. Adding a new setting = adding one entry to this array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_fields(): array {
		return [
			// ── API Keys ────────────────────────────────────────────────
			[
				'id'          => 'autoblogger_openrouter_api_key',
				'label'       => __( 'OpenRouter API Key', 'autoblogger' ),
				'type'        => 'password',
				'section'     => 'autoblogger_api',
				'description' => __( 'Get your key at openrouter.ai/keys', 'autoblogger' ),
				'icon'        => '🔑',
			],
			[
				'id'          => 'autoblogger_reddit_client_id',
				'label'       => __( 'Reddit Client ID', 'autoblogger' ),
				'type'        => 'text',
				'section'     => 'autoblogger_api',
				'description' => __( 'Create a script app at reddit.com/prefs/apps', 'autoblogger' ),
				'icon'        => '🤖',
			],
			[
				'id'      => 'autoblogger_reddit_client_secret',
				'label'   => __( 'Reddit Client Secret', 'autoblogger' ),
				'type'    => 'password',
				'section' => 'autoblogger_api',
			],

			// ── AI Models ───────────────────────────────────────────────
			[
				'id'          => 'autoblogger_analysis_model',
				'label'       => __( 'Analysis Model', 'autoblogger' ),
				'type'        => 'text',
				'section'     => 'autoblogger_models',
				'default'     => AUTOBLOGGER_DEFAULT_ANALYSIS_MODEL,
				'description' => __( 'Used for source analysis. Pick a cheap, fast model.', 'autoblogger' ),
				'badge'       => __( 'Low cost', 'autoblogger' ),
			],
			[
				'id'          => 'autoblogger_writing_model',
				'label'       => __( 'Writing Model', 'autoblogger' ),
				'type'        => 'text',
				'section'     => 'autoblogger_models',
				'default'     => AUTOBLOGGER_DEFAULT_WRITING_MODEL,
				'description' => __( 'Used for article generation. Quality matters here.', 'autoblogger' ),
				'badge'       => __( 'Quality', 'autoblogger' ),
			],
			[
				'id'          => 'autoblogger_editor_model',
				'label'       => __( 'Editor Model', 'autoblogger' ),
				'type'        => 'text',
				'section'     => 'autoblogger_models',
				'default'     => AUTOBLOGGER_DEFAULT_EDITOR_MODEL,
				'description' => __( 'Used for the chief editor review pass.', 'autoblogger' ),
				'badge'       => __( 'Quality', 'autoblogger' ),
			],

			// ── Content ─────────────────────────────────────────────────
			[
				'id'          => 'autoblogger_niche_description',
				'label'       => __( 'Niche Description', 'autoblogger' ),
				'type'        => 'textarea',
				'section'     => 'autoblogger_content',
				'description' => __( 'Describe your site\'s niche. Guides content analysis and generation.', 'autoblogger' ),
			],
			[
				'id'      => 'autoblogger_tone',
				'label'   => __( 'Content Tone', 'autoblogger' ),
				'type'    => 'select',
				'section' => 'autoblogger_content',
				'default' => 'informational',
				'options' => [
					'informational'  => __( 'Informational', 'autoblogger' ),
					'conversational' => __( 'Conversational', 'autoblogger' ),
					'professional'   => __( 'Professional', 'autoblogger' ),
					'casual'         => __( 'Casual', 'autoblogger' ),
					'authoritative'  => __( 'Authoritative', 'autoblogger' ),
				],
			],
			[
				'id'      => 'autoblogger_writing_pipeline',
				'label'   => __( 'Writing Pipeline', 'autoblogger' ),
				'type'    => 'select',
				'section' => 'autoblogger_content',
				'default' => 'multi_step',
				'options' => [
					'multi_step'  => __( 'Multi-step (outline → draft → polish)', 'autoblogger' ),
					'single_pass' => __( 'Single-pass (one LLM call)', 'autoblogger' ),
				],
			],
			[
				'id'      => 'autoblogger_min_word_count',
				'label'   => __( 'Min Word Count', 'autoblogger' ),
				'type'    => 'number',
				'section' => 'autoblogger_content',
				'default' => 800,
				'min'     => 200,
			],
			[
				'id'      => 'autoblogger_max_word_count',
				'label'   => __( 'Max Word Count', 'autoblogger' ),
				'type'    => 'number',
				'section' => 'autoblogger_content',
				'default' => 2000,
				'min'     => 500,
			],
			[
				'id'          => 'autoblogger_topic_exclusions',
				'label'       => __( 'Topic Exclusions', 'autoblogger' ),
				'type'        => 'textarea',
				'section'     => 'autoblogger_content',
				'description' => __( 'Comma-separated topics to never write about.', 'autoblogger' ),
			],

			// ── Sources ─────────────────────────────────────────────────
			[
				'id'          => 'autoblogger_target_subreddits',
				'label'       => __( 'Target Subreddits', 'autoblogger' ),
				'type'        => 'textarea',
				'section'     => 'autoblogger_sources',
				'description' => __( 'Comma-separated, without r/. E.g.: peptides, Nootropics, biohackers', 'autoblogger' ),
			],
			[
				'id'          => 'autoblogger_enabled_sources',
				'label'       => __( 'Enabled Sources', 'autoblogger' ),
				'type'        => 'checkboxes',
				'section'     => 'autoblogger_sources',
				'options'     => [
					'reddit'    => __( 'Reddit', 'autoblogger' ),
					'tiktok'    => __( 'TikTok (coming soon)', 'autoblogger' ),
					'instagram' => __( 'Instagram (coming soon)', 'autoblogger' ),
					'youtube'   => __( 'YouTube (coming soon)', 'autoblogger' ),
				],
				'default'     => '["reddit"]',
				'description' => __( 'Select which platforms to monitor for topics.', 'autoblogger' ),
			],

			// ── Schedule & Budget ───────────────────────────────────────
			[
				'id'      => 'autoblogger_daily_article_target',
				'label'   => __( 'Articles per Day', 'autoblogger' ),
				'type'    => 'number',
				'section' => 'autoblogger_schedule',
				'default' => 1,
				'min'     => 1,
				'max'     => 10,
			],
			[
				'id'          => 'autoblogger_schedule_time',
				'label'       => __( 'Generation Time', 'autoblogger' ),
				'type'        => 'time',
				'section'     => 'autoblogger_schedule',
				'default'     => '03:00',
				'description' => __( 'Daily generation runs at this time (server timezone).', 'autoblogger' ),
			],
			[
				'id'          => 'autoblogger_monthly_budget_usd',
				'label'       => __( 'Monthly Budget (USD)', 'autoblogger' ),
				'type'        => 'number',
				'section'     => 'autoblogger_schedule',
				'default'     => 50,
				'min'         => 0,
				'step'        => '0.01',
				'description' => __( 'Hard stop when reached. 0 = unlimited.', 'autoblogger' ),
			],

			// ── Publishing ──────────────────────────────────────────────
			[
				'id'          => 'autoblogger_auto_publish',
				'label'       => __( 'Auto-Publish', 'autoblogger' ),
				'type'        => 'toggle',
				'section'     => 'autoblogger_publishing',
				'default'     => '0',
				'description' => __( 'Automatically publish editor-approved posts. When off, all posts go to the Review Queue as drafts.', 'autoblogger' ),
			],
			[
				'id'          => 'autoblogger_default_author',
				'label'       => __( 'Default Author', 'autoblogger' ),
				'type'        => 'author_select',
				'section'     => 'autoblogger_publishing',
				'default'     => 0,
				'description' => __( 'WordPress user assigned as author of generated posts.', 'autoblogger' ),
			],
			[
				'id'          => 'autoblogger_default_category',
				'label'       => __( 'Default Category', 'autoblogger' ),
				'type'        => 'category_select',
				'section'     => 'autoblogger_publishing',
				'default'     => 0,
				'description' => __( 'Fallback category for generated posts.', 'autoblogger' ),
			],

			// ── Logging ─────────────────────────────────────────────────
			[
				'id'          => 'autoblogger_log_level',
				'label'       => __( 'Log Level', 'autoblogger' ),
				'type'        => 'select',
				'section'     => 'autoblogger_publishing',
				'default'     => 'info',
				'options'     => [
					'error'   => __( 'Error — only failures', 'autoblogger' ),
					'warning' => __( 'Warning — errors + warnings', 'autoblogger' ),
					'info'    => __( 'Info — key events (recommended)', 'autoblogger' ),
					'debug'   => __( 'Debug — everything (verbose)', 'autoblogger' ),
				],
				'description' => __( 'Controls detail level in the Activity Log.', 'autoblogger' ),
			],

			// ── Analytics ───────────────────────────────────────────────
			[
				'id'          => 'autoblogger_ga4_property_id',
				'label'       => __( 'GA4 Property ID', 'autoblogger' ),
				'type'        => 'text',
				'section'     => 'autoblogger_analytics',
				'description' => __( 'Format: properties/XXXXXXXXX. Leave blank to skip GA4.', 'autoblogger' ),
			],
			[
				'id'          => 'autoblogger_ga4_credentials_json',
				'label'       => __( 'Service Account JSON', 'autoblogger' ),
				'type'        => 'password',
				'section'     => 'autoblogger_analytics',
				'description' => __( 'Paste the full JSON key file for a service account with Analytics read access.', 'autoblogger' ),
			],
		];
	}
}
