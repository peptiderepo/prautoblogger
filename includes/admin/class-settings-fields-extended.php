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
	 * Static image model list for the admin model picker.
	 * No registry API for these — they're hardcoded here and updated manually.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_image_models(): array {
		return [
			[
				'id'             => 'google/gemini-2.5-flash-image',
				'name'           => 'Gemini 2.5 Flash Image (Nano Banana)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.005,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'Good quality, low cost. Recommended.', 'prautoblogger' ),
			],
			[
				'id'             => 'google/gemini-3.1-flash-image-preview',
				'name'           => 'Gemini 3.1 Flash Image (Nano Banana 2)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.008,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'Latest Google. Better quality.', 'prautoblogger' ),
			],
			[
				'id'             => 'google/gemini-3-pro-image-preview',
				'name'           => 'Gemini 3 Pro Image (Nano Banana Pro)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.03,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'Highest quality Google. Premium.', 'prautoblogger' ),
			],
			[
				'id'             => 'openai/gpt-5-image-mini',
				'name'           => 'GPT-5 Image Mini',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.02,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'OpenAI budget image model.', 'prautoblogger' ),
			],
			[
				'id'             => 'openai/gpt-5-image',
				'name'           => 'GPT-5 Image',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.08,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'OpenAI premium. Photorealistic.', 'prautoblogger' ),
			],
			[
				'id'             => 'flux-1-schnell',
				'name'           => 'FLUX.1 schnell (Cloudflare)',
				'provider'       => 'cloudflare',
				'cost_per_image' => 0.0007,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'Cheapest. Low quality, 4-step.', 'prautoblogger' ),
			],
		];
	}

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
				'description' => __( 'Generate images for each published article.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_image_b_enabled',
				'label'       => __( 'Generate Second Image (B)', 'prautoblogger' ),
				'type'        => 'toggle',
				'section'     => 'prautoblogger_images',
				'default'     => '1',
				'description' => __( 'Generate a second image from source data for A/B testing. Disabling saves one image generation + one LLM prompt rewrite per article.', 'prautoblogger' ),
			],
			[
				'id'      => 'prautoblogger_image_provider',
				'label'   => __( 'Image Provider', 'prautoblogger' ),
				'type'    => 'select',
				'section' => 'prautoblogger_images',
				'default' => 'openrouter',
				'options' => [
					'openrouter' => __( 'OpenRouter (multiple models available)', 'prautoblogger' ),
					'cloudflare' => __( 'Cloudflare Workers AI', 'prautoblogger' ),
				],
				'description' => __( 'OpenRouter reuses your existing API key and offers higher-quality models. Cloudflare is cheaper but lower quality.', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_image_model',
				'label'       => __( 'Image Model', 'prautoblogger' ),
				'type'        => 'model_select',
				'section'     => 'prautoblogger_images',
				'default'     => PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL,
				'capability'  => 'image_generation',
				'description' => __( 'Pick a model from your selected provider.', 'prautoblogger' ),
				'badge'       => __( 'Quality', 'prautoblogger' ),
			],
			[
				'id'          => 'prautoblogger_cloudflare_ai_token',
				'label'       => __( 'Cloudflare API Token', 'prautoblogger' ),
				'type'        => 'password',
				'section'     => 'prautoblogger_images',
				'description' => __( 'Only needed if using Cloudflare provider. Workers AI token with Read + Edit scope.', 'prautoblogger' ),
				'icon'        => '🔑',
			],
			[
				'id'          => 'prautoblogger_cloudflare_account_id',
				'label'       => __( 'Cloudflare Account ID', 'prautoblogger' ),
				'type'        => 'text',
				'section'     => 'prautoblogger_images',
				'default'     => '',
				'description' => __( 'Only needed if using Cloudflare provider.', 'prautoblogger' ),
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
