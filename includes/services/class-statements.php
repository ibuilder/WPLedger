<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Statements — Balance Sheet, Income Statement, Cash Flow Statement.
 *
 * All three statements read the same general ledger via Ledger::account_balance
 * and Ledger::period_activity. They cannot disagree.
 *
 * @package WPLedger\Services
 */

namespace WPLedger\Services;

use WPLedger\Db\Schema;

/**
 * Generates the three core financial statements from the ledger.
 * All monetary arithmetic uses bcmath (scale 2).
 */
class Statements {

	/**
	 * Fetch all active accounts for a company.
	 *
	 * @param int $company_id Target company.
	 * @return array Array of stdClass account rows.
	 */
	private static function accounts( int $company_id ): array {
		global $wpdb;
		$t = Schema::t( 'accounts' );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$t} WHERE company_id = %d", $company_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * INCOME STATEMENT: revenue minus expenses over a period.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $start      Period start date (ISO).
	 * @param string $end        Period end date (ISO).
	 * @return array Structured income statement data.
	 */
	public static function income_statement( int $company_id, string $start, string $end ): array {
		$accts = self::accounts( $company_id );

		$section = function ( array $subtypes ) use ( $accts, $start, $end ) {
			$rows  = [];
			$total = '0';

			foreach ( $accts as $a ) {
				if ( in_array( $a->subtype, $subtypes, true ) ) {
					$amt = Ledger::period_activity( (int) $a->id, $start, $end );

					if ( bccomp( $amt, '0', 2 ) !== 0 ) {
						$rows[] = [ 'code' => $a->code, 'name' => $a->name, 'amount' => $amt ];
						$total  = bcadd( $total, $amt, 2 );
					}
				}
			}

			return [ $rows, $total ];
		};

		[ $rev_rows,  $rev_t  ] = $section( [ 'OPERATING_REVENUE' ] );
		[ $cogs_rows, $cogs_t ] = $section( [ 'COGS' ] );
		[ $opex_rows, $opex_t ] = $section( [ 'OPERATING_EXPENSE' ] );
		[ $orev_rows, $orev_t ] = $section( [ 'OTHER_REVENUE' ] );
		[ $oexp_rows, $oexp_t ] = $section( [ 'OTHER_EXPENSE' ] );

		$gross_profit     = bcsub( $rev_t, $cogs_t, 2 );
		$operating_income = bcsub( $gross_profit, $opex_t, 2 );
		$net_income       = bcsub( bcadd( $operating_income, $orev_t, 2 ), $oexp_t, 2 );

		return [
			'period'             => [ 'start' => $start, 'end' => $end ],
			'revenue'            => [ 'rows' => $rev_rows,  'total' => $rev_t  ],
			'cogs'               => [ 'rows' => $cogs_rows, 'total' => $cogs_t ],
			'gross_profit'       => $gross_profit,
			'operating_expenses' => [ 'rows' => $opex_rows, 'total' => $opex_t ],
			'operating_income'   => $operating_income,
			'other_income'       => [ 'rows' => $orev_rows, 'total' => $orev_t ],
			'other_expense'      => [ 'rows' => $oexp_rows, 'total' => $oexp_t ],
			'net_income'         => $net_income,
		];
	}

