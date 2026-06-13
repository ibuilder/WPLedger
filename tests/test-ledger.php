<?php
/**
 * Unit tests for the Ledger service.
 *
 * Exercises the core double-entry invariants directly against a real WP test DB.
 *
 * @package WPLedger
 */

use WPLedger\Services\Ledger;
use WPLedger\Services\LedgerException;
use WPLedger\Db\Schema;
use WPLedger\Models\Coa;

/**
 * Tests for Ledger::post_entry(), account_balance(), and period_activity().
 */
class Test_Ledger extends WP_UnitTestCase {

	/** @var int Company ID created fresh for each test. */
	private int $cid;

	/** @var int Cash account ID. */
	private int $cash;

	/** @var int Sales Revenue account ID. */
	private int $sales;

	/**
	 * Create a fresh company and seed its chart of accounts before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::create();

		global $wpdb;
		$wpdb->insert( Schema::t( 'companies' ), [ 'name' => 'Ledger Test Co' ], [ '%s' ] );
		$this->cid   = (int) $wpdb->insert_id;
		Coa::seed_company( $this->cid );

		$this->cash  = $this->account_id( '1000' );
		$this->sales = $this->account_id( '4000' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Look up an account ID by code within the test company.
	 */
	private function account_id( string $code ): int {
		global $wpdb;
		$t  = Schema::t( 'accounts' );
		$id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$t} WHERE company_id = %d AND code = %s", $this->cid, $code ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertNotNull( $id, "Account {$code} not found" );
		return (int) $id;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * A balanced 2-line entry must return a positive entry ID.
	 */
	public function test_balanced_entry_posts(): void {
		$id = Ledger::post_entry(
			$this->cid,
			'2024-01-15',
			[
				[ 'account_id' => $this->cash,  'debit' => '1000.00', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',       'credit' => '1000.00' ],
			],
			'Test sale'
		);

		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * An unbalanced entry must throw LedgerException and leave the DB unchanged.
	 */
	public function test_unbalanced_entry_throws(): void {
		$this->expectException( LedgerException::class );

		Ledger::post_entry(
			$this->cid,
			'2024-01-16',
			[
				[ 'account_id' => $this->cash,  'debit' => '500.00', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',       'credit' => '400.00' ],
			]
		);
	}

	/**
	 * A single-line entry must be rejected — minimum is 2 lines.
	 */
	public function test_single_line_entry_throws(): void {
		$this->expectException( LedgerException::class );

		Ledger::post_entry(
			$this->cid,
			'2024-01-16',
			[
				[ 'account_id' => $this->cash, 'debit' => '100.00', 'credit' => '0' ],
			]
		);
	}

	/**
	 * A zero-value entry (both sides zero) must be rejected.
	 */
	public function test_zero_value_entry_throws(): void {
		$this->expectException( LedgerException::class );

		Ledger::post_entry(
			$this->cid,
			'2024-01-17',
			[
				[ 'account_id' => $this->cash,  'debit' => '0', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0', 'credit' => '0' ],
			]
		);
	}

	/**
	 * A line with both a debit and a credit must be rejected.
	 */
	public function test_line_with_both_sides_throws(): void {
		$this->expectException( LedgerException::class );

		Ledger::post_entry(
			$this->cid,
			'2024-01-18',
			[
				[ 'account_id' => $this->cash,  'debit' => '100', 'credit' => '100' ],
				[ 'account_id' => $this->sales, 'debit' => '0',   'credit' => '0' ],
			]
		);
	}

	/**
	 * DR Cash 1000 / CR Sales 1000 produces correct signed balances.
	 *
	 * Cash (ASSET) is debit-normal: balance = debits - credits = 1000.
	 * Sales (REVENUE) is credit-normal: balance = credits - debits = 1000.
	 */
	public function test_account_balances_after_entry(): void {
		Ledger::post_entry(
			$this->cid,
			'2024-06-01',
			[
				[ 'account_id' => $this->cash,  'debit' => '1000.00', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',       'credit' => '1000.00' ],
			]
		);

		$this->assertSame( '1000.00', Ledger::account_balance( $this->cash ) );
		$this->assertSame( '1000.00', Ledger::account_balance( $this->sales ) );
	}

	/**
	 * account_balance() with an as_of date excludes later entries.
	 */
	public function test_account_balance_date_filter(): void {
		Ledger::post_entry(
			$this->cid,
			'2024-06-01',
			[
				[ 'account_id' => $this->cash,  'debit' => '1000.00', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',       'credit' => '1000.00' ],
			]
		);
		Ledger::post_entry(
			$this->cid,
			'2024-06-15',
			[
				[ 'account_id' => $this->cash,  'debit' => '500.00', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',      'credit' => '500.00' ],
			]
		);

		// As of June 10 — should see only the first entry.
		$this->assertSame( '1000.00', Ledger::account_balance( $this->cash, '2024-06-10' ) );
	}

	/**
	 * period_activity() returns the net movement within a date range.
	 */
	public function test_period_activity(): void {
		// Entry before the period — should not count.
		Ledger::post_entry(
			$this->cid,
			'2024-05-31',
			[
				[ 'account_id' => $this->cash,  'debit' => '2000.00', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',       'credit' => '2000.00' ],
			]
		);
		// Entry inside the period.
		Ledger::post_entry(
			$this->cid,
			'2024-06-15',
			[
				[ 'account_id' => $this->cash,  'debit' => '500.00', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',      'credit' => '500.00' ],
			]
		);

		$activity = Ledger::period_activity( $this->cash, '2024-06-01', '2024-06-30' );
		$this->assertSame( '500.00', $activity );
	}

	/**
	 * Posting the same (source, external_id) pair twice must throw on the second call.
	 */
	public function test_idempotency_key_prevents_duplicate(): void {
		$ext_id = 'inv_' . wp_generate_uuid4();

		Ledger::post_entry(
			$this->cid, '2024-02-01',
			[
				[ 'account_id' => $this->cash,  'debit' => '100', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',   'credit' => '100' ],
			],
			null, null, 'invoicing_api', $ext_id
		);

		$this->expectException( LedgerException::class );

		Ledger::post_entry(
			$this->cid, '2024-02-01',
			[
				[ 'account_id' => $this->cash,  'debit' => '100', 'credit' => '0' ],
				[ 'account_id' => $this->sales, 'debit' => '0',   'credit' => '100' ],
			],
			null, null, 'invoicing_api', $ext_id
		);
	}
}
