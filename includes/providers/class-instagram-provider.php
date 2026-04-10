<?php
declare(strict_types=1);

/**
 * Instagram source provider — STUB for future implementation.
 *
 * Will integrate with Instagram's Graph API to collect post captions,
 * comments, and hashtag trends. Requires Meta developer app credentials.
 *
 * Triggered by: Source_Collector when 'instagram' is in enabled_sources.
 * Dependencies: TBD — Instagram Graph API credentials.
 *
 * @see interface-source-provider.php — Interface this class implements.
 */
class PRAutoBlogger_Instagram_Provider implements PRAutoBlogger_Source_Provider_Interface {

	/**
	 * @param array<string, mixed> $config
	 * @return PRAutoBlogger_Source_Data[]
	 * @throws \RuntimeException Always — not yet implemented.
	 */
	public function collect_data( array $config ): array {
		throw new \RuntimeException(
			__( 'Instagram source provider is not yet implemented. Remove "instagram" from enabled sources.', 'prautoblogger' )
		);
	}

	public function get_source_type(): string {
		return 'instagram';
	}

	public function validate_credentials(): bool {
		return false;
	}

	public function get_rate_limit_status(): array {
		return [ 'remaining' => 0, 'limit' => 0, 'resets_at' => '' ];
	}
}