	/**
	 * BALANCE SHEET: point-in-time snapshot.
	 *
	 * Equity includes current-year (unclosed) earnings via income_statement.
	 * Exposes a `balanced` flag: Assets must equal Liabilities + Equity.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $as_of      Snapshot date (ISO).
	 * @param string $fy_start   First day of the current fiscal year (ISO).
	 * @return array Structured balance sheet data with 'balanced' flag.
	 */
	public static function balance_sheet( int $company_id, string $as_of, string $fy_start ): array {
		$accts = self::accounts( $company_id );

		$section = function ( array $subtypes ) use ( $accts, $as_of ) {
			$rows  = [];
			$total = '0';

			foreach ( $accts as $a ) {
				if ( in_array( $a->subtype, $subtypes, true ) ) {
					$bal = Ledger::account_balance( (int) $a->id, $as_of );

					if ( bccomp( $bal, '0', 2 ) !== 0 ) {
						$rows[] = [ 'code' => $a->code, 'name' => $a->name, 'amount' => $bal ];
						$total  = bcadd( $total, $bal, 2 );
					}
				}
			}

			return [ $rows, $total ];
		};

		[ $ca_rows,  $ca_t  ] = $section( [ 'CURRENT_ASSET' ] );
		[ $nca_rows, $nca_t ] = $section( [ 'NONCURRENT_ASSET' ] );
		[ $cl_rows,  $cl_t  ] = $section( [ 'CURRENT_LIABILITY' ] );
		[ $ncl_rows, $ncl_t ] = $section( [ 'NONCURRENT_LIABILITY' ] );
		[ $eq_rows,  $eq_t  ] = $section( [ 'EQUITY' ] );

		// Current-year earnings not yet closed into Retained Earnings.
		$ytd = self::income_statement( $company_id, $fy_start, $as_of )['net_income'];

		$total_assets      = bcadd( $ca_t, $nca_t, 2 );
		$total_liabilities = bcadd( $cl_t, $ncl_t, 2 );
		$total_equity      = bcadd( $eq_t, $ytd, 2 );
		$liab_and_equity   = bcadd( $total_liabilities, $total_equity, 2 );

		return [
			'as_of'                  => $as_of,
			'current_assets'         => [ 'rows' => $ca_rows,  'total' => $ca_t  ],
			'noncurrent_assets'      => [ 'rows' => $nca_rows, 'total' => $nca_t ],
			'total_assets'           => $total_assets,
			'current_liabilities'    => [ 'rows' => $cl_rows,  'total' => $cl_t  ],
			'noncurrent_liabilities' => [ 'rows' => $ncl_rows, 'total' => $ncl_t ],
			'total_liabilities'      => $total_liabilities,
			'equity'                 => [ 'rows' => $eq_rows,  'total' => $eq_t  ],
			'current_year_earnings'  => $ytd,
			'total_equity'           => $total_equity,
			'total_liab_and_equity'  => $liab_and_equity,
			'balanced'               => bccomp( $total_assets, $liab_and_equity, 2 ) === 0,
		];
	}

