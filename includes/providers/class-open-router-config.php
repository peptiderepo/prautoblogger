<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

/**
 * Configuration helpers for OpenRouter provider.
 *
 * Resolves admin-configured settings for API endpoint selection
 * (direct vs. Cloudflare AI Gateway routing) and caching behavior.
 *
 * Dependencies: get_option() for WordPress settings lookups.
 *
 * @see class-open-router-provider.php      — Parent class that uses this config.
 * @see class-open-router-validator.php     — Also uses API base URL resolution.
 * @see ARCHITECTURE.md                     — External API Integrations section.
 */
class PRAutoBlogger_OpenRouter_Config {

	/**
	 * Default direct-to-OpenRouter endpoint.
	 *
	 * Used when no Cloudflare AI Gateway URL is configured. The active endpoint
	 * is resolved per-request via get_api_base_url() so admins can flip to an
	 * AI Gateway proxy without code changes.
	 */
	private const DEFAULT_API_BASE_URL = 'https://openrouter.ai/api/v1';

	/**
	 * Resolve the API base URL.
	 *
	 * If an admin has configured a Cloudflare AI Gateway URL in settings,
	 * we route through that instead of hitting OpenRouter directly. The
	 * gateway is a transparent OpenAI/OpenRouter-compatible proxy that
	 * adds caching, cost logging, rate-limiting, and provider fallback
	 * — see ARCHITECTURE.md "External API Integrations".
	 *
	 * Expected gateway URL shape:
	 *   https://gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}/openrouter
	 *
	 * @return string Base URL with no trailing slash.
	 */
	public function get_api_base_url(): string {
		$override = trim( (string) get_option( 'prautoblogger_ai_gateway_base_url', '' ) );
		if ( '' === $override ) {
			return self::DEFAULT_API_BASE_URL;
		}
		// Only accept https to avoid plaintext-auth regressions.
		if ( 0 !== stripos( $override, 'https://' ) ) {
			return self::DEFAULT_API_BASE_URL;
		}
		return rtrim( $override, '/' );
	}

	/**
	 * Cache TTL (seconds) for AI Gateway response caching.
	 *
	 * Cloudflare honours the `cf-aig-cache-ttl` request header and serves
	 * cached responses for identical payloads within the TTL window. Only
	 * meaningful when a gateway base URL is configured; harmless otherwise.
	 *
	 * @return int Non-negative integer seconds; 0 disables caching.
	 */
	public function get_cache_ttl_seconds(): int {
		$ttl = (int) get_option( 'prautoblogger_ai_gateway_cache_ttl', 0 );
		return $ttl > 0 ? $ttl : 0;
	}

	/**
	 * Check if routing through Cloudflare AI Gateway vs. direct-to-OpenRouter.
	 *
	 * @return bool True if a gateway URL is configured.
	 */
	public function is_via_gateway(): bool {
		return self::DEFAULT_API_BASE_URL !== $this->get_api_base_url();
	}

	/**
	 * Get the default OpenRouter API base URL constant.
	 *
	 * @return string
	 */
	public static function get_default_api_base_url(): string {
		return self::DEFAULT_API_BASE_URL;
	}
}
