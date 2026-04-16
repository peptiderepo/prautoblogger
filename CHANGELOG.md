# Changelog

All notable changes to PRAutoBlogger will be documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Image pipeline integration: wires image generation into article flow** (commit 1b).
  Completes the image + Instagram A/B experiment workstream by adding three
  orchestration classes that generate and sideload two images per published post.
  - `includes/core/class-image-pipeline.php` — Orchestrates A/B image generation:
    generates Image A (article-driven prompt) as featured image and Image B
    (source-driven prompt) stored in post meta `_prautoblogger_image_b_id`. Each
    image is independently fallible; article publishes even if both fail.
  - `includes/core/class-image-prompt-builder.php` — Synthesizes visual prompts
    from article title + first paragraph (for Image A) and Reddit thread title +
    top comment (for Image B). Both prompts append the CEO-locked 90s infomercial
    style suffix from settings. Prompts kept concise (under 200 words) for FLUX.1
    generation quality.
  - `includes/core/class-image-media-sideloader.php` — Downloads generated image
    bytes, creates temporary file, imports via `media_handle_sideload()`, sets alt
    text and generation metadata (`_prautoblogger_image_*`), cleans up temp files.
  - Settings: `prautoblogger_image_enabled` (toggle, default off) — allows users to
    enable/disable image generation after providing Cloudflare credentials.
  - `PRAutoBlogger_Publisher::attach_generated_images()` — New private method that
    runs the image pipeline post-creation and sets `_thumbnail_id` (Image A) and
    `_prautoblogger_image_b_id` (Image B) post meta. Errors are logged but do not
    block post publication.
  - Tests: `tests/unit/Core/ImagePipelineTest.php` (orchestration, cost tracking,
    graceful failure modes), `tests/unit/Core/ImagePromptBuilderTest.php` (prompt
    generation from various article/source shapes, length limits).
- ARCHITECTURE.md: added new image pipeline step (6b) to data flow, three new files
  to core/ section of file tree.

### Previous Commit (1a)

- **Image provider: Cloudflare Workers AI (FLUX.1 family).** Shipped the provider, its
  pricing + validator helpers, four new settings fields in a new "Images" admin section,
  and unit tests with mocked HTTP. Nothing called the provider from the article pipeline
  until this commit (1b).
  - `includes/providers/interface-image-provider.php`, `class-cloudflare-image-provider.php`,
    `class-cloudflare-image-pricing.php`, `class-cloudflare-image-validator.php`.
  - Settings: `prautoblogger_cloudflare_ai_token`, `prautoblogger_cloudflare_account_id`,
    `prautoblogger_image_model`, `prautoblogger_image_style_suffix`.
  - Constants: `PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL`, `PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX`.
  - Tests: `tests/unit/Providers/CloudflareImageProviderTest.php`.
- CONVENTIONS.md: new "How To: Add a New Image Provider" section.

## [0.2.2] — 2026-04-12

### Changed
- **CI/CD pipeline now runs PHPCS and PHPUnit** before deploying.
  Added `shivammathur/setup-php@v2` for PHP 8.1, Composer install,
  WordPress Coding Standards check, and full PHPUnit test suite.
  PHPCS runs in report-only mode (`|| true`) while codebase is
  brought into full compliance; PHPUnit failures block deploy.
- Removed stub source providers (TikTok, Instagram, YouTube).
  These were dead code — not registered in Source_Collector, threw
  RuntimeException if enabled, and confused AI agents reading the
  codebase. The `Source_Provider_Interface` remains the contract for
  adding future platforms (see CONVENTIONS.md).
- Removed "coming soon" checkboxes from Enabled Sources setting.
- Updated ARCHITECTURE.md: removed stub file references, added
  cross-system LLM budget coordination section documenting how
  PRAutoBlogger and Peptide News share an OpenRouter account.

### Improved
- **PublisherTest rewritten with real behavioral assertions.**
  Now tests: post_status ('publish' vs 'draft'), generation metadata
  storage, title from idea, taxonomy assignment, tag assignment,
  run_id-based log linking, RuntimeException on WP_Error, action/filter
  hook firing. Replaces previous method-existence-only checks.

## [0.2.1] — 2026-04-12

### Changed
- **Reddit data source switched to RSS primary, .json fallback.**
  PullPush.io was frequently stale/unavailable. Reddit RSS/Atom feeds work
  reliably from datacenter IPs (Hostinger) where .json gets 403. Comments
  are still fetched via .json (unavailable in RSS).
- Updated "Enabled Sources" checkbox label to "Reddit (RSS + .json)".
- Updated "Test Connections" to show RSS + .json status.
- Source status indicator now shows Reddit RSS (Primary) and .json (Fallback).

### Removed
- `class-pull-push-client.php` — replaced by RSS feeds in `class-reddit-json-client.php`.
- All PullPush.io references from admin UI, docs, and provider code.

### Fixed
- Generate Now button now always sends `force: '1'` to clear stale generation locks.
- Fixed encryption double-encryption bug with `enc:` prefix detection.

## [0.2.0] — 2026-04-12

### Changed
- **Reddit data source migrated from OAuth to RSS + .json (no auth required).**
  Reddit rejected our API application; RSS feeds and .json endpoints are free
  and require no authentication.
- Removed Reddit Client ID and Client Secret fields from admin API Keys tab.
- Updated "Test Connections" results to show Reddit source status.

### Added
- Source status indicator in API Keys tab showing RSS and .json
  availability with live status dots and last-collection timestamp.
- Configurable research cache TTL (1–72 hours) in Sources tab.
- Reddit time window selector (24h / week / month) in Sources tab.
- Posts-per-subreddit limit (5–100) in Sources tab.
- LiteSpeed cache purge step in CI/CD deploy pipeline.

### Removed
- `class-reddit-api-client.php` — replaced by `class-reddit-json-client.php`.
- Reddit OAuth credential fields and encrypted storage entry.

### Fixed
- Deploy pipeline now purges LiteSpeed/OPcache after file extraction,
  preventing stale PHP bytecode from being served.

## [0.1.0] — 2026-04-10

### Added
- Initial plugin scaffold with WordPress-native architecture.
- OpenRouter LLM provider with configurable model selection and retry logic.
- Reddit API source provider (OAuth2 script app) for collecting posts and comments.
- Content analysis engine: detects recurring questions, complaints, and comparisons.
- Idea scorer with deduplication against existing posts.
- Configurable writer agent pipeline (single-pass or multi-step: outline → draft → polish).
- Chief editor agent for LLM-powered editorial review before publishing.
- Publisher with full generation metadata stored as post_meta.
- Cost tracker with per-call logging, monthly budget enforcement, and hard-stop.
- Metrics collector: WordPress native + GA4 integration + composite content scoring.
- Admin settings page with sections for API keys, models, content, sources, schedule, and analytics.
- Metrics dashboard showing monthly spend, budget utilization, cost by stage, and daily spend.
- Post metabox showing generation metadata on PRAutoBlogger-generated posts.
- Admin notices for missing API keys, budget warnings, and configuration hints.
- Stub providers for TikTok, Instagram, and YouTube (future implementation).
- Encrypted storage for API keys using AES-256-CBC with wp_salt().
- AJAX-powered "Generate Now" and "Test Connections" buttons.
- Custom database tables for source data, analysis results, generation logs, and content scores.
- Clean uninstall handler removing all plugin data.
- ARCHITECTURE.md and CONVENTIONS.md for AI-readable codebase documentation.
