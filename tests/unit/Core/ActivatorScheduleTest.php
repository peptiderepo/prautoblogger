<?php
/**
 * Tests for `PRAutoBlogger_Activator::next_daily_generation_timestamp()` —
 * the v0.8.2 timezone-aware replacement for `strtotime("tomorrow HH:MM")`
 * which evaluated in UTC regardless of the WordPress site timezone.
 *
 * See thread scheduled-gen-timezone/01-cto-handoff.md for the bug report.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ActivatorScheduleTest extends BaseTestCase {

	/**
	 * Stub `wp_timezone()` to return the given tz id, and seed
	 * `prautoblogger_schedule_time` with the given value.
	 */
	private function with_site_tz_and_time( string $tz_id, string $time_str ): void {
		$this->stub_get_option( [
			'prautoblogger_schedule_time' => $time_str,
		] );
		Functions\when( 'wp_timezone' )->alias(
			static fn() => new \DateTimeZone( $tz_id )
		);
	}

	/**
	 * On an Asia/Singapore site (UTC+8), `schedule_time = 06:00` must
	 * resolve to 22:00 UTC the previous day — the prod bug from thread
	 * scheduled-gen-timezone seq 1.
	 */
	public function test_schedule_cron_uses_site_timezone(): void {
		$this->with_site_tz_and_time( 'Asia/Singapore', '06:00' );

		$timestamp = \PRAutoBlogger_Activator::next_daily_generation_timestamp();

		$this->assertGreaterThan( 0, $timestamp );
		$utc = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$this->assertSame( '22:00', $utc->format( 'H:i' ), 'Expected 06:00 SGT == 22:00 UTC previous day' );

		// And it really is "tomorrow" in Singapore — date part should not be today SGT.
		$sgt = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'Asia/Singapore' ) );
		$tomorrow_sgt = ( new \DateTimeImmutable( 'tomorrow', new \DateTimeZone( 'Asia/Singapore' ) ) )->format( 'Y-m-d' );
		$this->assertSame( $tomorrow_sgt, $sgt->format( 'Y-m-d' ) );
	}

	/**
	 * UTC site, `06:00` input → 06:00 UTC. Behaviour unchanged for
	 * the always-UTC case; regression guard.
	 */
	public function test_schedule_cron_handles_utc_site(): void {
		$this->with_site_tz_and_time( 'UTC', '06:00' );

		$timestamp = \PRAutoBlogger_Activator::next_daily_generation_timestamp();

		$utc = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$this->assertSame( '06:00', $utc->format( 'H:i' ) );
	}

	/**
	 * Nepal runs UTC+5:45. 06:00 local → 00:15 UTC. Guards against any
	 * integer-hour-offset assumptions in the conversion logic.
	 */
	public function test_schedule_cron_handles_fractional_offset(): void {
		$this->with_site_tz_and_time( 'Asia/Kathmandu', '06:00' );

		$timestamp = \PRAutoBlogger_Activator::next_daily_generation_timestamp();

		$utc = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$this->assertSame( '00:15', $utc->format( 'H:i' ) );
	}

	/**
	 * Malformed `schedule_time` (e.g. blank string saved accidentally)
	 * must not throw — `absint()` clamps to 0:0 and the helper returns
	 * a valid timestamp. Regression guard against the "bricked schedule
	 * on bad input" failure mode.
	 */
	public function test_schedule_cron_survives_malformed_time_input(): void {
		$this->with_site_tz_and_time( 'UTC', '' );

		$timestamp = \PRAutoBlogger_Activator::next_daily_generation_timestamp();

		$this->assertGreaterThan( 0, $timestamp );
	}
}
