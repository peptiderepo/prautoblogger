<?php
declare(strict_types=1);

/**
 * Declarative settings fields and sections for the PRAutoBlogger admin page.
 *
 * Centralizes all field and section definitions in one place, making it trivial
 * to add new settings (just add one array entry). Decoupled from page rendering logic.
 *
 * Triggered by: PRAutoBlogger_Admin_Page::on_register_settings() calls static methods here.
 * Dependencies: None — contains only data definitions and static methods.
 *
 * @see admin/class-admin-page.php — Calls get_sections() and get_fields() to register settings.
 * @see CONVENTIONS.md             — "How To: Add a New Admin Setting".
 */
class PRAutoBlogger_Settings_Fields {

	/**
	 * Get all settings sections. Each section maps to a tab in the admin UI.
	 *
	 * @return array<string, array{title: string, icon: string, description: string}>
	 */
	public static function get_sections(): array {
		return [
			'prautoblogger_api' => [
				'title'       => __( 'API Keys', 'prautoblogger' ),
				'icon'        => 'dashicons-admin-network',
				'description' => __( 'Connect your external services. Keys are encrypted at rest.', 'prautoblogger' ),
			],
			'prautoblogger_models' => [
				'title'       => __( 'AI Models', 'prautoblogger' ),
				'icon'        => 'dashicons-superhero-alt',
				'description' => __( 'Choose which models power each stage of the pipeline.', 'prautoblogger' ),
			],
			'prautoblogger_content' => [
				'title'       => __( 'Content', 'prautoblogger' ),
				'icon'        => 'dashicons-edit-large',
				'description' => __( 'Control tone, length, pipeline mode, and topic guardrails.', 'prautoblogger' ),
			],
			'prautoblogger_sources' => [
				'title'       => __( 'Sources', 'prautoblogger' ),
				'icon'        => 'dashicons-rss',
				'description' => __( 'Configure where PRAutoBlogger finds trending topics.', 'prautoblogger' ),
			],
			'prautoblogger_schedule' => [
				'title'       => __( 'Schedule & Budget', 'prautoblogger' ),
				'icon'        => 'dashicons-calendar-alt',
				'description' => __( 'Set daily generation schedule, volume, and spending limits.', 'prautoblogger' ),
			],
			'prautoblogger_publishing' => [
				'title'       => __( 'Publishing', 'prautoblogger' ),
				'icon'        => 'dashicons-megaphone',
				'description' => __( 'Control how generated content is published.', 'prautoblogger' ),
			],
			'prautoblogger_analytics' => [
				'title'       => __( 'Analytics', 'prautoblogger' ),
				'icon'        => 'dashicons-chart-area',
				'description' => __( 'Connect Google Analytics 4 for post performance scoring.', 'prautoblogger' ),
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
				'id'          => 'prautoblogger_openrouter_api_key',
				'label'       => __( 'OpenRouter API Key', 'prautoblogger' ),
				'type'        => 'password',
				'section'     => 'prautoblogger_api',
				'description' => __( 'Get your key at openrouter.ai/keys', 'prautoblogger' ),
				'icon'        => '🔑',
			],
			[
				'id'          => 'prautoblogger_reddit_source_status',
				'label'       => __( 'Reddit Data Source', 'prautoblogger' ),
				'type'        => 'source_status',
				'section'     => 'prautoblogger_api',
				'description' => __( 'Uses PullPush.io (primary) and Reddit .json (fallback). No API key required.', 'prautoblogger' ),
				'icon'        => '📡',
			],

			// ── AI Models ───────────────────────────────────────────────
			[
				'id'          => 'prautoblogger_analysis_model',
				'label'       => __( 'Analysis Model', 'prautoblogger' ),
				'type'        => 'text',
				'section'     => 'prautoblogger_models',
				'default'     => PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL,
				'description' => __( 'Used for source analysis. Pick a cheap, fast model.', 'prautoblogger' ),
				'badge'       => __( 'Low cost', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_writing_model',
				'label'       => __( 'Writing Model', 'prautoblogger' ),
				'type'        => 'text',
				'section'     => 'prautoblogger_models',
				'default'     => PRAUTOBLOGGER_DEFAULT_WRITING_MODEL,
				'description' => __( 'Used for article generation. Quality matters here.', 'prautoblogger' ),
				'badge'       => __( 'Quality', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_editor_model',
				'label'       => __( 'Editor Model', 'prautoblogger' ),
				'type'        => 'text',
				'section'     => 'prautoblogger_models',
				'default'     => PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL,
				'description' => __( 'Used for the chief editor review pass.', 'prautoblogger' ),
				'badge'       => __( 'Quality', 'prautoblogger' ),
			],

			// ── Content ─────────────────────────────────────────────────
			[
				'id'          => 'prautoblogger_niche_description',
				'label'       => __( 'Niche Description', 'prautoblogger' ),
				'type'        => 'textarea',
				'section'     => 'prautoblogger_content',
				'description' => __( 'Describe your site\'s niche. Guides content analysis and generation.', 'prautoblogger' ),
			],
			[
				'id'      => 'prautoblogger_tone',
				'label'   => __( 'Content Tone', 'prautoblogger' ),
				'type'    => 'select',
				'section' => 'prautoblogger_content',
				'default' => 'informational',
				'options' => [
					'informational'  => __( 'Informational', 'prautoblogger' ),
					'conversational' => __( 'Conversational', 'prautoblogger' ),
					'professional'   => __( 'Professional', 'prautoblogger' ),
					'casual'         => __( 'Casual', 'prautoblogger' ),
					'authoritative'  => __( 'Authoritative', 'prautoblogger' ),
				],
			],
			[
				'id'      => 'prautoblogger_writing_pipeline',
				'label'   => __( 'Writing Pipeline', 'prautoblogger' ),
				'type'    => 'select',
				'section' => 'prautoblogger_content',
				'default' => 'multi_step',
				'options' => [
					'multi_step'  => __( 'Multi-step (outline → draft → polish)', 'prautoblogger' ),
					'single_pass' => __( 'Single-pass (one LLM call)', 'prautoblogger' ),
				],
			],
			[
				'id'      => 'prautoblogger_min_word_count',
				'label'   => __( 'Min Word Count', 'prautoblogger' ),
				'type'    => 'number',
				'section' => 'prautoblogger_content',
				'default' => 800,
				'min'     => 200,
			],
			[
				'id'      => 'prautoblogger_max_word_count',
				'label'   => __( 'Max Word Count', 'prautoblogger' ),
				'type'    => 'number',
				'section' => 'prautoblogger_content',
				'default' => 2000,
				'min'     => 500,
			],
			[
				'id'          => 'prautoblogger_topic_exclusions',
				'label'       => __( 'Topic Exclusions', 'prautoblogger' ),
				'type'        => 'textarea',
				'section'     => 'prautoblogger_content',
				'description' => __( 'Comma-separated topics to never write about.', 'prautoblogger' ),
			],

			// ── Sources ─────────────────────────────────────────────────
			[
				'id'          => 'prautoblogger_target_subreddits',
				'label'       => __( 'Target Subreddits', 'prautoblogger' ),
				'type'        => 'textarea',
				'section'     => 'prautoblogger_sources',
				'description' => __( 'Comma-separated, without r/. E.g.: peptides, Nootropics, biohackers', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_enabled_sources',
				'label'       => __( 'Enabled Sources', 'prautoblogger' ),
				'type'        => 'checkboxes',
				'section'     => 'prautoblogger_sources',
				'options'     => [
					'reddit'    => __( 'Reddit (via PullPush.io)', 'prautoblogger' ),
					'tiktok'    => __( 'TikTok (coming soon)', 'prautoblogger' ),
					'instagram' => __( 'Instagram (coming soon)', 'prautoblogger' ),
					'youtube'   => __( 'YouTube (coming soon)', 'prautoblogger' ),
				],
				'default'     => '["reddit"]',
				'description' => __( 'Select which platforms to monitor for topics.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_pullpush_cache_ttl',
				'label'       => __( 'Research Cache (hours)', 'prautoblogger' ),
				'type'        => 'number',
				'section'     => 'prautoblogger_sources',
				'default'     => 6,
				'min'         => 1,
				'max'         => 72,
				'description' => __( 'How long to cache PullPush research results before re-fetching. Saves API calls on low-traffic sites.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_reddit_time_filter',
				'label'       => __( 'Reddit Time Window', 'prautoblogger' ),
				'type'        => 'select',
				'section'     => 'prautoblogger_sources',
				'default'     => 'day',
				'options'     => [
					'day'   => __( 'Past 24 hours', 'prautoblogger' ),
					'week'  => __( 'Past week', 'prautoblogger' ),
					'month' => __( 'Past month', 'prautoblogger' ),
				],
				'description' => __( 'How far back to search for trending posts and comments.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_reddit_posts_per_subreddit',
				'label'       => __( 'Posts per Subreddit', 'prautoblogger' ),
				'type'        => 'number',
				'section'     => 'prautoblogger_sources',
				'default'     => 25,
				'min'         => 5,
				'max'         => 100,
				'description' => __( 'Maximum posts to fetch per subreddit per collection run.', 'prautoblogger' ),
			],

			// ── Schedule & Budget ───────────────────────────────────────
			[
				'id'      => 'prautoblogger_daily_article_target',
				'label'   => __( 'Articles per Day', 'prautoblogger' ),
				'type'    => 'number',
				'section' => 'prautoblogger_schedule',
				'default' => 1,
				'min'     => 1,
				'max'     => 10,
			],
			[
				'id'          => 'prautoblogger_schedule_time',
				'label'       => __( 'Generation Time', 'prautoblogger' ),
				'type'        => 'time',
				'section'     => 'prautoblogger_schedule',
				'default'     => '03:00',
				'description' => __( 'Daily generation runs at this time (server timezone).', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_monthly_budget_usd',
				'label'       => __( 'Monthly Budget (USD)', 'prautoblogger' ),
				'type'        => 'number',
				'section'     => 'prautoblogger_schedule',
				'default'     => 50,
				'min'         => 0,
				'step'        => '0.01',
				'description' => __( 'Hard stop when reached. 0 = unlimited.', 'prautoblogger' ),
			],

			// ── Publishing ──────────────────────────────────────────────
			[
				'id'          => 'prautoblogger_auto_publish',
				'label'       => __( 'Auto-Publish', 'prautoblogger' ),
				'type'        => 'toggle',
				'section'     => 'prautoblogger_publishing',
				'default'     => '0',
				'description' => __( 'Automatically publish editor-approved posts. When off, all posts go to the Review Queue as drafts.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_default_author',
				'label'       => __( 'Default Author', 'prautoblogger' ),
				'type'        => 'author_select',
				'section'     => 'prautoblogger_publishing',
				'default'     => 0,
				'description' => __( 'WordPress user assigned as author of generated posts.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_default_category',
				'label'       => __( 'Default Category', 'prautoblogger' ),
				'type'        => 'category_select',
				'section'     => 'prautoblogger_publishing',
				'default'     => 0,
				'description' => __( 'Fallback category for generated posts.', 'prautoblogger' ),
			],

			// ── Logging ─────────────────────────────────────────────────
			[
				'id'          => 'prautoblogger_log_level',
				'label'       => __( 'Log Level', 'prautoblogger' ),
				'type'        => 'select',
				'section'     => 'prautoblogger_publishing',
				'default'     => 'info',
				'options'     => [
					'error'   => __( 'Error — only failures', 'prautoblogger' ),
					'warning' => __( 'Warning — errors + warnings', 'prautoblogger' ),
					'info'    => __( 'Info — key events (recommended)', 'prautoblogger' ),
					'debug'   => __( 'Debug — everything (verbose)', 'prautoblogger' ),
				],
				'description' => __( 'Controls detail level in the Activity Log.', 'prautoblogger' ),
			],

			// ── Analytics ───────────────────────────────────────────────
			[
				'id'          => 'prautoblogger_ga4_property_id',
				'label'       => __( 'GA4 Property ID', 'prautoblogger' ),
				'type'        => 'text',
				'section'     => 'prautoblogger_analytics',
				'description' => __( 'Format: properties/XXXXXXXXX. Leave blank to skip GA4.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_ga4_credentials_json',
				'label'       => __( 'Service Account JSON', 'prautoblogger' ),
				'type'        => 'password',
				'section'     => 'prautoblogger_analytics',
				'description' => __( 'Paste the full JSON key file for a service account with Analytics read access.', 'prautoblogger' ),
			],
		];
	}
}
