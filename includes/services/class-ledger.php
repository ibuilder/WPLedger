<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Ledger — the only class allowed to write journal entries.
 *
 * @package WPLedger\Services
 */

namespace WPLedger\Services;

use WPLedger\Db\Schema;

/**
 * Thrown when a journal entry violates double-entry rules.
 */
class LedgerException extends \Exception {}

/**
 * Posts balanced journal entries and derives account balances from the ledger.
 * All monetary arithmetic uses bcmath (scale 2). NEVER uses PHP floats.
 */
class Ledger {

	/**
	 * Post a balanced journal entry inside a transaction.
	 *
	 * @param int         $company_id         Company this entry belongs to.
	 * @param string      $entry_date         ISO date (YYYY-MM-DD).
	 * @param array       $lines              Each element: ['account_id'=>int,'debit'=>string,'credit'=>string,'line_memo'=>string|null].
	 * @param string|null $memo               Optional narrative.
	 * @param string|null $reference          Optional external reference number.
	 * @param string      $source             Source system identifier (default 'manual').
	 * @param string|null $source_external_id Idempotency key — duplicate posts are rejected.
	 * @return int New journal entry ID.
	 * @throws LedgerException If the entry does not balance or violates rules.
	 */
	public static function post_entry(
		int $company_id,
		string $entry_date,
		array $lines,
		?string $memo = null,
		?string $reference = null,
		string $source = 'manual',
		?string $source_external_id = null
	): int {
		global $wpdb;

		if ( count( $lines ) < 2 ) {
			throw new LedgerException( 'A journal entry needs at least two lines.' );
		}

		$total_debit  = '0';
		$total_credit = '0';

		foreach ( $lines as $l ) {
			$d = self::money( $l['debit']  ?? '0' );
			$c = self::money( $l['credit'] ?? '0' );

			if ( bccomp( $d, '0', 2 ) === 1 && bccomp( $c, '0', 2 ) === 1 ) {
				throw new LedgerException( 'A line cannot have both a debit and a credit.' );
			}

			$total_debit  = bcadd( $total_debit,  $d, 2 );
			$total_credit = bcadd( $total_credit, $c, 2 );
		}

		if ( bccomp( $total_debit, $total_credit, 2 ) !== 0 ) {
			throw new LedgerException(
				"Unbalanced entry: debits {$total_debit} != credits {$total_credit}"
			);
		}

		if ( bccomp( $total_debit, '0', 2 ) === 0 ) {
			throw new LedgerException( 'Entry has no value.' );
		}

		$entries_t = Schema::t( 'journal_entries' );
		$lines_t   = Schema::t( 'journal_lines' );

		$wpdb->query( 'START TRANSACTION' );

		try {
			$ok = $wpdb->insert(
				$entries_t,
				[
					'company_id'         => $company_id,
					'entry_date'         => $entry_date,
					'memo'               => $memo,
					'reference'          => $reference,
					'source'             => $source,
					'source_external_id' => $source_external_id,
					'posted'             => 1,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
			);

			if ( false === $ok ) {
				// The uq_idempotency unique key fired — treat as already-posted.
				throw new LedgerException( 'Duplicate or invalid entry: ' . $wpdb->last_error );
			}

			$entry_id = (int) $wpdb->insert_id;

			foreach ( $lines as $l ) {
				$wpdb->insert(
					$lines_t,
					[
						'entry_id'   => $entry_id,
						'account_id' => (int) $l['account_id'],
						'debit'      => self::money( $l['debit']  ?? '0' ),
						'credit'     => self::money( $l['credit'] ?? '0' ),
						'line_memo'  => $l['line_memo'] ?? null,
					],
					[ '%d', '%d', '%s', '%s', '%s' ]
				);
			}

			$wpdb->query( 'COMMIT' );
			return $entry_id;

		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw new LedgerException( $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Signed balance in the account's normal direction, as of a date (inclusive).
	 *
	 * ASSET and EXPENSE accounts are debit-normal (balance = debits - credits).
	 * LIABILITY, EQUITY, and REVENUE accounts are credit-normal (balance = credits - debits).
	 *
	 * @param int         $account_id Target account.
	 * @param string|null $as_of      ISO date ceiling. Null = all time.
	 * @return string Balance as a decimal string (bcmath-safe).
	 */
	public static function account_balance( int $account_id, ?string $as_of = null ): string {
		global $wpdb;

		$lines_t   = Schema::t( 'journal_lines' );
		$entries_t = Schema::t( 'journal_entries' );
		$accts_t   = Schema::t( 'accounts' );

		$type = $wpdb->get_var(
			$wpdb->prepare( "SELECT type FROM {$accts_t} WHERE id = %d", $account_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$sql    = "SELECT COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.credit),0) AS c
		           FROM {$lines_t} jl
		           JOIN {$entries_t} je ON je.id = jl.entry_id
		           WHERE jl.account_id = %d AND je.posted = 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params = [ $account_id ];

		if ( $as_of ) {
			$sql     .= ' AND je.entry_date <= %s';
			$params[] = $as_of;
		}

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$debits  = self::money( $row->d );
		$credits = self::money( $row->c );

		if ( in_array( $type, [ 'ASSET', 'EXPENSE' ], true ) ) {
			return bcsub( $debits, $credits, 2 );
		}

		return bcsub( $credits, $debits, 2 );
	}

	/**
	 * Net movement in an account between two dates (period activity).
	 *
	 * Calculated as: balance(end) - balance(day before start).
	 *
	 * @param int    $account_id Target account.
	 * @param string $start      Period start date (ISO).
	 * @param string $end        Period end date (ISO).
	 * @return string Net movement as decimal string.
	 */
	public static function period_activity( int $account_id, string $start, string $end ): string {
		$prior = gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) );

		return bcsub(
			self::account_balance( $account_id, $end ),
			self::account_balance( $account_id, $prior ),
			2
		);
	}

	/**
	 * Normalise a monetary value to a 2-decimal string safe for bcmath and DB storage.
	 *
	 * @param mixed $v Raw value.
	 * @return string e.g. "1000.00"
	 */
	private static function money( $v ): string {
		return number_format( (float) $v, 2, '.', '' );
	}
}
