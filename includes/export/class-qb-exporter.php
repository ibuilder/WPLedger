<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * QuickBooks Exporter — produces IIF (Desktop) and CSV (Online) export files
 * from the WPLedger general ledger.
 *
 * IIF  — Intuit Interchange Format; imports into QB Desktop Pro/Premier/Enterprise.
 * CSV  — General Journal CSV template; imports into QuickBooks Online.
 *
 * @package WPLedger\Export
 */

namespace WPLedger\Export;

use WPLedger\Db\Schema;

/**
 * Generates QuickBooks-compatible export files from the WPLedger ledger.
 */
class QbExporter {

	/**
	 * Build an IIF string for QB Desktop from journal entries in a date range.
	 *
	 * IIF structure:
	 *   !ACCNT header + ACCNT rows (chart of accounts)
	 *   !TRNS / !SPL / !ENDTRNS headers + transaction rows
	 *
	 * @param int    $company_id Target company.
	 * @param string $start      Period start date (YYYY-MM-DD).
	 * @param string $end        Period end date (YYYY-MM-DD).
	 * @return string Raw IIF text (tab-separated, CRLF line endings).
	 */
	public function export_iif( int $company_id, string $start, string $end ): string {
		global $wpdb;

		$accounts = $this->get_accounts( $company_id );
		$entries  = $this->get_entries( $company_id, $start, $end );

		$lines = [];

		// ---- Chart of accounts ----
		$lines[] = "!ACCNT\tNAME\tACCNTTYPE\tDESC";
		foreach ( $accounts as $a ) {
			$lines[] = implode( "\t", [
				'ACCNT',
				$this->iif_field( $a->code . ' ' . $a->name ),
				$this->iif_account_type( $a->type, $a->subtype ),
				$this->iif_field( $a->name ),
			] );
		}

		// ---- Transactions ----
		$lines[] = '!TRNS	TRNSID	TRNSTYPE	DATE	ACCNT	NAME	AMOUNT	MEMO	DOCNUM';
		$lines[] = '!SPL	SPLID	TRNSTYPE	DATE	ACCNT	NAME	AMOUNT	MEMO';
		$lines[] = '!ENDTRNS';

		foreach ( $entries as $entry ) {
			if ( empty( $entry->lines ) ) {
				continue;
			}

			// QB IIF: the TRNS row is the first line (the "main" side of the entry).
			// Subsequent lines are SPL rows. We use the first debit line as TRNS.
			$first = array_shift( $entry->lines );
			$amt   = bccomp( $first->debit, '0', 2 ) > 0
				? $first->debit
				: '-' . $first->credit;

			$date   = gmdate( 'm/d/Y', strtotime( $entry->entry_date ) );
			$memo   = $this->iif_field( $entry->memo ?? '' );
			$docnum = $this->iif_field( $entry->reference ?? (string) $entry->id );

			$lines[] = implode( "\t", [
				'TRNS',
				(string) $entry->id,
				'GENERAL JOURNAL',
				$date,
				$this->iif_field( $first->code . ' ' . $first->account_name ),
				'',
				number_format( (float) $amt, 2, '.', '' ),
				$memo,
				$docnum,
			] );

			foreach ( $entry->lines as $line ) {
				$spl_amt = bccomp( $line->debit, '0', 2 ) > 0
					? $line->debit
					: '-' . $line->credit;

				$lines[] = implode( "\t", [
					'SPL',
					'',
					'GENERAL JOURNAL',
					$date,
					$this->iif_field( $line->code . ' ' . $line->account_name ),
					'',
					number_format( (float) $spl_amt, 2, '.', '' ),
					$this->iif_field( $line->line_memo ?? '' ),
				] );
			}

			$lines[] = 'ENDTRNS';
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Build a CSV string for QuickBooks Online general journal import.
	 *
	 * QB Online CSV columns (required):
	 *   Journal No, Journal Date, Currency, Account, Debits, Credits, Description
	 *
	 * @param int    $company_id Target company.
	 * @param string $start      Period start date (YYYY-MM-DD).
	 * @param string $end        Period end date (YYYY-MM-DD).
	 * @return string Raw CSV text (UTF-8 with BOM for Excel compatibility).
	 */
	public function export_csv( int $company_id, string $start, string $end ): string {
		$entries = $this->get_entries( $company_id, $start, $end );

		$rows   = [];
		$rows[] = [ 'Journal No', 'Journal Date', 'Currency', 'Account', 'Debits', 'Credits', 'Description', 'Name' ];

		foreach ( $entries as $entry ) {
			$date   = gmdate( 'm/d/Y', strtotime( $entry->entry_date ) );
			$jno    = $entry->reference ?: 'LCE-' . $entry->id;
			$memo   = $entry->memo ?? '';

			foreach ( $entry->lines as $line ) {
				$rows[] = [
					$jno,
					$date,
					'USD',
					$line->code . ' ' . $line->account_name,
					bccomp( $line->debit, '0', 2 ) > 0 ? number_format( (float) $line->debit, 2, '.', '' ) : '',
					bccomp( $line->credit, '0', 2 ) > 0 ? number_format( (float) $line->credit, 2, '.', '' ) : '',
					$line->line_memo ?: $memo,
					'',
				];
			}
		}

		// UTF-8 BOM so Excel opens it correctly.
		$out = "\xEF\xBB\xBF";
		foreach ( $rows as $row ) {
			$out .= implode( ',', array_map( [ $this, 'csv_cell' ], $row ) ) . "\r\n";
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch all accounts for a company.
	 *
	 * @param int $company_id Target company.
	 * @return array<object>
	 */
	private function get_accounts( int $company_id ): array {
		global $wpdb;
		$t = Schema::t( 'accounts' );
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$t} WHERE company_id = %d ORDER BY code",
				$company_id
			)
		) ?: [];
	}

	/**
	 * Fetch journal entries with their lines for a date range.
	 *
	 * Each entry has a ->lines array with code and account_name joined in.
	 *
	 * @param int    $company_id Target company.
	 * @param string $start      Start date (inclusive).
	 * @param string $end        End date (inclusive).
	 * @return array<object>
	 */
	private function get_entries( int $company_id, string $start, string $end ): array {
		global $wpdb;

		$et = Schema::t( 'journal_entries' );
		$lt = Schema::t( 'journal_lines' );
		$at = Schema::t( 'accounts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$et}
				  WHERE company_id = %d AND posted = 1
				    AND entry_date >= %s AND entry_date <= %s
				  ORDER BY entry_date, id",
				$company_id,
				$start,
				$end
			)
		) ?: [];

		foreach ( $entries as $entry ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$entry->lines = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT jl.*, a.code, a.name AS account_name
					   FROM {$lt} jl
					   JOIN {$at} a ON a.id = jl.account_id
					  WHERE jl.entry_id = %d
					  ORDER BY jl.id",
					$entry->id
				)
			) ?: [];
		}

		return $entries;
	}

	/**
	 * Map WPLedger account type/subtype to QB Desktop account type string.
	 *
	 * @param string $type    WPLedger type (ASSET, LIABILITY, etc.).
	 * @param string $subtype WPLedger subtype.
	 * @return string QB IIF ACCNTTYPE value.
	 */
	private function iif_account_type( string $type, string $subtype ): string {
		$map = [
			'CURRENT_ASSET'         => 'BANK',
			'NONCURRENT_ASSET'      => 'FIXASSET',
			'CURRENT_LIABILITY'     => 'CCARD',
			'NONCURRENT_LIABILITY'  => 'LTLIAB',
			'EQUITY'                => 'EQUITY',
			'OPERATING_REVENUE'     => 'INC',
			'OTHER_REVENUE'         => 'OTHERINC',
			'COGS'                  => 'COGS',
			'OPERATING_EXPENSE'     => 'EXP',
			'OTHER_EXPENSE'         => 'OEXP',
		];

		return $map[ $subtype ] ?? ( 'ASSET' === $type ? 'OASSET' : 'OEXP' );
	}

	/**
	 * Escape a value for IIF: strip tabs and newlines.
	 *
	 * @param string $v Raw value.
	 * @return string Safe IIF field value.
	 */
	private function iif_field( string $v ): string {
		return str_replace( [ "\t", "\r", "\n" ], ' ', $v );
	}

	/**
	 * Wrap a CSV cell value in quotes if it contains commas, quotes, or newlines.
	 *
	 * @param string $v Raw value.
	 * @return string Quoted CSV cell.
	 */
	private function csv_cell( string $v ): string {
		$v = str_replace( '"', '""', $v );
		if ( false !== strpbrk( $v, ",\"\r\n" ) ) {
			return '"' . $v . '"';
		}
		return $v;
	}
}
