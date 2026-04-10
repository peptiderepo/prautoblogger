# AutoBlogger — Architecture

AutoBlogger is a WordPress plugin that monitors social media (starting with Reddit) for recurring questions, complaints, and comparisons in a configured niche, uses LLM agents (via OpenRouter) to generate high-quality blog articles from those insights, runs them through a chief editor agent for quality review, and auto-publishes them on a configurable daily schedule. All collected data and generation metrics are stored for a self-improvement feedback loop.

---

## Data Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         WP-Cron / Action Scheduler                      │
│                         (Daily trigger, configurable)                    │
└────────────┬────────────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────┐
│   1. Source Collector    │  Pulls raw posts/comments from social platforms
│   (Reddit API, etc.)    │  Stores raw data in `ab_source_data` table
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│   2. Content Analyzer   │  LLM call (cheap model) to detect:
│   (Analysis Agent)      │  - Recurring questions
│                         │  - Complaints / pain points
│                         │  - Product comparisons
│                         │  Stores analysis in `ab_analysis_results` table
│                         │  Outputs ranked article ideas
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│   3. Idea Scorer &      │  Deduplicates against existing posts
│      Deduplicator       │  Scores ideas by relevance, frequency, freshness
│                         │  Picks top N ideas (N = daily article target)
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│   4. Writer Agent       │  Configurable pipeline:
│   (Content Generator)   │  Single-pass: one LLM call → complete article
│                         │  Multi-step: outline → draft → polish
│                         │  Uses quality model via OpenRouter
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│   5. Chief Editor Agent │  LLM-powered editorial review:
│                         │  - Checks quality, accuracy, tone
│                         │  - Checks SEO (title, headings, keyword density)
│                         │  - Can request rewrites or approve
│                         │  - Approved → publish; Rejected → flag for human
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│   6. Publisher           │  Creates/updates WordPress post
│                         │  Sets categories, tags, featured image
│                         │  Stores generation metadata in post_meta
│                         │  Logs cost/tokens in `ab_generation_log` table
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│   7. Metrics Collector  │  Tracks post-publish performance:
│   (Separate cron job)   │  - WordPress native (views, comments)
│                         │  - GA4 (pageviews, bounce rate, time on page)
│                         │  - Composite "content score"
│                         │  Feeds back into Analysis step for self-improvement
└─────────────────────────┘
```

---

## File Tree

```
autoblogger/
├── autoblogger.php                       # Plugin bootstrap — minimal, only hook registration
├── uninstall.php                         # Clean removal of ALL plugin data
├── readme.txt                            # WordPress.org standard readme
├── composer.json                         # Autoloading (PSR-4) and dependencies
├── ARCHITECTURE.md                       # This file
├── CONVENTIONS.md                        # Naming patterns, extension guides
├── CHANGELOG.md                          # Semantic versioning changelog
│
├── assets/
│   ├── css/
│   │   └── admin.css                     # Admin page styles (wp-admin conventions)
│   └── js/
│       └── admin.js                      # Admin page interactivity (vanilla JS / Alpine.js)
│
├── includes/
│   ├── class-autoblogger.php             # Main orchestrator — registers all hooks via loader
│   ├── class-activator.php               # Activation: create DB tables, set defaults, schedule cron
│   ├── class-deactivator.php             # Deactivation: clear cron, cleanup transients
│   ├── class-autoloader.php              # PSR-4-style autoloader for plugin classes
│   │
│   ├── admin/
│   │   ├── class-admin-page.php          # Main settings page (tabbed SaaS-style UI)
│   │   ├── class-settings-fields.php     # Declarative settings definitions (sections + fields)
│   │   ├── class-admin-notices.php       # Onboarding notices, error alerts, budget warnings
│   │   ├── class-dashboard-widget.php    # WP Dashboard widget showing generation status
│   │   ├── class-post-metabox.php        # Metabox on posts showing generation metadata
│   │   ├── class-metrics-page.php        # Admin page for cost dashboard and content scores
│   │   ├── class-review-queue.php        # Approve/edit/reject queue for generated drafts
│   │   └── class-log-viewer.php          # Activity log viewer with level filtering
│   │
│   ├── core/
│   │   ├── class-scheduler.php           # WP-Cron / Action Scheduler job management
│   │   ├── class-source-collector.php    # Orchestrates data collection from all sources
│   │   ├── class-content-analyzer.php    # LLM-powered analysis of collected social data
│   │   ├── class-idea-scorer.php         # Ranks and deduplicates article ideas
│   │   ├── class-content-generator.php   # Writer agent — manages the generation pipeline
│   │   ├── class-chief-editor.php        # Editor agent — LLM-powered editorial review
│   │   ├── class-publisher.php           # Creates WordPress posts from approved content
│   │   ├── class-cost-tracker.php        # Logs all API costs, enforces budget limits
│   │   ├── class-logger.php             # Structured logging singleton (error/warning/info/debug)
│   │   ├── class-pipeline-runner.php    # Orchestrates the full generation pipeline
│   │   ├── class-ga4-client.php         # Google Analytics 4 API client (OAuth + Data API)
│   │   └── class-metrics-collector.php   # Collects post performance data (WP + GA4)
│   │
│   ├── providers/
│   │   ├── interface-llm-provider.php    # Contract for any LLM provider
│   │   ├── class-openrouter-provider.php # OpenRouter API implementation
│   │   ├── class-openrouter-pricing.php  # Model pricing lookup and cost estimation
│   │   ├── interface-source-provider.php # Contract for any social media source
│   │   ├── class-reddit-provider.php     # Reddit API (OAuth) implementation
│   │   ├── class-reddit-api-client.php   # Reddit HTTP client (OAuth + rate limiting)
│   │   ├── class-tiktok-provider.php     # TikTok — stub/future implementation
│   │   ├── class-instagram-provider.php  # Instagram — stub/future implementation
│   │   └── class-youtube-provider.php    # YouTube — stub/future implementation
│   │
│   └── models/
│       ├── class-source-data.php         # Value object: raw social media post/comment
│       ├── class-analysis-result.php     # Value object: analyzed topic with scores
│       ├── class-article-idea.php        # Value object: scored article idea
│       ├── class-content-request.php     # Value object: generation request with params
│       ├── class-editorial-review.php    # Value object: editor verdict, scores, revised content
│       ├── class-generation-log.php      # Value object: log entry for a generation run
│       └── class-content-score.php       # Value object: composite performance score
│
├── templates/
│   └── admin/
│       ├── settings-page.php             # Settings page template (tabbed sidebar layout)
│       ├── metrics-page.php              # Metrics/cost dashboard template
│       ├── review-queue.php              # Review queue table template
│       ├── log-viewer.php                # Activity log viewer template
│       └── metabox-generation-info.php   # Post metabox template
│
├── languages/
│   └── autoblogger.pot                   # i18n translation template
│
└── tests/
    ├── unit/                             # PHPUnit tests (mocked dependencies)
    └── integration/                      # WordPress integration tests
