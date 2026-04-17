<?php
declare(strict_types=1);

/**
 * Extended settings fields: schedule, publishing, analytics, and images.
 *
 * What: Declarative field definitions for operational settings sections.
 * Who calls it: PRAutoBlogger_Settings_Fields::get_fields() merges these in.
 * Dependencies: PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL, PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX constants.
 *
 * @see admin/class-settings-fields.php — Core fields and sections; calls get_extended_fields().
 * @see admin/class-admin-page.php       — Renders fields registered from these definitions.
 * @see CONVENTIONS.md                   — "How To: Add a New Admin Setting".
 */
class PRAutoBlogger_Settings_Fields_Extended {

	/**
	 * Get schedule, publishing, analytics, and image settings fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_fields(): array {
		return [
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

			// ── Images ─────────────────────────────────────────────────
			[
				'id'          => 'prautoblogger_image_enabled',
				'label'       => __( 'Enable Image Generation', 'prautoblogger' ),
				'type'        => 'toggle',
				'section'     => 'prautoblogger_images',
				'default'     => '0',
				'description' => __( 'When enabled, generate two images (A/B) for each published article. Requires Cloudflare credentials.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_cloudflare_ai_token',
				'label'       => __( 'Cloudflare API Token', 'prautoblogger' ),
				'type'        => 'password',
				'section'     => 'prautoblogger_images',
				'description' => __( 'Workers AI token with Read + Edit scope. Create at dash.cloudflare.com → AI → API Tokens.', 'prautoblogger' ),
				'icon'        => '🔑',
			],
			[
				'id'          => 'prautoblogger_cloudflare_account_id',
				'label'       => __( 'Cloudflare Account ID', 'prautoblogger' ),
				'type'        => 'text',
				'section'     => 'prautoblogger_images',
				'default'     => '',
				'description' => __( 'The Account ID shown on your Cloudflare dashboard sidebar.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_image_model',
				'label'       => __( 'Image Model', 'prautoblogger' ),
				'type'        => 'model_select',
				'section'     => 'prautoblogger_images',
				'default'     => PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL,
				'capability'  => 'cloudflare_image',
				'description' => __( 'Schnell is the normal choice. Switch to [dev] for higher quality.', 'prautoblogger' ),
				'badge'       => __( 'Low cost', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_image_style_suffix',
				'label'       => __( 'Style Suffix', 'prautoblogger' ),
				'type'        => 'textarea',
				'section'     => 'prautoblogger_images',
				'default'     => PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX,
				'description' => __( 'Appended to every image prompt. Controls visual look. Changing mid-run causes visible style drift.', 'prautoblogger' ),
			],
		];
	}
}
