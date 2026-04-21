<?php
/**
 * Tests for the v0.8.2 one-shot migration that reschedules the daily
 * generation cron in the site's configured timezone. Exercises both
 * the "first run" path and the "already migrated, no-op" path.
 *
 * See thread scheduled-gen-timezone/01-cto-handoff.md.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ActivatorMigrationTest extends BaseTestCase {

	/**
	 * Track cron scheduling side effects so we can assert on them.
	 *
	 * @var array<string, array{scheduled_at: int|null, recurrence: string|null, cleared: bool}>
	 */
	private array $cron_state = [];

	/**
	 * Track the v0.8.2 migration flag across get_option/update_option calls.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->cron_state = [
			'prautoblogger_daily_generation' => [
				'scheduled_at' => null,
				'recurrence'   => null,
				'cleared'      => false,
			],
		];

		// Start with a pre-existing cron simulating the v<0.8.2 UTC-interpreted
		// schedule, so the migration has something to clear.
		$this->cron_state['prautoblogger_daily_generation']['scheduled_at'] = strtotime( 'tomorrow 03:00 UTC' );
		$this->cron_state['prautoblogger_daily_generation']['recurrence']   = 'daily';

		// Baseline options.
		$this->options = [
			'prautoblogger_schedule_time' => '06:00',
			'prautoblogger_log_level'     => 'info',
		];

		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'wp_next_scheduled' )->alias(
			fn( $hook ) => $this->cron_state[ $hook ]['scheduled_at'] ?? false
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				if ( isset( $this->cron_state[ $hook ] ) ) {
					$this->cron_state[ $hook ]['scheduled_at'] = null;
					$this->cron_state[ $hook ]['cleared']      = true;
				}
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) {
				$this->cron_state[ $hook ]['scheduled_at'] = (int) $timestamp;
				$this->cron_state[ $hook ]['recurrence']   = (string) $recurrence;
				return true;
			}
		);

		Functions\when( 'wp_timezone' )->alias(
			static fn() => new \DateTimeZone( 'Asia/Singapore' )
		);
	}

	/**
	 * Migration clears the UTC-scheduled event and reschedules in SGT.
	 * Flag is set so the next pass is a no-op.
	 */
	public function test_db_version_migration_reschedules_existing_cron(): void {
		$before_timestamp = $this->cron_state['prautoblogger_daily_generation']['scheduled_at'];
		$this->assertNotNull( $before_timestamp, 'Precondition: stale cron was pre-seeded.' );

		\PRAutoBlogger_Activator::reschedule_daily_in_site_timezone_v082();

		// Hook was cleared and then re-scheduled in one pass.
		$this->assertTrue( $this->cron_state['prautoblogger_daily_generation']['cleared'] );
		$after = $this->cron_state['prautoblogger_daily_generation']['scheduled_at'];
		$this->assertNotNull( $after );
		$this->assertNotSame( $before_timestamp, $after, 'Migration should produce a different timestamp.' );

		// Re-scheduled timestamp maps to 06:00 SGT (22:00 UTC prev day).
		$utc = ( new \DateTimeImmutable( '@' . $after ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$this->assertSame( '22:00', $utc->format( 'H:i' ) );

		// Flag was set.
		$this->assertSame( '1', $this->options['prautoblogger_migrated_schedule_tz_v082'] ?? null );
	}

	/**
	 * Running the migration twice is idempotent — the second pass must
	 * return immediately without clearing or rescheduling.
	 */
	public function test_db_version_migration_idempotent(): void {
		\PRAutoBlogger_Activator::reschedule_daily_in_site_timezone_v082();

		// Reset the cleared marker so we can detect a second clear.
		$this->cron_state['prautoblogger_daily_generation']['cleared'] = false;
		$timestamp_after_first = $this->cron_state['prautoblogger_daily_generation']['scheduled_at'];

		\PRAutoBlogger_Activator::reschedule_daily_in_site_timezone_v082();

		// No second clear, no re-schedule.
		$this->assertFalse( $this->cron_state['prautoblogger_daily_generation']['cleared'] );
		$this->assertSame(
			$timestamp_after_first,
			$this->cron_state['prautoblogger_daily_generation']['scheduled_at']
		);
	}
}
