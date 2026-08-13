<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Recurring Journal Entries.
 *
 * Stores template entries (lines_json) and fires them automatically via WP-Cron.
 * Frequencies: monthly, quarterly, annually.
 *
 * @package WPLedger\Services
 */

namespace WPLedger\Services;

use WPLedger\Db\Schema;

/**
 * Manages recurring journal entry templates and their automatic posting.
 */
class Recurring {

	/** WP-Cron hook name. */
	const CRON_HOOK = 'wpledger_post_recurring';

	/**
	 * Register the cron hook and schedule if not already scheduled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_due' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the cron schedule (called on plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Post all recurring entries whose next_run_date is today or in the past.
	 * Advances next_run_date by the entry's frequency after each run.
	 *
	 * @return array{posted:int, skipped:int, errors:string[]} Summary.
	 */
	public function run_due(): array {
		global $wpdb;
		$t     = Schema::t( 'recurring_entries' );
		$today = gmdate( 'Y-m-d' );

		$due = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE is_active = 1 AND next_run_date <= %s",
				$today
			)
		) ?: [];

		$posted  = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $due as $r ) {
			$lines = json_decode( $r->lines_json, true );

			if ( ! is_array( $lines ) || count( $lines ) < 2 ) {
				$errors[] = "#{$r->id} {$r->name}: invalid lines JSON";
				$skipped++;
				continue;
			}

			try {
				Ledger::post_entry(
					(int) $r->company_id,
					$r->next_run_date,
					$lines,
					$r->memo ?: null,
					null,
					'recurring',
					'rec_' . $r->id . '_' . $r->next_run_date
				);
				$posted++;
			} catch ( LedgerException $e ) {
				// Duplicate idempotency key = already posted, not an error.
				if ( false !== strpos( $e->getMessage(), 'Duplicate' ) ) {
					$skipped++;
				} else {
					$errors[] = "#{$r->id} {$r->name}: " . $e->getMessage();
					$skipped++;
				}
			}

			// Advance next_run_date regardless of outcome.
			$next = $this->advance_date( $r->next_run_date, $r->frequency );
			$wpdb->update( $t, [ 'next_run_date' => $next ], [ 'id' => (int) $r->id ], [ '%s' ], [ '%d' ] );
		}

		return compact( 'posted', 'skipped', 'errors' );
	}

	/**
	 * Create a new recurring entry template.
	 *
	 * @param int    $company_id   Company ID.
	 * @param string $name         Human-readable label.
	 * @param string $frequency    'monthly' | 'quarterly' | 'annually'.
	 * @param string $start_date   First run date (ISO).
	 * @param array  $lines        Journal lines (same format as Ledger::post_entry).
	 * @param string $memo         Optional memo.
	 * @return int|false Inserted ID or false on error.
	 */
	public static function create( int $company_id, string $name, string $frequency, string $start_date, array $lines, string $memo = '' ) {
		global $wpdb;

		$valid_freq = [ 'monthly', 'quarterly', 'annually' ];
		if ( ! in_array( $frequency, $valid_freq, true ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			Schema::t( 'recurring_entries' ),
			[
				'company_id'    => $company_id,
				'name'          => $name,
				'frequency'     => $frequency,
				'next_run_date' => $start_date,
				'lines_json'    => wp_json_encode( $lines ),
				'memo'          => $memo ?: null,
				'is_active'     => 1,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Toggle a recurring entry active/inactive.
	 *
	 * @param int $id Recurring entry ID.
	 * @return void
	 */
	public static function toggle( int $id ): void {
		global $wpdb;
		$t       = Schema::t( 'recurring_entries' );
		$current = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT is_active FROM {$t} WHERE id = %d", $id )
		);
		$wpdb->update( $t, [ 'is_active' => $current ? 0 : 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
	}

	/**
	 * Delete a recurring entry permanently.
	 *
	 * @param int $id Recurring entry ID.
	 * @return void
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::t( 'recurring_entries' ), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Get all recurring entries for a company.
	 *
	 * @param int $company_id Company ID.
	 * @return array<object>
	 */
	public static function get_all( int $company_id ): array {
		global $wpdb;
		$t = Schema::t( 'recurring_entries' );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$t} WHERE company_id = %d ORDER BY next_run_date", $company_id )
		) ?: [];
	}

	/**
	 * Advance an ISO date string by the given frequency.
	 *
	 * @param string $date      ISO date (YYYY-MM-DD).
	 * @param string $frequency 'monthly' | 'quarterly' | 'annually'.
	 * @return string Next ISO date.
	 */
	private function advance_date( string $date, string $frequency ): string {
		$ts = strtotime( $date );
		switch ( $frequency ) {
			case 'quarterly':
				return gmdate( 'Y-m-d', strtotime( '+3 months', $ts ) );
			case 'annually':
				return gmdate( 'Y-m-d', strtotime( '+1 year', $ts ) );
			default: // monthly
				return gmdate( 'Y-m-d', strtotime( '+1 month', $ts ) );
		}
	}
}
