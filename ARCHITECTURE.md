# PRAutoBlogger — Architecture

> **Cross-app context:** decisions that affect multiple plugins (Cloudflare AI Gateway routing, OpenRouter account sharing, the interface pattern, image-generation stack, social distributor choice) are recorded in `Peptide Repo CTO/docs/engineering/decisions/`. The incident runbook for cross-app failure modes is at `Peptide Repo CTO/docs/engineering/INCIDENT-RUNBOOK.md`. Engineer PRAutoBlogger should read both before making decisions that cross plugin boundaries.

PRAutoBlogger is a WordPress plugin that monitors social media (starting with Reddit) for recurring questions, complaints, and comparisons in a configured niche, uses LLM agents (via OpenRouter) to generate high-quality blog articles from those insights, runs them through a chief editor agent for quality review, and auto-publishes them on a configurable daily schedule. All collected data and generation metrics are stored for a self-improvement feedback loop.

---

## Data Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    WP-Cron / Action Scheduler                          │
│                    (Daily trigger, configurable)                        │
└────────────┬────────────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────┐
│  1. Source Collector     │  Pulls raw posts/comments from social platforms
│  (Reddit RSS primary    │  Reddit RSS/Atom feeds (primary) / .json (fallback)
│   / .json fallback)        Stores raw data in `ab_source_data` table
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  2. Content Analyzer    │  LLM call (cheap model) to detect:
│  (Analysis Agent)       │  - Recurring questions
│                         │  - Complaints / pain points
│                         │  - Product comparisons
│                         │  Stores analysis in `ab_analysis_results` table
│                         │  Outputs ranked article ideas
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  3. Idea Scorer &       │  Deduplicates against existing posts
│     Deduplicator        │  Scores ideas by relevance, frequency, freshness
│                         │  Picks top N ideas (N = daily article target)
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  4. Writer Agent        │  Configurable pipeline:
│  (Content Generator)    │  Single-pass: one LLM call → complete article
│                         │  Multi-step: outline → draft → polish
│                         │  Uses quality model via OpenRouter
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  5. Chief Editor Agent  │  LLM-powered editorial review:
│                         │  - Checks quality, accuracy, tone
│                         │  - Checks SEO (title, headings, keyword density)
│                         │  - Can request rewrites or approve
│                         │  - Approved → publish; Rejected → flag for human
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  6. Publisher           │  Creates/updates WordPress post
│                         │  Sets categories, tags, featured image
│                         │  Stores generation metadata in post_meta
│                         │  Logs cost/tokens in `prab_generation_log` table
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  6b. Image Pipeline     │  (commit 1b) Generates two A/B images:
│  (optional, if enabled) │  - Image A: article-driven (featured)
│                         │  - Image B: source-driven (post meta)
│                         │  Sideloads to media library, logs costs
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  7. Metrics Collector   │  Tracks post-publish performance:
│  (Separate cron job)    │  - WordPress native (views, comments)
│                         │  - GA4 (pageviews, bounce rate, time on page)
│                         │  - Composite "content score"
│                         │  Feeds back into Analysis step for self-improvement
└─────────────────────────┘
```

---

## File Tree

```
prautoblogger/
├── prautoblogger.php                  # Plugin bootstrap — minimal, only hook registration
├── uninstall.php                      # Clean removal of ALL plugin data
├── readme.txt                         # WordPress.org standard readme
├── composer.json                      # Autoloading (PSR-4) and dependencies
├── ARCHITECTURE.md                    # This file
├── CONVENTIONS.md                     # Naming patterns, extension guides
├── CHANGELOG.md                       # Semantic versioning changelog
│
├── assets/
│   ├── css/
│   │   ├── admin.css                  # Admin page styles (wp-admin conventions)
│   │   └── posts-widget.css           # Frontend posts widget styles (uses theme CSS vars)
│   └── js/
│       ├── admin.js                   # Admin page interactivity (vanilla JS / Alpine.js)
│       └── posts-widget.js            # React component for frontend post cards (wp.element)
│
├── includes/
│   ├── class-prautoblogger.php        # Main orchestrator — registers all hooks via loader
│   ├── class-activator.php            # Activation: create DB tables, set defaults, schedule cron
│   ├── class-deactivator.php          # Deactivation: clear cron, cleanup transients
│   ├── class-autoloader.php           # PSR-4-style autoloader for plugin classes
│   │
│   ├── admin/
│   │   ├── class-admin-page.php       # Main settings page (tabbed SaaS-style UI)
│   │   ├── class-settings-fields.php  # Declarative settings definitions (sections + fields)
│   │   ├── class-admin-notices.php    # Onboarding notices, error alerts, budget warnings
│   │   ├── class-dashboard-widget.php # WP Dashboard widget showing generation status
│   │   ├── class-post-metabox.php     # Metabox on posts showing generation metadata
│   │   ├── class-metrics-page.php     # Admin page for cost dashboard and content scores
│   │   ├── class-review-queue.php     # Approve/edit/reject queue for generated drafts
│   │   └── class-log-viewer.php       # Activity log viewer with level filtering
│   │
│   ├── core/
│   │   ├── class-scheduler.php        # WP-Cron / Action Scheduler job management
│   │   ├── class-source-collector.php # Orchestrates data collection from all sources
│   │   ├── class-content-analyzer.php # LLM-powered analysis of collected social data
│   │   ├── class-idea-scorer.php      # Ranks and deduplicates article ideas
│   │   ├── class-content-generator.php# Writer agent — manages the generation pipeline
│   │   ├── class-chief-editor.php     # Editor agent — LLM-powered editorial review
│   │   ├── class-publisher.php        # Creates WordPress posts from approved content
│   │   ├── class-image-pipeline.php   # Orchestrates A/B image generation (commit 1b)
│   │   ├── class-image-prompt-builder.php # Generates visual prompts from article/source data
│   │   ├── class-image-media-sideloader.php # Imports images into WordPress media library
│   │   ├── class-cost-tracker.php     # Logs all API costs, enforces budget limits
│   │   ├── class-logger.php           # Structured logging singleton (error/warning/info/debug)
│   │   ├── class-pipeline-runner.php  # Orchestrates the full generation pipeline
│   │   ├── class-ga4-client.php       # Google Analytics 4 API client (OAuth + Data API)
│   │   └── class-metrics-collector.php# Collects post performance data (WP + GA4)
│   │
│   ├── providers/
│   │   ├── interface-llm-provider.php    # Contract for any LLM provider
│   │   ├── class-open-router-provider.php # OpenRouter API implementation
│   │   ├── class-open-router-pricing.php  # Model pricing lookup and cost estimation
│   │   ├── interface-source-provider.php # Contract for any social media source
│   │   ├── class-reddit-json-client.php  # Reddit HTTP client — RSS (primary) + .json (fallback)
│   │   ├── class-reddit-provider.php     # Reddit data collection orchestrator (RSS primary)
│   │   ├── interface-image-provider.php  # Contract for any image generation provider
│   │   ├── class-cloudflare-image-provider.php  # FLUX.1 via Cloudflare Workers AI
│   │   ├── class-cloudflare-image-pricing.php   # Model alias + per-MP cost estimation
│   │   ├── class-cloudflare-image-validator.php # Non-destructive credential + connectivity check
│   │   ├── class-cloudflare-image-support.php   # Token, account, URL, and log helpers for the CF provider
│   │   └── (new providers go here — see CONVENTIONS.md)
│   │
│   ├── frontend/
│   │   └── class-posts-widget.php     # [prautoblogger_posts] shortcode + REST endpoint
│   │
│   └── models/
│       ├── class-source-data.php      # Value object: raw social media post/comment
│       ├── class-analysis-result.php  # Value object: analyzed topic with scores
│       ├── class-article-idea.php     # Value object: scored article idea
│       ├── class-content-request.php  # Value object: generation request with params
│       ├── class-editorial-review.php # Value object: editor verdict, scores, revised content
│       ├── class-generation-log.php   # Value object: log entry for a generation run
│       └── class-content-score.php    # Value object: composite performance score
│
├── templates/
│   └── admin/
│       ├── settings-page.php          # Settings page template (tabbed sidebar layout)
│       ├── metrics-page.php           # Metrics/cost dashboard template
│       ├── review-queue.php           # Review queue table template
│       ├── log-viewer.php             # Activity log viewer template
│       └── metabox-generation-info.php# Post metabox template
│
├── languages/
│   └── prautoblogger.pot              # i18n translation template
│
└── tests/
    ├── unit/                          # PHPUnit tests (mocked dependencies)
    └── integration/                   # WordPress integration tests
