# Changelog

All notable changes to PRAutoBlogger will be documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [0.6.1] — 2026-04-20

### Added
- **Peptide auto-linking.** When any peptide name from the PR Core database is
  mentioned in a generated article, the first mention is automatically linked to
  the corresponding `/peptides/{slug}/` page. The system prompt now includes a
  complete peptide database reference list alongside the existing article links.
  Gracefully degrades if PR Core is not active (`post_type_exists()` guard).

### Changed
- **Extracted prompt builders into Content_Prompts class.** Moved all prompt
  construction (system prompt, stage prompts, linking rules) from
  `Content_Generator` into a new `Content_Prompts` static helper. Generator
  dropped from 324 → 162 lines; prompts class is 252 lines. Both under 300.
- **Internal link rules strengthened.** Linking rules section now explicitly
  instructs the model to link every peptide's first mention and never fabricate
  URLs, with real published article and peptide page URLs provided.

## [0.6.0] — 2026-04-20

### Added
- **Generate article from idea.** New "Generate" column in the Ideas browser.
  Click any idea's Generate button to produce an article directly, bypassing
  the scheduled pipeline's collect → analyze → score steps. The button shows
  real-time stage updates (drafting, editing, publishing) via per-idea status
  polling, then swaps to Edit/View links with cost on completion.
  - `Ideas_Browser::on_ajax_generate_from_idea()` — AJAX trigger that stores
    the idea and schedules a one-shot WP-Cron event.
  - `Ideas_Browser::on_cron_generate_from_idea()` — Background handler that
    runs Article_Worker for the selected idea.
  - `Ideas_Browser::on_ajax_idea_gen_status()` — Per-idea status polling.
  - `assets/js/ideas-browser.js` — Button click, AJAX, polling, and UI state.
  - Per-idea transients (`prab_idea_gen_{id}`) for independent status tracking.
  - Page-load polling resume for ideas that were mid-generation.
  - Generation lock respected — only one generation at a time.

## [0.5.3] — 2026-04-20

### Fixed
- **Writing instructions not obeyed by LLM.** The user-configured writing
  instructions (bullet points, hyperlinks, style rules) were appended to the
  system prompt as a weak "Additional instructions:" afterthought. Models
  prioritized competing format directives in the user prompts and ignored
  the style guide.
  - System prompt now frames writing instructions as a "MANDATORY STYLE GUIDE"
    with explicit override language.
  - Every stage's user prompt (outline, draft, single-pass) now includes a
    reinforcement line: "Follow EVERY requirement from your system prompt
    style guide."
  - Polish stage explicitly preserves bullet points, numbered lists, and
    hyperlinks instead of flattening them.
  - Chief editor's revision rules now preserve structural formatting (lists,
    links) from the original draft.

## [0.5.2] — 2026-04-20

### Added
- **LLM research cost amortization.** The research LLM call (which runs once
  per pipeline execution) is now divided evenly across all articles produced
  in that run. Each article's cost breakdown popover shows its amortized share
  of the research overhead. Previously, research costs were orphaned with no
  post attribution.
  - `Post_Assembler::amortize_research_costs()` — Queries the unlinked
    research log entry, divides cost and tokens by article count, inserts one
    row per article, and removes the original.
  - `link_generation_logs()` now excludes `llm_research` stage from per-article
    linking so the cost isn't grabbed wholesale by the first article.
  - `Source_Collector::set_cost_tracker()` — Accepts the pipeline's cost
    tracker so research costs are tagged with the pipeline's `run_id`.
  - `LLM_Research_Provider` now uses the pipeline's cost tracker when available,
    ensuring research log entries share the same `run_id` as article entries.
  - Amortization runs after all articles complete (both single-article and
    chained-job paths in `Pipeline_Runner`).

## [0.5.1] — 2026-04-20

### Added
- **Ideas browser admin page.** New "Ideas" submenu under PRAutoBlogger showing
  all article ideas collected by the content analyzer. Displays suggested title,
  topic, type (question/complaint/comparison/news/guide), relevance score bar,
  frequency, key points, and target keywords. Filterable by type with count
  badges. Paginated at 30 per page, newest first.

## [0.5.0] — 2026-04-20

### Added
- **LLM Deep Research source provider.** New data source that sends a configurable
  research prompt to a reasoning-capable model (e.g. Grok 4.1 Fast, DeepSeek-R1)
  to identify trending topics, emerging questions, misconceptions, and content gaps.
  Findings feed into the content analyzer alongside Reddit data, giving the pipeline
  both real-time community signals AND deep knowledge-base research.
  - `includes/providers/class-llm-research-provider.php` — Source provider implementation.
  - New admin settings in Sources tab:
    - "LLM Deep Research" checkbox in Enabled Sources.
    - "Research Model" model picker — pick a reasoning-capable model.
    - "Research Prompt" textarea — customizable research brief with `{niche}` placeholder.
  - Each research run produces 8-12 findings stored as `llm_research` source data.
  - Deduplication via date-based source IDs (one fresh run per day, refreshes on re-run).
  - Cost tracked as `llm_research` stage in generation logs.
- Content analyzer now formats LLM research findings with a cleaner label
  (`[LLM Research] Relevance: N`) instead of the Reddit-specific `r/subreddit` format.

## [0.4.9] — 2026-04-19

### Added
- **OpenRouter reasoning mode support.** New "Enable Reasoning" toggle and
  "Reasoning Effort" selector in AI Models settings. When enabled, sends
  `reasoning: {enabled: true, effort: "<level>"}` to models that support it
  (Grok 4.1 Fast, DeepSeek-R1, etc.). Effort levels: Extra High, High,
  Medium, Low, Minimal. Reasoning tokens are billed as output tokens — cost
  is automatically tracked. Models that don't support reasoning ignore the
  parameter. Per-call override available via the `reasoning` key in the
  provider options array.
