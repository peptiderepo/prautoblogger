<?php
declare(strict_types=1);

/**
 * Contract for any social media source provider (Reddit, TikTok, Instagram, YouTube).
 *
 * Any class implementing this interface can be registered as a data source.
 * The Source_Collector iterates through enabled providers and calls collect_data().
 *
 * @see class-reddit-provider.php     — Primary implementation (Reddit API).
 * @see core/class-source-collector.php — Orchestrates collection from all providers.
 * @see CONVENTIONS.md                — "How To: Add a New Source Provider".
 */
interface PRAutoBlogger_Source_Provider_Interface {

	/**
	 * Collect posts/comments from this social platform.
	 *
	 * @param array{
	 *     subreddits?: string[],
	 *     limit?: int,
	 *     time_filter?: string,
	 *     keywords?: string[],
	 * } $config Source-specific configuration.
	 *
	 * @return PRAutoBlogger_Source_Data[] Array of collected data objects.
	 *
	 * @throws \RuntimeException On API error after retries exhausted.
	 */
	public function collect_data( array $config ): array;

	/**
	 * Get the source type identifier for this provider.
	 *
	 * Must match a valid value for ab_source_data.source_type.
	 *
	 * @return string Source type (e.g., 'reddit', 'tiktok', 'youtube').
	 */
	public function get_source_type(): string;

	/**
	 * Validate that the provider's API credentials are configured and working.
	 *
	 * @return bool True if credentials are valid and API is reachable.
	 */
	public function validate_credentials(): bool;

	/**
	 * Get current rate limit status for this provider's API.
	 *
	 * @return array{
	 *     remaining: int,
	 *     limit: int,
	 *     resets_at: string,
	 * } Rate limit information.
	 */
	public function get_rate_limit_status(): array;
}