```

---

## Frontend Widget Data Flow

```
┌──────────────────────────────┐
│  WordPress Page/Post         │  Contains [prautoblogger_posts] shortcode
│  (e.g. Home page)            │
└────────────┬─────────────────┘
             │  Shortcode renders:
             │  1. <div id="prab-posts-root"></div>
             │  2. Enqueues posts-widget.js + posts-widget.css
             │  3. Passes config via wp_localize_script
             ▼
┌──────────────────────────────┐
│  React Component (wp.element)│  Mounts into #prab-posts-root
│  posts-widget.js             │  Shows loading skeleton on mount
└────────────┬─────────────────┘
             │  Fetches asynchronously
             ▼
┌──────────────────────────────┐
│  REST API Endpoint           │  GET /wp-json/prautoblogger/v1/posts
│  class-posts-widget.php      │  Queries published posts with
│                              │  _prautoblogger_generated = '1' meta
│                              │  Returns slim JSON (id, title, excerpt,
│                              │  url, date, category, image, word_count)
│                              │  5-minute Cache-Control header
└──────────────────────────────┘
```

**Shortcode Attributes**

| Attribute  | Default                          | Description             |
|------------|----------------------------------|-------------------------|
| `count`    | `6`                              | Posts to display (max 12) |
| `category` | (all)                            | Filter by category slug |
| `title`    | `"Latest Research & Insights"`   | Widget heading          |
| `subtitle` | `"Evidence-based articles..."`   | Widget subheading       |

**CSS Integration**

The widget CSS (`posts-widget.css`) uses the Peptide Starter theme's CSS custom properties (`--color-*`, `--spacing-*`, `--text-*`, `--radius-*`, `--transition-*`) with hardcoded fallbacks. This means:

- **On sites using Peptide Starter:** widget automatically adapts to light/dark mode via `data-theme`.
- **On other themes:** fallback values render a sensible dark-themed design.

---

## Database Schema

### Custom Tables

All tables use `$wpdb->prefix` + `prautoblogger_` prefix.

#### `ab_source_data` — Raw social media data

| Column        | Type              | Description                                      |
|---------------|-------------------|--------------------------------------------------|
| id            | BIGINT UNSIGNED   | Auto-increment PK                                |
| source_type   | VARCHAR(50)       | 'reddit' (extensible for future providers)        |
| source_id     | VARCHAR(255)      | Platform-specific unique ID (post/comment ID)     |
| subreddit     | VARCHAR(255)      | Subreddit or channel name (nullable)              |
| title         | TEXT              | Post/video title                                 |
| content       | LONGTEXT          | Post body / comment text / transcript             |
| author        | VARCHAR(255)      | Original author username                          |
| score         | INT               | Upvotes/likes/engagement metric                   |
| comment_count | INT               | Number of comments/replies                        |
| permalink     | VARCHAR(500)      | URL to original content                           |
| collected_at  | DATETIME          | When we collected this data                       |
| metadata_json | LONGTEXT          | Platform-specific extra data (JSON)               |

- INDEX: `source_type`, `collected_at`
- UNIQUE INDEX: `source_type`, `source_id`

#### `ab_analysis_results` — Analyzed topics and patterns

| Column           | Type              | Description                                    |
|------------------|-------------------|------------------------------------------------|
| id               | BIGINT UNSIGNED   | Auto-increment PK                              |
| analysis_type    | VARCHAR(50)       | 'question', 'complaint', 'comparison'          |
| topic            | VARCHAR(500)      | Detected topic/theme                           |
| summary          | TEXT              | LLM-generated summary of the pattern           |
| frequency        | INT               | How many source posts mention this             |
| relevance_score  | FLOAT             | LLM-assigned relevance score (0-1)             |
| source_ids_json  | LONGTEXT          | JSON array of ab_source_data.id references     |
| analyzed_at      | DATETIME          | When analysis was performed                    |
| metadata_json    | LONGTEXT          | Extra analysis data (JSON)                     |

- INDEX: `analysis_type`, `relevance_score`
- INDEX: `analyzed_at`

#### `prab_generation_log` — API cost and generation tracking

| Column            | Type              | Description                                   |
|-------------------|-------------------|-----------------------------------------------|
| id                | BIGINT UNSIGNED   | Auto-increment PK                             |
| post_id           | BIGINT UNSIGNED   | WordPress post ID (nullable, set after publish)|
| run_id            | VARCHAR(36)       | UUID linking all entries from one pipeline run |
| stage             | VARCHAR(50)       | 'analysis', 'outline', 'draft', 'edit', 'review' |
| provider          | VARCHAR(50)       | 'openrouter'                                  |
| model             | VARCHAR(100)      | Model identifier used                         |
| prompt_tokens     | INT               | Input tokens consumed                         |
| completion_tokens | INT               | Output tokens generated                       |
| estimated_cost    | DECIMAL(10,6)     | Estimated USD cost                            |
| request_json      | LONGTEXT          | Full request payload (for debugging)          |
| response_status   | VARCHAR(20)       | 'success', 'error', 'timeout'                 |
| error_message     | TEXT              | Error details if failed (nullable)             |
| created_at        | DATETIME          | When the API call was made                    |

- INDEX: `post_id`
- INDEX: `run_id`
- INDEX: `created_at`
- INDEX: `stage`

#### `prab_event_log` — Structured application logging

| Column     | Type              | Description                                     |
|------------|-------------------|-------------------------------------------------|
| id         | BIGINT UNSIGNED   | Auto-increment PK                               |
| level      | VARCHAR(10)       | 'error', 'warning', 'info', 'debug'             |
| context    | VARCHAR(100)      | Origin: 'pipeline', 'reddit', 'ga4', etc.       |
| message    | TEXT              | Human-readable log message                      |
| meta_json  | LONGTEXT          | Optional structured data (nullable)              |
| created_at | DATETIME          | When the event occurred                          |

- INDEX: `level`, `created_at`
- INDEX: `created_at`

#### `ab_content_scores` — Post performance metrics

| Column             | Type              | Description                                  |
|--------------------|-------------------|----------------------------------------------|
| id                 | BIGINT UNSIGNED   | Auto-increment PK                            |
| post_id            | BIGINT UNSIGNED   | WordPress post ID                            |
| pageviews          | INT               | Total pageviews (WP native or GA4)           |
| avg_time_on_page   | FLOAT             | Average seconds on page (GA4)                |
| bounce_rate        | FLOAT             | Bounce rate percentage (GA4)                 |
| comment_count      | INT               | WordPress comment count                      |
| composite_score    | FLOAT             | LLM-computed content quality score (0-100)   |
| score_factors_json | LONGTEXT          | JSON breakdown of what contributed to score  |
| measured_at        | DATETIME          | When metrics were collected                  |

- INDEX: `post_id`
- INDEX: `measured_at`
- INDEX: `composite_score`

### WordPress Options (`wp_options`)

All prefixed with `prautoblogger_`:

| Option Key                             | Description                                           |
|----------------------------------------|-------------------------------------------------------|
| `prautoblogger_openrouter_api_key`     | Encrypted OpenRouter API key                          |
| `prautoblogger_ai_gateway_base_url`    | Optional Cloudflare AI Gateway URL (proxies OpenRouter); empty = direct |
| `prautoblogger_ai_gateway_cache_ttl`   | Seconds Cloudflare may cache identical LLM responses (0 = off)           |
| `prautoblogger_ga4_property_id`        | Google Analytics 4 property ID                        |
| `prautoblogger_ga4_credentials_json`   | Encrypted GA4 service account credentials             |
| `prautoblogger_analysis_model`         | OpenRouter model for analysis (default: cheap)        |
| `prautoblogger_writing_model`          | OpenRouter model for writing (default: quality)       |
| `prautoblogger_editor_model`           | OpenRouter model for chief editor (default: quality)  |
| `prautoblogger_daily_article_target`   | Number of articles per day (1-10, default: 1)         |
| `prautoblogger_writing_pipeline`       | 'single_pass' or 'multi_step' (default: multi_step)  |
| `prautoblogger_niche_description`      | Text description of the site's niche                  |
| `prautoblogger_target_subreddits`      | JSON array of subreddits to monitor                   |
| `prautoblogger_monthly_budget_usd`     | Monthly API spend limit in USD                        |
| `prautoblogger_tone`                   | Content tone (informational, conversational, etc.)    |
| `prautoblogger_min_word_count`         | Minimum article word count (default: 800)             |
| `prautoblogger_max_word_count`         | Maximum article word count (default: 2000)            |
| `prautoblogger_topic_exclusions`       | JSON array of topics to never write about             |
| `prautoblogger_enabled_sources`        | JSON array of active source types                     |
| `prautoblogger_auto_publish`           | Toggle: auto-publish approved posts (default: '0')    |
| `prautoblogger_default_author`         | WP user ID for generated post authorship              |
| `prautoblogger_default_category`       | WP category ID fallback for generated posts           |
| `prautoblogger_log_level`              | Logging threshold: error/warning/info/debug           |
| `prautoblogger_db_version`             | Schema version for migrations                         |
| `prautoblogger_schedule_time`          | Daily generation time (HH:MM, default: '03:00')       |
| `prautoblogger_cloudflare_ai_token`    | Encrypted Cloudflare Workers AI API token             |
| `prautoblogger_cloudflare_account_id`  | Cloudflare account UUID (plaintext — identifier, not secret) |
| `prautoblogger_image_model`            | Image model alias: `flux-1-schnell` (default) or `flux-1-dev` |
| `prautoblogger_image_style_suffix`     | Text appended to every image prompt (default: CEO-locked 90s infomercial prompt) |

### Post Meta

Stored on every PRAutoBlogger-generated post:

| Meta Key                              | Description                                   |
|---------------------------------------|-----------------------------------------------|
| `_prautoblogger_generated`            | Boolean flag — '1' if generated by plugin     |
| `_prautoblogger_analysis_id`          | FK to ab_analysis_results.id                  |
| `_prautoblogger_source_ids`           | JSON array of source data IDs used            |
| `_prautoblogger_model_used`           | Model that generated the content              |
| `_prautoblogger_pipeline_mode`        | 'single_pass' or 'multi_step'                 |
| `_prautoblogger_total_cost`           | Total USD cost for this post                  |
| `_prautoblogger_total_tokens`         | Total tokens consumed for this post           |
| `_prautoblogger_editor_verdict`       | 'approved', 'revised', 'rejected'             |
| `_prautoblogger_editor_notes`         | Chief editor's review notes                   |
| `_prautoblogger_generated_at`         | ISO 8601 timestamp of generation              |
| `_prautoblogger_research_sources`     | JSON array of source URLs used                |

---

## External API Integrations

| Service | Purpose | Auth | Rate Limit | Code |
|---------|---------|------|------------|------|
| OpenRouter | All LLM calls (analysis, writing, editing) | API key (encrypted in wp_options) | Per-model | `providers/class-open-router-provider.php`, `providers/class-open-router-pricing.php` |
| Cloudflare AI Gateway (optional) | Transparent proxy in front of OpenRouter — adds response caching, cost logging, rate limiting, provider fallback | Same OpenRouter key; gateway URL in `prautoblogger_ai_gateway_base_url` | Gateway-side quotas | Same provider file; activated when gateway URL option is non-empty |
| Reddit RSS | Primary Reddit data source — Atom feeds for subreddit hot posts | None (unauthenticated) | No known rate limit; reliable from datacenter IPs | `providers/class-reddit-json-client.php` |
| Reddit .json | Fallback for posts + only source for comments | None (unauthenticated) | ~10 req/min (datacenter IPs often blocked) | `providers/class-reddit-json-client.php` |
| Google Analytics 4 | Post performance metrics | OAuth2 service account | Standard GA4 limits | `core/class-ga4-client.php`, `core/class-metrics-collector.php` |
| Cloudflare Workers AI | Image generation (FLUX.1 schnell / dev) for article hero, thumbnail, and IG placements | API token (encrypted in wp_options) + account ID | Workers AI per-account quotas | `providers/class-cloudflare-image-provider.php`, `providers/class-cloudflare-image-pricing.php`, `providers/class-cloudflare-image-validator.php` |

---

## Key Decisions

### #1: OpenRouter over direct provider APIs
Gives access to many models through one API, simplifies provider management, and lets the user switch models without code changes. Trade-off: slight latency overhead and dependency on OpenRouter's availability.

### #2: Reddit as first social source (RSS primary, .json fallback)
Reddit has rich discussion data ideal for identifying recurring questions and pain points. Reddit RSS/Atom feeds are the primary data source — they work reliably from datacenter IPs (Hostinger) where .json endpoints return 403. The .json endpoints serve as a fallback for posts and are the only source for comment fetching (RSS doesn't include comments). Trade-off: RSS lacks engagement metrics (score, comment count), but post titles and content are sufficient for topic analysis.

### #3: Chief editor agent instead of human review queue
The user wants full automation — a second LLM pass reviews quality, SEO, and accuracy before publishing. Posts that fail editorial review are flagged for human intervention rather than published. Trade-off: doubles the LLM cost of the "review" step, but catches quality issues.

### #4: Configurable pipeline (single-pass vs. multi-step)
Some users want cheap/fast, others want high quality. Making this configurable avoids forcing one approach. Trade-off: more code paths to maintain.

### #5: Composite content score using LLM
Rather than just tracking raw metrics, we periodically have the LLM evaluate what made high-performing posts succeed and low-performing posts fail. This feeds back into the analysis and generation prompts. Trade-off: additional API cost for scoring, but enables the self-improvement loop.

### #6: Source provider interface for future platforms
Reddit is the only implemented source, but the interface pattern means adding a new source (YouTube, TikTok, etc.) is one class implementation plus a settings checkbox. We removed the old stub provider files (TikTok, Instagram, YouTube) because dead code confuses AI agents and humans alike — the interface is the contract, not empty classes. Trade-off: slight over-engineering for day one, but pays off immediately at source #2.

### #7: Custom tables for high-volume data
Source data, analysis results, and generation logs are high-write, time-series data. WordPress post_meta and options are wrong for this. Custom tables with proper indexes. Trade-off: more complex activation/uninstall, but correct.

### #8: All API keys encrypted at rest
Using `wp_salt()` as encryption key with OpenSSL. Not bulletproof (salt is in wp-config.php) but significantly better than plaintext in wp_options. Trade-off: adds complexity to option get/set, but necessary for security.

### #9: Structured logger instead of raw error_log()
All application logging flows through `PRAutoBlogger_Logger` singleton, which writes to the `prab_event_log` table and forwards errors/warnings to PHP's `error_log()`. This gives users an in-admin Activity Log page with filtering by level, search, and pagination — much more accessible than server logs. Trade-off: one extra DB write per log entry, but the table is prunable and indexed.

### #10: Database-level atomic mutex for generation lock
Uses `INSERT IGNORE` on wp_options (which has a UNIQUE index on `option_name`) instead of transient-based locking. This eliminates the TOCTOU race condition where two concurrent cron runs could both read the transient as "not locked" before either sets it. Expired locks older than 1 hour are cleaned up first to prevent permanent deadlock.

### #11: wp.element for frontend React (no build step)
The posts widget uses WordPress-bundled React (`wp.element`) with raw `createElement` calls instead of JSX. This avoids requiring a Node.js build pipeline for what is a simple card grid. Trade-off: more verbose component code, but zero tooling dependencies for the plugin consumer.

### #12: REST API for frontend data fetching
The widget fetches posts asynchronously from a dedicated REST endpoint rather than rendering server-side. This keeps initial page load fast and allows the endpoint to be cached independently (5-minute Cache-Control). The permission callback uses a filter (`prautoblogger_rest_posts_public`) so sites can restrict access if needed.

### #13: Run-ID based log linking
Each pipeline execution generates a UUID (`run_id`) that tags every `prab_generation_log` entry. When a post is published, `link_generation_logs()` uses `UPDATE WHERE run_id = X` to associate entries with the `post_id`. This is more reliable than the previous timestamp-window approach, especially in batch runs where multiple posts are generated in quick succession.

### #14: Reddit RSS replaces PullPush.io (and earlier Reddit OAuth)
Reddit rejected our OAuth API application (April 2026). We initially switched to PullPush.io, but its index was frequently stale or unavailable. Reddit's RSS/Atom feeds (`/r/{sub}/hot.rss`) proved the most reliable option — they work from datacenter IPs where .json gets 403, require no auth, and have no apparent rate limit. The .json endpoints are kept as a fallback for posts and as the sole source for comment data. Each collected item's metadata includes a `data_source` field (`reddit_rss` or `reddit_json`) for auditability.

### #16: Image generation via Cloudflare Workers AI (FLUX.1), direct (not via AI Gateway)
The image-generation layer (started 2026-04-15, tracked in `convo/prautoblogger/threads/2026-04-image-pipeline/`) uses FLUX.1 [schnell] on Cloudflare Workers AI as its default model, chosen for its ~$0.0011/MP cost and 2–3 sec latency. FLUX.1 [dev] is a ~4× cost upgrade exposed as a dropdown for specific posts that warrant it. Calls go directly to `https://api.cloudflare.com/client/v4/accounts/{id}/ai/run/@cf/black-forest-labs/...`, *not* through the Cloudflare AI Gateway that we use for OpenRouter — the gateway route to Workers AI currently 403s (pre-existing open issue). The provider lives behind `PRAutoBlogger_Image_Provider_Interface`, so the decision is trivially reversible: switching to a different image API (DALL-E, Replicate, a future AI Gateway route) is a new class implementation plus a one-line swap in the pipeline wiring. Trade-off: we run two different Cloudflare integration paths (gateway for OpenRouter LLMs, direct for Workers AI images) until the gateway route stabilizes — minor cognitive overhead, zero functional downside.