- Response parser now captures `reasoning_tokens` and `reasoning_content`
  from the OpenRouter response for downstream logging and debugging.

## [0.4.8] — 2026-04-19

### Fixed
- **GLM 4.7 Flash timeout during analysis.** The model was timing out at 120s on
  large analysis prompts (especially with the new custom instructions). Bumped
  API timeout from 120s to 180s.
- **Empty raw response in error logs.** Parse failure errors now include the raw
  LLM response inline (first 500 chars) at error level, not debug level, so
  the actual failure reason is always visible in the Activity Log.

## [0.4.7] — 2026-04-19

### Added
- **Cost breakdown popover.** The Cost column in the post list is now clickable.
  Hovering or clicking shows a per-step breakdown: stage name, model used, token
  count, and cost for every LLM call that produced the article.

## [0.4.6] — 2026-04-19

### Added
- **Image B toggle.** New "Generate Second Image (B)" toggle in Images settings.
  Disabling saves one image generation call + one LLM prompt rewrite per article.
  Defaults to enabled for backwards compatibility.

## [0.4.5] — 2026-04-19

### Added
- **Analysis Instructions setting.** Custom instructions appended to the analysis
  LLM's system prompt. Steers how source data is evaluated and which topic ideas
  get surfaced.
- **Editor Instructions setting.** Custom instructions appended to the chief
  editor's system prompt. Controls what the editorial review looks for and how
  it decides to approve, revise, or reject articles.

## [0.4.4] — 2026-04-19

### Added
- **Writing Instructions setting.** New textarea in Content settings lets you
  provide custom instructions appended to the LLM's system prompt when writing
  articles. Use it to steer style, structure, voice, and formatting without
  editing code.

## [0.4.3] — 2026-04-19

### Fixed
- **"Analysis response was not valid JSON" with some models.** Models like
  GPT 5.1 Nano ignore `response_format: json_object` and wrap JSON in markdown
  fences or preamble text. New `PRAutoBlogger_Json_Extractor` utility strips
  fences and extracts the outermost JSON object. Applied to both the content
  analyzer and chief editor parsers. Raw response now logged on failure for
  debugging.

## [0.4.2] — 2026-04-19

### Fixed
- **Posts list Title column unreadable.** Title was compressed to ~30px by the
  three custom columns. Title now gets 35% minimum width with word-wrap enabled.
  Model columns trimmed to 100px with text-overflow ellipsis for long model names.

## [0.4.1] — 2026-04-19

### Added
- **Posts list columns: Writing Model, Image Model, Cost.** Three new columns
  on the WordPress Posts admin page. Writing Model shows the LLM that wrote the
  article, Image Model shows the model that generated the featured image, and
  Cost shows the total API spend for generating that article (summed from the
  generation log). All columns show "—" for non-generated posts. Writing Model
  is sortable. Model names are shortened (provider prefix stripped) with full
  ID in a tooltip.

## [0.4.0] — 2026-04-19

### Added
- **Semantic dedup via embedding cosine similarity.** Replaces keyword-overlap
  dedup (60% word match) with MiniLM-L12-v2 embeddings via OpenRouter. Catches
  rephrasings like "BPC-157 dosing" vs "how much BPC-157 to take" that keyword
  matching misses. Automatic fallback to keywords if embedding API unavailable.
  Cost: ~$0.00001 per generation run.
  - `includes/core/class-semantic-dedup.php` — Dedup engine with embedding +
    keyword fallback.
  - `includes/providers/class-open-router-embedding-provider.php` — OpenRouter
    `/embeddings` client with batch support and cosine similarity helper.

- **LLM-aware topic avoidance.** Analysis prompt now includes the last 30 days
  of published article titles with explicit "do not suggest similar topics"
  instruction. Zero additional cost — appended to the existing analysis call.

### Changed
- Dedup window widened from 7 days to 30 days (semantic similarity is precise
  enough to avoid over-blocking).
- `class-idea-scorer.php` refactored to delegate dedup to `Semantic_Dedup`.

### Fixed
- Featured images displayed at 300×300 (WordPress "medium" size) instead of full
  width. Changed theme to use `the_post_thumbnail('full')` with responsive CSS.
- Race condition causing duplicate article generation when the AJAX status poller
  re-scheduled a cron event for an article already being generated.
- False stall detection on multi-article runs (measured elapsed time from start
  instead of idle time since last progress update).

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

- **OpenRouter model registry (model-picker commit 1).** Foundation for the
  smart model picker: fetches, normalizes, and caches the OpenRouter model
  list (`/api/v1/models` — free, unauthenticated). Daily refresh via the
  existing `prautoblogger_daily_generation` cron hook with 12h idempotency.
  - `includes/services/interface-model-registry.php` — Phase 3-aware contract.
  - `includes/services/class-open-router-model-registry.php` — fetch + cache + query.
  - `includes/services/class-open-router-model-normalizer.php` — raw → standardized shape.
  - Capability vocabulary: `text→text`, `text+image→text`, `text→embedding`, etc.
  - Zero-coupling: no PRAUTOBLOGGER_* constants inside the class — Phase 2 lift
    into a shared Composer package requires only a namespace rename.
  - Cost impact: $0/month (free endpoint, no LLM tokens).

- **Image provider: Cloudflare Workers AI (FLUX.1 family).** Ships the provider, its
  pricing + validator helpers, four new settings fields in a new "Images"
  admin section, and unit tests with mocked HTTP.
  - `includes/providers/interface-image-provider.php` — contract for any image provider.
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
  - Tests: `tests/unit/Providers/CloudflareImageProviderTest.php`.
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