	/**
	 * CASH FLOW STATEMENT (indirect method).
	 *
	 * Derives operating/investing/financing cash flows from ledger movements.
	 * Exposes a `reconciles` flag: net_change_in_cash must equal actual change
	 * in cash-flagged accounts.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $start      Period start date (ISO).
	 * @param string $end        Period end date (ISO).
	 * @return array Structured cash flow data with 'reconciles' flag.
	 */
	public static function cash_flow_statement( int $company_id, string $start, string $end ): array {
		$accts = self::accounts( $company_id );
		$prior = gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) );

		$change = function ( $a ) use ( $end, $prior ) {
			return bcsub(
				Ledger::account_balance( (int) $a->id, $end ),
				Ledger::account_balance( (int) $a->id, $prior ),
				2
			);
		};

		$inc        = self::income_statement( $company_id, $start, $end );
		$net_income = $inc['net_income'];

		// Operating: start from net income then adjust for non-cash and working capital.
		$operating = [ [ 'label' => 'Net income', 'amount' => $net_income ] ];

		// Add back non-cash depreciation (account 6400).
		foreach ( $accts as $a ) {
			if ( '6400' === $a->code ) {
				$d = Ledger::period_activity( (int) $a->id, $start, $end );

				if ( bccomp( $d, '0', 2 ) !== 0 ) {
					$operating[] = [ 'label' => 'Depreciation', 'amount' => $d ];
				}
			}
		}

		// Working-capital changes (non-cash current assets/liabilities).
		foreach ( $accts as $a ) {
			if ( $a->is_cash ) {
				continue;
			}

			$delta = $change( $a );

			if ( bccomp( $delta, '0', 2 ) === 0 ) {
				continue;
			}

			if ( 'CURRENT_ASSET' === $a->subtype ) {
				// Asset up ⟹ cash down.
				$operating[] = [
					'label'  => 'Change in ' . $a->name,
					'amount' => bcmul( $delta, '-1', 2 ),
				];
			} elseif ( 'CURRENT_LIABILITY' === $a->subtype ) {
				// Liability up ⟹ cash up.
				$operating[] = [ 'label' => 'Change in ' . $a->name, 'amount' => $delta ];
			}
		}

		$operating_total = self::sum( $operating );

		// Investing: changes in non-current assets (excluding cash).
		$investing = [];

		foreach ( $accts as $a ) {
			if ( 'NONCURRENT_ASSET' === $a->subtype && ! $a->is_cash ) {
				$delta = $change( $a );

				if ( bccomp( $delta, '0', 2 ) !== 0 ) {
					$investing[] = [
						'label'  => 'Change in ' . $a->name,
						'amount' => bcmul( $delta, '-1', 2 ),
					];
				}
			}
		}

		$investing_total = self::sum( $investing );

		// Financing: changes in non-current liabilities and equity.
		$financing = [];

		foreach ( $accts as $a ) {
			if ( in_array( $a->subtype, [ 'NONCURRENT_LIABILITY', 'EQUITY' ], true ) ) {
				$delta = $change( $a );

				if ( bccomp( $delta, '0', 2 ) !== 0 ) {
					$financing[] = [ 'label' => 'Change in ' . $a->name, 'amount' => $delta ];
				}
			}
		}

		$financing_total = self::sum( $financing );

		$net_change = bcadd( bcadd( $operating_total, $investing_total, 2 ), $financing_total, 2 );

		// Reconcile against actual cash-account movement.
		$beginning_cash = '0';
		$ending_cash    = '0';

		foreach ( $accts as $a ) {
			if ( $a->is_cash ) {
				$beginning_cash = bcadd( $beginning_cash, Ledger::account_balance( (int) $a->id, $prior ), 2 );
				$ending_cash    = bcadd( $ending_cash,    Ledger::account_balance( (int) $a->id, $end ),   2 );
			}
		}

		$actual = bcsub( $ending_cash, $beginning_cash, 2 );

		return [
			'period'             => [ 'start' => $start, 'end' => $end ],
			'operating'          => [ 'rows' => $operating, 'total' => $operating_total ],
			'investing'          => [ 'rows' => $investing, 'total' => $investing_total ],
			'financing'          => [ 'rows' => $financing, 'total' => $financing_total ],
			'net_change_in_cash' => $net_change,
			'beginning_cash'     => $beginning_cash,
			'ending_cash'        => $ending_cash,
			'reconciles'         => bccomp( $net_change, $actual, 2 ) === 0,
		];
	}

	/**
	 * TRIAL BALANCE: net balance of every account as of a date.
	 *
	 * Each account's net balance appears in the Debit or Credit column
	 * depending on whether it carries a normal debit or credit balance.
	 * Total debits must equal total credits.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $as_of      Snapshot date (ISO).
	 * @return array Structured trial balance data with 'balanced' flag.
	 */
	public static function trial_balance( int $company_id, string $as_of ): array {
		global $wpdb;
		$at = Schema::t( 'accounts' );
		$et = Schema::t( 'journal_entries' );
		$lt = Schema::t( 'journal_lines' );

		// Single query: raw debit and credit totals per account through $as_of.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT a.id, a.code, a.name, a.type,
				        COALESCE(SUM(jl.debit), 0)  AS total_debit,
				        COALESCE(SUM(jl.credit), 0) AS total_credit
				   FROM {$at} a
				   LEFT JOIN {$lt} jl  ON jl.account_id = a.id
				   LEFT JOIN {$et} je  ON je.id = jl.entry_id
				                      AND je.posted = 1
				                      AND je.entry_date <= %s
				  WHERE a.company_id = %d
				  GROUP BY a.id
				  HAVING total_debit > 0 OR total_credit > 0
				  ORDER BY a.code",
				$as_of,
				$company_id
			)
		) ?: [];

		$total_debits  = '0';
		$total_credits = '0';
		$formatted     = [];

		foreach ( $rows as $r ) {
			$d = number_format( (float) $r->total_debit,  2, '.', '' );
			$c = number_format( (float) $r->total_credit, 2, '.', '' );

			// Net balance in normal-balance direction.
			$is_debit_normal = in_array( $r->type, [ 'ASSET', 'EXPENSE' ], true );
			$net = $is_debit_normal ? bcsub( $d, $c, 2 ) : bcsub( $c, $d, 2 );

			// Positive net → normal column; negative net → opposite column (contra).
			if ( bccomp( $net, '0', 2 ) >= 0 ) {
				$debit_col  = $is_debit_normal ? $net : '0.00';
				$credit_col = $is_debit_normal ? '0.00' : $net;
			} else {
				$abs        = bcmul( $net, '-1', 2 );
				$debit_col  = $is_debit_normal ? '0.00' : $abs;
				$credit_col = $is_debit_normal ? $abs : '0.00';
			}

			$total_debits  = bcadd( $total_debits,  $debit_col,  2 );
			$total_credits = bcadd( $total_credits, $credit_col, 2 );

			$formatted[] = [
				'code'   => $r->code,
				'name'   => $r->name,
				'type'   => $r->type,
				'debit'  => $debit_col,
				'credit' => $credit_col,
			];
		}

		return [
			'as_of'         => $as_of,
			'rows'          => $formatted,
			'total_debits'  => $total_debits,
			'total_credits' => $total_credits,
			'balanced'      => bccomp( $total_debits, $total_credits, 2 ) === 0,
		];
	}

	/**
	 * GENERAL LEDGER: per-account transaction history with running balance.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $start      Period start date (ISO).
	 * @param string $end        Period end date (ISO).
	 * @param int    $account_id Specific account to filter (0 = all accounts).
	 * @return array Structured general ledger data.
	 */
	public static function general_ledger( int $company_id, string $start, string $end, int $account_id = 0 ): array {
		global $wpdb;
		$at = Schema::t( 'accounts' );
		$et = Schema::t( 'journal_entries' );
		$lt = Schema::t( 'journal_lines' );

		if ( $account_id ) {
			$accts = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM {$at} WHERE id = %d AND company_id = %d ORDER BY code", $account_id, $company_id )
			) ?: [];
		} else {
			$accts = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM {$at} WHERE company_id = %d ORDER BY code", $company_id )
			) ?: [];
		}

		$prior  = gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) );
		$ledger = [];

		foreach ( $accts as $a ) {
			$opening = Ledger::account_balance( (int) $a->id, $prior );

			$txns = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"SELECT je.entry_date, je.id AS entry_id, je.memo, je.reference,
					        jl.debit, jl.credit, jl.line_memo
					   FROM {$lt} jl
					   JOIN {$et} je ON je.id = jl.entry_id
					  WHERE jl.account_id = %d AND je.posted = 1
					    AND je.entry_date >= %s AND je.entry_date <= %s
					  ORDER BY je.entry_date, je.id",
					(int) $a->id, $start, $end
				)
			) ?: [];

			if ( empty( $txns ) && bccomp( $opening, '0', 2 ) === 0 ) {
				continue;
			}

			$is_debit_normal = in_array( $a->type, [ 'ASSET', 'EXPENSE' ], true );
			$running         = $opening;
			$rows            = [];

			foreach ( $txns as $t ) {
				$d        = number_format( (float) $t->debit,  2, '.', '' );
				$c        = number_format( (float) $t->credit, 2, '.', '' );
				$movement = $is_debit_normal ? bcsub( $d, $c, 2 ) : bcsub( $c, $d, 2 );
				$running  = bcadd( $running, $movement, 2 );

				$rows[] = [
					'date'      => $t->entry_date,
					'entry_id'  => (int) $t->entry_id,
					'memo'      => $t->line_memo ?: ( $t->memo ?? '' ),
					'reference' => $t->reference ?? '',
					'debit'     => bccomp( $d, '0', 2 ) > 0 ? $d : '',
					'credit'    => bccomp( $c, '0', 2 ) > 0 ? $c : '',
					'balance'   => $running,
				];
			}

			$ledger[] = [
				'code'    => $a->code,
				'name'    => $a->name,
				'type'    => $a->type,
				'opening' => $opening,
				'rows'    => $rows,
				'closing' => Ledger::account_balance( (int) $a->id, $end ),
			];
		}

		return [
			'period' => [ 'start' => $start, 'end' => $end ],
			'ledger' => $ledger,
		];
	}

	/**
	 * Sum the 'amount' field of an array of labelled rows.
	 *
	 * @param array $rows Rows with 'amount' key.
	 * @return string Total as decimal string.
	 */
	private static function sum( array $rows ): string {
		$t = '0';

		foreach ( $rows as $r ) {
			$t = bcadd( $t, $r['amount'], 2 );
		}

		return $t;
	}
}