### #15: Optional Cloudflare AI Gateway in front of OpenRouter
We already use Cloudflare for DNS/CDN on peptiderepo.com, so layering AI Gateway in front of OpenRouter is zero marginal infrastructure. It gives us response caching (meaningful for repeated classification/scoring calls), a unified cost/latency dashboard, rate limiting, and provider fallback — all of which we would otherwise have to build ourselves to satisfy the CTO cost-tracking rules. Kept as an opt-in URL setting (`prautoblogger_ai_gateway_base_url`) so the plugin still works unchanged out of the box and can be bypassed instantly if the gateway misbehaves. The gateway is a transparent OpenRouter-compatible proxy; no new provider class is needed, and the response parsing path (`usage`, `choices[0].message.content`) is unchanged.

---

## Cross-System LLM Budget Coordination

PRAutoBlogger and Peptide News both call OpenRouter and may share a single API key / billing account. Their combined spend should be considered when setting per-plugin budgets.

| Plugin | Default Models | Typical Daily Spend | Budget Control |
|--------|---------------|--------------------:|----------------|
| PRAutoBlogger | Gemini 2.5 Flash Lite (analysis + editing), Claude Sonnet 4 (writing) | $0.05–$0.30 depending on article count | Hard-stop monthly budget in plugin settings |
| Peptide News | Google Gemini 2.0 Flash (keywords + summaries) | $0.01–$0.05 | No hard budget yet (planned) |

**Important:** If your OpenRouter account has a global spending limit, set each plugin's budget to less than half the total. PRAutoBlogger will hard-stop when its budget is exhausted, but Peptide News currently has no budget enforcement — a spike in news fetches could consume shared quota.

**Future improvement:** A shared `wp_options` key (e.g., `ecosystem_monthly_llm_budget`) that both plugins read, with each plugin reserving its allocation on startup. This requires coordination at the ecosystem level and is tracked as a medium-term goal.
