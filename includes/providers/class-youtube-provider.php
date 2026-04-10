<?php
declare(strict_types=1);

/**
 * YouTube source provider — STUB for future implementation.
 *
 * Will integrate with YouTube Data API v3 to collect video titles,
 * descriptions, comments, and trending topics. Requires Google API key.
 *
 * Triggered by: Source_Collector when 'youtube' is in enabled_sources.
 * Dependencies: TBD — YouTube Data API key.
 *
 * @see interface-source-provider.php — Interface this class implements.
 */
class Autoblogger_Youtube_Provider implements Autoblogger_Source_Provider_Interface {

	/**
	 * @param array<string, mixed> $config
	 * @return Autoblogger_Source_Data[]
	 * @throws \RuntimeException Always — not yet implemented.
	 */
	public function collect_data( array $config ): array {
		throw new \RuntimeException(
			__( 'YouTube source provider is not yet implemented. Remove "youtube" from enabled sources.', 'autoblogger' )
		);
	}

	public function get_source_type(): string {
		return 'youtube';
	}

	public function validate_credentials(): bool {
		return false;
	}

	public function get_rate_limit_status(): array {
		return [ 'remaining' => 0, 'limit' => 0, 'resets_at' => '' ];
	}
}
