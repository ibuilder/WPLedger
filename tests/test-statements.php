<?php
/**
 * Unit tests for the Statements service.
 *
 * Scenario: owner investment, credit sale, customer payment, rent, equipment
 * purchase, depreciation — verifies net income, balance sheet balanced flag,
 * and cash flow reconciliation.
 *
 * @package WPLedger
 */

use WPLedger\Services\Ledger;
use WPLedger\Services\Statements;
use WPLedger\Db\Schema;
use WPLedger\Models\Coa;

/**
 * Tests for Statements::income_statement(), balance_sheet(), and cash_flow_statement().
 */
class Test_Statements extends WP_UnitTestCase {

	private int $cid;

	// Account IDs.
	private int $a_cash;
	private int $a_ar;
	private int $a_ppe;
	private int $a_accum;
	private int $a_cap;
	private int $a_rev;
	private int $a_rent;
	private int $a_depr;

	private string $period_start = '2024-01-01';
	private string $period_end   = '2024-01-31';
	private string $fy_start     = '2024-01-01';

	/**
	 * Create a fresh company, seed the COA, and post the standard scenario entries.
	 *
	 * All tests in this class share these entries — each test reads but never writes.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::create();

		global $wpdb;
		$wpdb->insert(
			Schema::t( 'companies' ),
			[ 'name' => 'Statements Test Co', 'fiscal_year_start_month' => 1 ],
			[ '%s', '%d' ]
		);
		$this->cid = (int) $wpdb->insert_id;
		Coa::seed_company( $this->cid );

		$this->a_cash  = $this->acct( '1000' ); // Cash (is_cash = 1)
		$this->a_ar    = $this->acct( '1200' ); // Accounts Receivable
		$this->a_ppe   = $this->acct( '1500' ); // PP&E
		$this->a_accum = $this->acct( '1510' ); // Accumulated Depreciation (contra-asset)
		$this->a_cap   = $this->acct( '3000' ); // Owner's Capital
		$this->a_rev   = $this->acct( '4000' ); // Sales Revenue
		$this->a_rent  = $this->acct( '6100' ); // Rent Expense
		$this->a_depr  = $this->acct( '6400' ); // Depreciation Expense

		// 1. Owner invests $10,000 cash.
		Ledger::post_entry( $this->cid, '2024-01-01', [
			[ 'account_id' => $this->a_cash, 'debit' => '10000', 'credit' => '0' ],
			[ 'account_id' => $this->a_cap,  'debit' => '0',     'credit' => '10000' ],
		], 'Owner investment' );

		// 2. Credit sale $5,000.
		Ledger::post_entry( $this->cid, '2024-01-05', [
			[ 'account_id' => $this->a_ar,  'debit' => '5000', 'credit' => '0' ],
			[ 'account_id' => $this->a_rev, 'debit' => '0',    'credit' => '5000' ],
		], 'Credit sale' );

		// 3. Customer pays $5,000 (AR collected into cash).
		Ledger::post_entry( $this->cid, '2024-01-10', [
			[ 'account_id' => $this->a_cash, 'debit' => '5000', 'credit' => '0' ],
			[ 'account_id' => $this->a_ar,   'debit' => '0',    'credit' => '5000' ],
		], 'Customer payment' );

		// 4. Rent expense $1,200 paid in cash.
		Ledger::post_entry( $this->cid, '2024-01-15', [
			[ 'account_id' => $this->a_rent, 'debit' => '1200', 'credit' => '0' ],
			[ 'account_id' => $this->a_cash, 'debit' => '0',    'credit' => '1200' ],
		], 'January rent' );

		// 5. Cash equipment purchase $3,000 (investing outflow).
		Ledger::post_entry( $this->cid, '2024-01-20', [
			[ 'account_id' => $this->a_ppe,  'debit' => '3000', 'credit' => '0' ],
			[ 'account_id' => $this->a_cash, 'debit' => '0',    'credit' => '3000' ],
		], 'Equipment purchase' );

		// 6. Depreciation $100 (non-cash operating expense).
		Ledger::post_entry( $this->cid, '2024-01-31', [
			[ 'account_id' => $this->a_depr,  'debit' => '100', 'credit' => '0' ],
			[ 'account_id' => $this->a_accum, 'debit' => '0',   'credit' => '100' ],
		], 'January depreciation' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function acct( string $code ): int {
		global $wpdb;
		$t  = Schema::t( 'accounts' );
		$id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$t} WHERE company_id = %d AND code = %s", $this->cid, $code ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertNotNull( $id, "Account {$code} not found for company {$this->cid}" );
		return (int) $id;
	}

	// -------------------------------------------------------------------------
	// Income Statement tests
	// -------------------------------------------------------------------------

	/**
	 * Net income = Revenue 5000 − Rent 1200 − Depreciation 100 = 3700.
	 */
	public function test_net_income(): void {
		$is = Statements::income_statement( $this->cid, $this->period_start, $this->period_end );
		$this->assertSame( '3700.00', $is['net_income'] );
	}

