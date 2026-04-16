<?php
declare(strict_types=1);

/**
 * Structured logging with configurable verbosity levels.
 *
 * Writes log entries to a custom database table and optionally forwards
 * to PHP's error_log() for server-level monitoring. The log level is
 * user-configurable via Settings → PRAutoBlogger → General.
 *
 * Levels (ascending verbosity): error → warning → info → debug.
 * Setting the level to "info" captures error, warning, and info — not debug.
 *
 * Triggered by: Every class that previously called error_log() directly.
 * Dependencies: WordPress $wpdb.
 *
 * @see admin/class-log-viewer.php  — Reads and displays log entries.
 * @see class-activator.php         — Creates the prab_event_log table.
 * @see ARCHITECTURE.md             — Logging architecture section.
 */
class PRAutoBlogger_Logger {

	/** Log levels, lower = more severe. */
	public const LEVEL_ERROR   = 0;
	public const LEVEL_WARNING = 1;
	public const LEVEL_INFO    = 2;
	public const LEVEL_DEBUG   = 3;

	private const LEVEL_MAP = [
		'error'   => self::LEVEL_ERROR,
		'warning' => self::LEVEL_WARNING,
		'info'    => self::LEVEL_INFO,
		'debug'   => self::LEVEL_DEBUG,
	];

	private const LEVEL_LABELS = [
		self::LEVEL_ERROR   => 'error',
		self::LEVEL_WARNING => 'warning',
		self::LEVEL_INFO    => 'info',
		self::LEVEL_DEBUG   => 'debug',
	];

	/** Singleton instance. */
	private static ?self $instance = null;

	/** Cached numeric threshold from settings. */
	private int $threshold;

	private function __construct() {
		$setting         = get_option( 'prautoblogger_log_level', 'info' );
		$this->threshold = self::LEVEL_MAP[ $setting ] ?? self::LEVEL_INFO;
	}

	/** Get the singleton logger instance. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Log an error (always captured unless logging is completely off).
	 *
	 * @param string $message  Human-readable description.
	 * @param string $context  Origin class or pipeline stage.
	 * @param array<string, mixed> $meta Optional structured data.
	 */
	public function error( string $message, string $context = '', array $meta = [] ): void {
		$this->log( self::LEVEL_ERROR, $message, $context, $meta );
	}

	/**
	 * Log a warning (budget approaching, retries, degraded behavior).
	 *
	 * @param string $message
	 * @param string $context
	 * @param array<string, mixed> $meta
	 */
	public function warning( string $message, string $context = '', array $meta = [] ): void {
		$this->log( self::LEVEL_WARNING, $message, $context, $meta );
	}

	/**
	 * Log an informational event (pipeline start/stop, articles generated).
	 *
	 * @param string $message
	 * @param string $context
	 * @param array<string, mixed> $meta
	 */
	public function info( string $message, string $context = '', array $meta = [] ): void {
		$this->log( self::LEVEL_INFO, $message, $context, $meta );
	}

	/**
	 * Log debug detail (API token counts, timing, intermediate values).
	 *
	 * @param string $message
	 * @param string $context
	 * @param array<string, mixed> $meta
	 */
	public function debug( string $message, string $context = '', array $meta = [] ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context, $meta );
	}

	/**
	 * Core logging method. Writes to DB if level meets threshold.
	 *
	 * Side effects: database INSERT, optionally PHP error_log().
	 *
	 * @param int    $level   Numeric level constant.
	 * @param string $message Human-readable message.
	 * @param string $context Origin (class/stage).
	 * @param array<string, mixed> $meta Structured data.
	 */
	private function log( int $level, string $message, string $context, array $meta ): void {
		if ( $level > $this->threshold ) {
			return;
		}

		$label = self::LEVEL_LABELS[ $level ] ?? 'info';

		// Always forward errors to PHP error_log for server-level monitoring.
		if ( $level <= self::LEVEL_WARNING ) {
			error_log( sprintf( '[PRAutoBlogger][%s] %s — %s', strtoupper( $label ), $context, $message ) );
		}

		global $wpdb;
		if ( null === $wpdb ) {
			// No database available (unit test environment). PHP error_log
			// was already called above for warnings/errors; silently skip DB write.
			return;
		}
		$table = $wpdb->prefix . 'prautoblogger_event_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, [
			'level'      => $label,
			'context'    => substr( $context, 0, 100 ),
			'message'    => substr( $message, 0, 2000 ),
			'meta_json'  => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			'created_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Query log entries for the admin viewer.
	 *
	 * @param string $level_filter  Filter by level: 'all', 'error', 'warning', 'info', 'debug'.
	 * @param string $search        Search within message/context.
	 * @param int    $page          Page number (1-based).
	 * @param int    $per_page      Results per page.
	 *
	 * @return array{rows: array, total: int}
	 */
	public static function query(
		string $level_filter = 'all',
		string $search = '',
		int $page = 1,
		int $per_page = 50
	): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'prautoblogger_event_log';
		$where  = [];
		$params = [];

		if ( 'all' !== $level_filter && isset( self::LEVEL_MAP[ $level_filter ] ) ) {
			$where[]  = 'level = %s';
			$params[] = $level_filter;
		}

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(message LIKE %s OR context LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$offset    = ( $page - 1 ) * $per_page;

		// Total count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			empty( $params )
				? "SELECT COUNT(*) FROM {$table} {$where_sql}"
				: $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params )
		);

		// Rows.
		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$query_params
			),
			ARRAY_A
		);

		return [ 'rows' => $rows ?: [], 'total' => $total ];
	}

	/**
	 * Prune log entries older than a given number of days.
	 *
	 * @param int $days Keep entries from the last N days.
	 * @return int Number of rows deleted.
	 */
	public static function prune( int $days = 30 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_event_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
	}
}
