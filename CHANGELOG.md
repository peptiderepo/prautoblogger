# Changelog

All notable changes to PRAutoBlogger will be documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **OpenRouter model registry (model-picker commit 1).** Foundation for the
  smart model picker: fetches, normalizes, and caches the OpenRouter model
  list (`/api/v1/models` — free, unauthenticated). Daily refresh via the
  existing `prautoblogger_daily_generation` cron hook with 12h idempotency.
  - `includes/services/interface-model-registry.php` — Phase 3-aware contract.
  - `includes/services/class-openrouter-model-registry.php` — fetch + cache + query.
  - `includes/services/class-openrouter-model-normalizer.php` — raw → standardized shape.
  - Capability vocabulary: `text→text`, `text+image→text`, `text→embedding`, etc.
  - Zero-coupling: no PRAUTOBLOGGER_* constants inside the class — Phase 2 lift
    into a shared Composer package requires only a namespace rename.
  - Cost impact: $0/month (free endpoint, no LLM tokens).

- **Image provider: Cloudflare Workers AI (FLUX.1 family).** First commit of
  the image + Instagram A/B pipeline workstream. Ships the provider, its
  pricing + validator helpers, four new settings fields in a new "Images"
  admin section, and unit tests with mocked HTTP. Nothing calls the provider
  yet from the article pipeline — integration lands in commit 1b.
  - `includes/providers/interface-image-provider.php` (adopted from the CTO's
    uncommitted draft) — contract for any image provider.
  - `includes/providers/class-cloudflare-image-provider.php` — FLUX on Workers
    AI, direct call to `/accounts/{id}/ai/run/...` (bypassing AI Gateway per
    decision D-001); exponential-backoff retries on 429 / 5xx / network,
    loud fail on 4xx; handles both raw-bytes and JSON-envelope response shapes.
  - `includes/providers/class-cloudflare-image-pricing.php` — model alias →
    full Workers AI id resolution + per-megapixel cost estimation.
  - `includes/providers/class-cloudflare-image-validator.php` — non-destructive
    "Test Connection" credential check that never generates a real image.
  - Settings: `prautoblogger_cloudflare_ai_token` (encrypted),
    `prautoblogger_cloudflare_account_id`, `prautoblogger_image_model`
    (schnell / dev), `prautoblogger_image_style_suffix` (default = CEO-locked
    90s infomercial prompt).
  - Constants: `PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL`,
    `PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX`.
  - Tests: `tests/unit/Providers/CloudflareImageProviderTest.php` covers
    happy path (raw bytes + JSON envelope), dimension + empty-prompt
    validation, 4xx loud-fail without retry, unexpected response shape,
    cost scaling across models and dimensions, and missing-token diagnostics.
- ARCHITECTURE.md: new key decision #16 (image pipeline: Cloudflare Workers
  AI), new external API integration row, new options rows, file tree updates.
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
