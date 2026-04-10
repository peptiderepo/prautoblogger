<?php
declare(strict_types=1);

/**
 * TikTok source provider — STUB for future implementation.
 *
 * Will integrate with TikTok's API to collect video descriptions, comments,
 * and trending topics. Requires TikTok developer app credentials.
 *
 * Triggered by: Source_Collector when 'tiktok' is in enabled_sources.
 * Dependencies: TBD — TikTok API credentials.
 *
 * @see interface-source-provider.php — Interface this class implements.
 * @see ARCHITECTURE.md               — Planned source providers.
 */
class PRAutoBlogger_Tiktok_Provider implements PRAutoBlogger_Source_Provider_Interface {

	/**
	 * @param array<string, mixed> $config
	 * @return PRAutoBlogger_Source_Data[]
	 * @throws \RuntimeException Always — not yet implemented.
	 */
	public function collect_data( array $config ): array {
		throw new \RuntimeException(
			__( 'TikTok source provider is not yet implemented. Remove "tiktok" from enabled sources.', 'prautoblogger' )
		);
	}

	public function get_source_type(): string {
		return 'tiktok';
	}

	public function validate_credentials(): bool {
		return false;
	}

	public function get_rate_limit_status(): array {
		return [ 'remaining' => 0, 'limit' => 0, 'resets_at' => '' ];
	}
}