```

---

## Database Schema

### Custom Tables

All tables use `$wpdb->prefix` + `autoblogger_` prefix.

#### `ab_source_data` — Raw social media data
| Column          | Type              | Description                                    |
|-----------------|-------------------|------------------------------------------------|
| id              | BIGINT UNSIGNED   | Auto-increment PK                              |
| source_type     | VARCHAR(50)       | 'reddit', 'tiktok', 'instagram', 'youtube'     |
| source_id       | VARCHAR(255)      | Platform-specific unique ID (post/comment ID)   |
| subreddit       | VARCHAR(255)      | Subreddit or channel name (nullable)            |
| title           | TEXT              | Post/video title                               |
| content         | LONGTEXT          | Post body / comment text / transcript           |
| author          | VARCHAR(255)      | Original author username                        |
| score           | INT               | Upvotes/likes/engagement metric                 |
| comment_count   | INT               | Number of comments/replies                      |
| permalink       | VARCHAR(500)      | URL to original content                         |
| collected_at    | DATETIME          | When we collected this data                     |
| metadata_json   | LONGTEXT          | Platform-specific extra data (JSON)             |
| INDEX           | source_type, collected_at                                        |
| UNIQUE INDEX    | source_type, source_id                                           |

#### `ab_analysis_results` — Analyzed topics and patterns
| Column          | Type              | Description                                    |
|-----------------|-------------------|------------------------------------------------|
| id              | BIGINT UNSIGNED   | Auto-increment PK                              |
| analysis_type   | VARCHAR(50)       | 'question', 'complaint', 'comparison'           |
| topic           | VARCHAR(500)      | Detected topic/theme                            |
| summary         | TEXT              | LLM-generated summary of the pattern            |
| frequency       | INT               | How many source posts mention this              |
| relevance_score | FLOAT             | LLM-assigned relevance score (0-1)              |
| source_ids_json | LONGTEXT          | JSON array of ab_source_data.id references      |
| analyzed_at     | DATETIME          | When analysis was performed                     |
| metadata_json   | LONGTEXT          | Extra analysis data (JSON)                      |
| INDEX           | analysis_type, relevance_score                                   |
| INDEX           | analyzed_at                                                      |

#### `ab_generation_log` — API cost and generation tracking
| Column           | Type              | Description                                   |
|------------------|-------------------|-----------------------------------------------|
| id               | BIGINT UNSIGNED   | Auto-increment PK                             |
| post_id          | BIGINT UNSIGNED   | WordPress post ID (nullable, set after publish)|
| run_id           | VARCHAR(36)       | UUID linking all entries from one pipeline run |
| stage            | VARCHAR(50)       | 'analysis', 'outline', 'draft', 'edit', 'review' |
| provider         | VARCHAR(50)       | 'openrouter'                                  |
| model            | VARCHAR(100)      | Model identifier used                         |
| prompt_tokens    | INT               | Input tokens consumed                         |
| completion_tokens| INT               | Output tokens generated                       |
| estimated_cost   | DECIMAL(10,6)     | Estimated USD cost                            |
| request_json     | LONGTEXT          | Full request payload (for debugging)           |
| response_status  | VARCHAR(20)       | 'success', 'error', 'timeout'                 |
| error_message    | TEXT              | Error details if failed (nullable)             |
| created_at       | DATETIME          | When the API call was made                    |
| INDEX            | post_id                                                         |
| INDEX            | run_id                                                          |
| INDEX            | created_at                                                      |
| INDEX            | stage                                                           |

#### `ab_event_log` — Structured application logging
| Column           | Type              | Description                                   |
|------------------|-------------------|-----------------------------------------------|
| id               | BIGINT UNSIGNED   | Auto-increment PK                             |
| level            | VARCHAR(10)       | 'error', 'warning', 'info', 'debug'           |
| context          | VARCHAR(100)      | Origin: 'pipeline', 'reddit', 'ga4', etc.     |
| message          | TEXT              | Human-readable log message                    |
| meta_json        | LONGTEXT          | Optional structured data (nullable)            |
| created_at       | DATETIME          | When the event occurred                       |
| INDEX            | level, created_at                                               |
| INDEX            | created_at                                                      |

#### `ab_content_scores` — Post performance metrics
| Column           | Type              | Description                                   |
|------------------|-------------------|-----------------------------------------------|
| id               | BIGINT UNSIGNED   | Auto-increment PK                             |
| post_id          | BIGINT UNSIGNED   | WordPress post ID                             |
| pageviews        | INT               | Total pageviews (WP native or GA4)             |
| avg_time_on_page | FLOAT             | Average seconds on page (GA4)                  |
| bounce_rate      | FLOAT             | Bounce rate percentage (GA4)                   |
| comment_count    | INT               | WordPress comment count                        |
| composite_score  | FLOAT             | LLM-computed content quality score (0-100)     |
| score_factors_json| LONGTEXT          | JSON breakdown of what contributed to score    |
| measured_at      | DATETIME          | When metrics were collected                    |
| INDEX            | post_id                                                         |
| INDEX            | measured_at                                                     |
| INDEX            | composite_score                                                 |

### WordPress Options (`wp_options`)

All prefixed with `autoblogger_`:

| Option Key                          | Description                                          |
|-------------------------------------|------------------------------------------------------|
| `autoblogger_openrouter_api_key`    | Encrypted OpenRouter API key                         |
| `autoblogger_reddit_client_id`      | Reddit OAuth client ID                               |
| `autoblogger_reddit_client_secret`  | Encrypted Reddit client secret                       |
| `autoblogger_reddit_access_token`   | Reddit OAuth access token (transient-backed)         |
| `autoblogger_ga4_property_id`       | Google Analytics 4 property ID                       |
| `autoblogger_ga4_credentials_json`  | Encrypted GA4 service account credentials            |
| `autoblogger_analysis_model`        | OpenRouter model for analysis (default: cheap)       |
| `autoblogger_writing_model`         | OpenRouter model for writing (default: quality)      |
| `autoblogger_editor_model`          | OpenRouter model for chief editor (default: quality) |
| `autoblogger_daily_article_target`  | Number of articles per day (1-10, default: 1)        |
| `autoblogger_writing_pipeline`      | 'single_pass' or 'multi_step' (default: multi_step)  |
| `autoblogger_niche_description`     | Text description of the site's niche                 |
| `autoblogger_target_subreddits`     | JSON array of subreddits to monitor                  |
| `autoblogger_monthly_budget_usd`    | Monthly API spend limit in USD                       |
| `autoblogger_tone`                  | Content tone (informational, conversational, etc.)   |
| `autoblogger_min_word_count`        | Minimum article word count (default: 800)            |
| `autoblogger_max_word_count`        | Maximum article word count (default: 2000)           |
| `autoblogger_topic_exclusions`      | JSON array of topics to never write about            |
| `autoblogger_enabled_sources`       | JSON array of active source types                    |
| `autoblogger_auto_publish`          | Toggle: auto-publish approved posts (default: '0')   |
| `autoblogger_default_author`        | WP user ID for generated post authorship             |
| `autoblogger_default_category`      | WP category ID fallback for generated posts          |
| `autoblogger_log_level`             | Logging threshold: error/warning/info/debug          |
| `autoblogger_db_version`            | Schema version for migrations                        |
| `autoblogger_schedule_time`         | Daily generation time (HH:MM, default: '03:00')      |

### Post Meta

Stored on every AutoBlogger-generated post:

| Meta Key                              | Description                                  |
|---------------------------------------|----------------------------------------------|
| `_autoblogger_generated`              | Boolean flag — '1' if generated by plugin    |
| `_autoblogger_analysis_id`            | FK to ab_analysis_results.id                 |
| `_autoblogger_source_ids`             | JSON array of source data IDs used           |
| `_autoblogger_model_used`             | Model that generated the content             |
| `_autoblogger_pipeline_mode`          | 'single_pass' or 'multi_step'                |
| `_autoblogger_total_cost`             | Total USD cost for this post                 |
| `_autoblogger_total_tokens`           | Total tokens consumed for this post          |
| `_autoblogger_editor_verdict`         | 'approved', 'revised', 'rejected'            |
| `_autoblogger_editor_notes`           | Chief editor's review notes                  |
| `_autoblogger_generated_at`           | ISO 8601 timestamp of generation             |
| `_autoblogger_research_sources`       | JSON array of source URLs used               |

---

## External API Integrations

| API            | Purpose                           | Code Location                          |
|----------------|-----------------------------------|----------------------------------------|
| OpenRouter     | All LLM calls (analysis, writing, editing) | `providers/class-openrouter-provider.php`, `providers/class-openrouter-pricing.php` |
| Reddit API     | Pull posts/comments from subreddits | `providers/class-reddit-provider.php`, `providers/class-reddit-api-client.php` |
| Google Analytics 4 | Post performance metrics       | `core/class-ga4-client.php`, `core/class-metrics-collector.php` |

---

## Key Decisions

1. **OpenRouter over direct provider APIs.** Gives access to many models through one API, simplifies provider management, and lets the user switch models without code changes. Trade-off: slight latency overhead and dependency on OpenRouter's availability.

2. **Reddit OAuth API over JSON scraping.** Official API is reliable, rate-limited in a predictable way, and won't break unexpectedly. Requires user to create a Reddit app (we document this). Trade-off: more setup friction for the user.

3. **Chief editor agent instead of human review queue.** The user wants full automation — a second LLM pass reviews quality, SEO, and accuracy before publishing. Posts that fail editorial review are flagged for human intervention rather than published. Trade-off: doubles the LLM cost of the "review" step, but catches quality issues.

4. **Configurable pipeline (single-pass vs. multi-step).** Some users want cheap/fast, others want high quality. Making this configurable avoids forcing one approach. Trade-off: more code paths to maintain.

5. **Composite content score using LLM.** Rather than just tracking raw metrics, we periodically have the LLM evaluate what made high-performing posts succeed and low-performing posts fail. This feeds back into the analysis and generation prompts. Trade-off: additional API cost for scoring, but enables the self-improvement loop.

6. **Source provider interface for future platforms.** Reddit is first, but TikTok/Instagram/YouTube are planned. The interface pattern means adding a new source is one class implementation. Trade-off: slight over-engineering for day one, but pays off immediately at source #2.

7. **Custom tables for high-volume data.** Source data, analysis results, and generation logs are high-write, time-series data. WordPress post_meta and options are wrong for this. Custom tables with proper indexes. Trade-off: more complex activation/uninstall, but correct.

8. **All API keys encrypted at rest.** Using `wp_salt()` as encryption key with OpenSSL. Not bulletproof (salt is in wp-config.php) but significantly better than plaintext in wp_options. Trade-off: adds complexity to option get/set, but necessary for security.

9. **Structured logger instead of raw error_log().** All application logging flows through `Autoblogger_Logger` singleton, which writes to the `ab_event_log` table and forwards errors/warnings to PHP's `error_log()`. This gives users an in-admin Activity Log page with filtering by level, search, and pagination — much more accessible than server logs. Trade-off: one extra DB write per log entry, but the table is prunable and indexed.

10. **Database-level atomic mutex for generation lock.** Uses `INSERT IGNORE` on `wp_options` (which has a UNIQUE index on `option_name`) instead of transient-based locking. This eliminates the TOCTOU race condition where two concurrent cron runs could both read the transient as "not locked" before either sets it. Expired locks older than 1 hour are cleaned up first to prevent permanent deadlock.

11. **Run-ID based log linking.** Each pipeline execution generates a UUID (`run_id`) that tags every `ab_generation_log` entry. When a post is published, `link_generation_logs()` uses `UPDATE WHERE run_id = X` to associate entries with the post_id. This is more reliable than the previous timestamp-window approach, especially in batch runs where multiple posts are generated in quick succession.
