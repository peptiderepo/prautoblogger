=== PRAutoBlogger ===
Contributors: peptiderepo
Tags: ai, content generation, blogging, seo, automation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically researches trending topics from social media and generates SEO-friendly blog posts using AI.

== Description ==

PRAutoBlogger monitors social media platforms (starting with Reddit) for recurring questions, complaints, and product comparisons in your niche. It uses AI to analyze these patterns, generate well-structured blog articles, run them through an editorial review agent, and publish them on a configurable daily schedule.

**Key Features:**

* Reddit integration for topic research (TikTok, Instagram, YouTube planned)
* Configurable AI-powered writing pipeline (single-pass or multi-step)
* Chief editor agent reviews content before publishing
* Full cost tracking with monthly budget limits
* Google Analytics 4 integration for performance metrics
* Self-improvement loop: learns from high-performing content
* Encrypted API key storage

**Supported LLM Providers:**

Uses OpenRouter for access to models from Anthropic, OpenAI, Google, Meta, and more.

== Installation ==

1. Upload the `prautoblogger` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to PRAutoBlogger > Settings to configure your API keys and preferences.
4. Add your OpenRouter API key and Reddit app credentials.
5. Configure your target subreddits and content preferences.
6. Click "Test Connections" to verify setup.
7. Click "Generate Now" for your first article, or wait for the daily schedule.

== Frequently Asked Questions ==

= How much does it cost to run? =

Costs depend on your chosen models and article volume. With default settings (Claude Haiku for analysis, Claude Sonnet for writing, 1 article/day), expect roughly $0.05-0.15 per article. The plugin includes a monthly budget hard-stop to prevent overspending.

= Can I review articles before they publish? =

By default, articles go through the chief editor agent. Articles that pass review are auto-published. Articles that fail review are saved as drafts for manual review.

= How do I set up Reddit API access? =

Go to reddit.com/prefs/apps, create a "script" type application. Copy the client ID and secret into the PRAutoBlogger settings page.

== Changelog ==

= 0.1.0 =
* Initial release with Reddit source, OpenRouter LLM, and full content pipeline.