	public function test_revenue_total(): void {
		$is = Statements::income_statement( $this->cid, $this->period_start, $this->period_end );
		$this->assertSame( '5000.00', $is['revenue']['total'] );
	}

	public function test_operating_expenses_total(): void {
		// Rent 1200 + Depreciation 100 = 1300.
		$is = Statements::income_statement( $this->cid, $this->period_start, $this->period_end );
		$this->assertSame( '1300.00', $is['operating_expenses']['total'] );
	}

	// -------------------------------------------------------------------------
	// Balance Sheet tests
	// -------------------------------------------------------------------------

	/**
	 * The `balanced` flag must be true: Assets = Liabilities + Equity.
	 */
	public function test_balance_sheet_is_balanced(): void {
		$bs = Statements::balance_sheet( $this->cid, $this->period_end, $this->fy_start );
		$this->assertTrue( $bs['balanced'], 'Balance sheet balanced flag must be true' );
	}

	/**
	 * Current year earnings on the balance sheet must equal income statement net income.
	 */
	public function test_current_year_earnings_matches_net_income(): void {
		$is  = Statements::income_statement( $this->cid, $this->period_start, $this->period_end );
		$bs  = Statements::balance_sheet( $this->cid, $this->period_end, $this->fy_start );
		$this->assertSame( $is['net_income'], $bs['current_year_earnings'] );
	}

	/**
	 * Cash balance: 10000 + 5000 − 1200 − 3000 = 10800.
	 */
	public function test_cash_balance_on_balance_sheet(): void {
		$bs       = Statements::balance_sheet( $this->cid, $this->period_end, $this->fy_start );
		$cash_row = null;

		foreach ( $bs['current_assets']['rows'] as $r ) {
			if ( '1000' === $r['code'] ) {
				$cash_row = $r;
				break;
			}
		}

		$this->assertNotNull( $cash_row, 'Cash account must appear in current assets' );
		$this->assertSame( '10800.00', $cash_row['amount'] );
	}

	// -------------------------------------------------------------------------
	// Cash Flow Statement tests
	// -------------------------------------------------------------------------

	/**
	 * The `reconciles` flag must be true.
	 */
	public function test_cash_flow_reconciles(): void {
		$cf = Statements::cash_flow_statement( $this->cid, $this->period_start, $this->period_end );
		$this->assertTrue( $cf['reconciles'], "Cash flow reconciles flag must be true. Net change={$cf['net_change_in_cash']}" );
	}

	/**
	 * Ending cash must equal 10800 (same as balance sheet cash).
	 */
	public function test_ending_cash(): void {
		$cf = Statements::cash_flow_statement( $this->cid, $this->period_start, $this->period_end );
		$this->assertSame( '10800.00', $cf['ending_cash'] );
	}

	/**
	 * Depreciation (non-cash) must appear as an add-back in operating activities.
	 */
	public function test_depreciation_addback_in_operating(): void {
		$cf   = Statements::cash_flow_statement( $this->cid, $this->period_start, $this->period_end );
		$rows = array_filter( $cf['operating']['rows'], fn( $r ) => 'Depreciation' === $r['label'] );
		$this->assertCount( 1, $rows, 'Depreciation add-back must appear in operating activities' );
	}

	/**
	 * The equipment purchase must appear in investing activities (PP&E change).
	 */
	public function test_equipment_purchase_in_investing(): void {
		$cf   = Statements::cash_flow_statement( $this->cid, $this->period_start, $this->period_end );
		$rows = array_filter(
			$cf['investing']['rows'],
			fn( $r ) => str_contains( $r['label'], 'Plant' ) || str_contains( $r['label'], 'Equipment' )
		);
		$this->assertGreaterThanOrEqual( 1, count( $rows ), 'PP&E change must appear in investing activities' );
	}
}
