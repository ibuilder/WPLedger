<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Chart of Accounts — default account definitions and seeding.
 *
 * @package WPLedger\Models
 */

namespace WPLedger\Models;

use WPLedger\Db\Schema;

/**
 * Provides the default chart of accounts and seeds it for new companies.
 */
class Coa {

	/**
	 * Default COA rows: [code, name, type, subtype, is_cash].
	 * 1000s = assets, 2000s = liabilities, 3000s = equity,
	 * 4000s = revenue, 5000s+ = expenses.
	 */
	public const DEFAULT_COA = [
		[ '1000', 'Cash - Operating',           'ASSET',     'CURRENT_ASSET',        1 ],
		[ '1010', 'Cash - Savings',              'ASSET',     'CURRENT_ASSET',        1 ],
		[ '1200', 'Accounts Receivable',         'ASSET',     'CURRENT_ASSET',        0 ],
		[ '1300', 'Inventory',                   'ASSET',     'CURRENT_ASSET',        0 ],
		[ '1400', 'Prepaid Expenses',            'ASSET',     'CURRENT_ASSET',        0 ],
		[ '1500', 'Property, Plant & Equipment', 'ASSET',     'NONCURRENT_ASSET',     0 ],
		[ '1510', 'Accumulated Depreciation',    'ASSET',     'NONCURRENT_ASSET',     0 ],
		[ '2000', 'Accounts Payable',            'LIABILITY', 'CURRENT_LIABILITY',    0 ],
		[ '2100', 'Accrued Liabilities',         'LIABILITY', 'CURRENT_LIABILITY',    0 ],
		[ '2200', 'Sales Tax Payable',           'LIABILITY', 'CURRENT_LIABILITY',    0 ],
		[ '2300', 'Unearned Revenue',            'LIABILITY', 'CURRENT_LIABILITY',    0 ],
		[ '2500', 'Long-Term Debt',              'LIABILITY', 'NONCURRENT_LIABILITY', 0 ],
		[ '3000', "Owner's Capital",             'EQUITY',    'EQUITY',               0 ],
		[ '3100', 'Retained Earnings',           'EQUITY',    'EQUITY',               0 ],
		[ '3200', "Owner's Draws",               'EQUITY',    'EQUITY',               0 ],
		[ '4000', 'Sales Revenue',               'REVENUE',   'OPERATING_REVENUE',    0 ],
		[ '4100', 'Service Revenue',             'REVENUE',   'OPERATING_REVENUE',    0 ],
		[ '4900', 'Interest Income',             'REVENUE',   'OTHER_REVENUE',        0 ],
		[ '5000', 'Cost of Goods Sold',          'EXPENSE',   'COGS',                 0 ],
		[ '6000', 'Salaries & Wages',            'EXPENSE',   'OPERATING_EXPENSE',    0 ],
		[ '6100', 'Rent',                        'EXPENSE',   'OPERATING_EXPENSE',    0 ],
		[ '6200', 'Utilities',                   'EXPENSE',   'OPERATING_EXPENSE',    0 ],
		[ '6300', 'Office & Admin',              'EXPENSE',   'OPERATING_EXPENSE',    0 ],
		[ '6400', 'Depreciation Expense',        'EXPENSE',   'OPERATING_EXPENSE',    0 ],
		[ '6900', 'Interest Expense',            'EXPENSE',   'OTHER_EXPENSE',        0 ],
	];

	/**
	 * Insert the default chart of accounts for a given company.
	 *
	 * @param int $company_id Target company ID.
	 * @return void
	 */
	public static function seed_company( int $company_id ): void {
		global $wpdb;
		$accts = Schema::t( 'accounts' );

		foreach ( self::DEFAULT_COA as [ $code, $name, $type, $subtype, $is_cash ] ) {
			$wpdb->insert(
				$accts,
				[
					'company_id' => $company_id,
					'code'       => $code,
					'name'       => $name,
					'type'       => $type,
					'subtype'    => $subtype,
					'is_cash'    => $is_cash,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%d' ]
			);
		}
	}

	/**
	 * Seed a demo company and its chart of accounts if no companies exist yet.
	 *
	 * @return void
	 */
	public static function maybe_seed_default_company(): void {
		global $wpdb;
		$companies = Schema::t( 'companies' );

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$companies}" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		$wpdb->insert( $companies, [ 'name' => 'Demo Company' ], [ '%s' ] );
		self::seed_company( (int) $wpdb->insert_id );
	}
}
