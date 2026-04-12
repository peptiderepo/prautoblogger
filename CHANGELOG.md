# Changelog

All notable changes to PRAutoBlogger will be documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project uses [Semantic Versioning](https://semver.org/).

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
