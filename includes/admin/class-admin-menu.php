<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Admin menu registration and screen dispatch.
 *
 * Every write action uses check_admin_referer() (nonce) and current_user_can().
 * All input is sanitized on the way in; all output is escaped on the way out.
 *
 * @package WPLedger\Admin
 */

namespace WPLedger\Admin;

use WPLedger\Db\Schema;
use WPLedger\Models\Coa;
use WPLedger\Services\Ledger;
use WPLedger\Services\LedgerException;
use WPLedger\Services\Statements;
use WPLedger\Pdf\PdfRenderer;
use WPLedger\Integrations\WoocommerceSync;
use WPLedger\Export\QbExporter;

/**
 * Registers the WPLedger top-level admin menu and all sub-menu pages.
 */
class AdminMenu {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu',    [ $this, 'add_menu' ] );
		add_action( 'admin_post_wpl_create_company',       [ $this, 'handle_create_company' ] );
		add_action( 'admin_post_wpl_create_account',      [ $this, 'handle_create_account' ] );
		add_action( 'admin_post_wpl_edit_account',        [ $this, 'handle_edit_account' ] );
		add_action( 'admin_post_wpl_toggle_account',      [ $this, 'handle_toggle_account' ] );
		add_action( 'admin_post_wpl_post_entry',          [ $this, 'handle_post_entry' ] );
		add_action( 'admin_post_wpl_download_report',     [ $this, 'handle_download_report' ] );
		add_action( 'admin_post_wpl_wc_settings',         [ $this, 'handle_wc_settings' ] );
		add_action( 'admin_post_wpl_wc_historical_sync',  [ $this, 'handle_wc_historical_sync' ] );
		add_action( 'admin_post_wpl_export_qb',           [ $this, 'handle_export_qb' ] );
		add_action( 'admin_enqueue_scripts',         [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the top-level menu page and sub-pages.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'WPLedger', 'wpledger' ),
			__( 'WPLedger', 'wpledger' ),
			'manage_wpledger',
			'wpledger',
			[ $this, 'page_companies' ],
			'dashicons-money-alt',
			30
		);

		add_submenu_page( 'wpledger', __( 'Companies', 'wpledger' ),        __( 'Companies', 'wpledger' ),        'manage_wpledger', 'wpledger',     [ $this, 'page_companies' ] );
		add_submenu_page( 'wpledger', __( 'Chart of Accounts', 'wpledger' ), __( 'Chart of Accounts', 'wpledger' ), 'manage_wpledger', 'wpl-accounts',   [ $this, 'page_accounts' ] );
		add_submenu_page( 'wpledger', __( 'New Journal Entry', 'wpledger' ), __( 'New Journal Entry', 'wpledger' ), 'manage_wpledger', 'wpl-journal',    [ $this, 'page_journal' ] );
		add_submenu_page( 'wpledger', __( 'Statements', 'wpledger' ),        __( 'Statements', 'wpledger' ),        'manage_wpledger', 'wpl-statements', [ $this, 'page_statements' ] );
		add_submenu_page( 'wpledger', __( 'Journal Ledger', 'wpledger' ),   __( 'Journal Ledger', 'wpledger' ),   'manage_wpledger', 'wpl-ledger',    [ $this, 'page_ledger' ] );
		add_submenu_page( 'wpledger', __( 'REST API', 'wpledger' ),          __( 'REST API', 'wpledger' ),          'manage_wpledger', 'wpl-api',        [ $this, 'page_api_info' ] );
		add_submenu_page( 'wpledger', __( 'Export', 'wpledger' ),           __( 'Export', 'wpledger' ),           'manage_wpledger', 'wpl-export',     [ $this, 'page_export' ] );
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page( 'wpledger', __( 'WooCommerce', 'wpledger' ), __( 'WooCommerce', 'wpledger' ), 'manage_wpledger', 'wpl-woocommerce', [ $this, 'page_woocommerce' ] );
		}
	}

	/**
	 * Enqueue admin stylesheet and JavaScript on WPLedger screens only.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_wpl_screen() ) {
			return;
		}

		wp_enqueue_style(
			'wpledger-admin',
			WPLEDGER_URL . 'assets/css/admin.css',
			[],
			WPLEDGER_VERSION
		);

		wp_enqueue_script(
			'wpledger-admin',
			WPLEDGER_URL . 'assets/js/admin.js',
			[],          // no jQuery dependency needed
			WPLEDGER_VERSION,
			true         // load in footer
		);
	}

	/**
	 * Check whether the current admin screen belongs to WPLedger.
	 *
	 * @return bool
	 */
	private function is_wpl_screen(): bool {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		return str_contains( $screen->id, 'wpledger' )
			|| str_contains( $screen->id, 'wpl-' )
			|| str_contains( $screen->id, 'wpl_' );
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	/**
	 * Companies list + create form.
	 *
	 * @return void
	 */
	public function page_companies(): void {
		$this->require_cap();
		global $wpdb;
		$companies = $wpdb->get_results( 'SELECT * FROM ' . Schema::t( 'companies' ) . ' ORDER BY name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$flash     = $this->get_flash();
		include WPLEDGER_DIR . 'includes/admin/views/companies.php';
	}

	/**
	 * Chart of accounts for a selected company.
	 *
	 * @return void
	 */
	public function page_accounts(): void {
		$this->require_cap();
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing, no write.
		$company_id = absint( $_GET['company_id'] ?? 0 );
		$companies  = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::t( 'companies' ) . ' ORDER BY name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$accounts   = $company_id
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'accounts' ) . ' WHERE company_id = %d ORDER BY code', $company_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: [];
		$flash = $this->get_flash();
		include WPLEDGER_DIR . 'includes/admin/views/accounts.php';
	}

	/**
	 * Manual journal entry form.
	 *
	 * @return void
	 */
	public function page_journal(): void {
		$this->require_cap();
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$company_id = absint( $_GET['company_id'] ?? 0 );
		$companies  = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::t( 'companies' ) . ' ORDER BY name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$accounts   = $company_id
			? $wpdb->get_results( $wpdb->prepare( 'SELECT id, code, name FROM ' . Schema::t( 'accounts' ) . ' WHERE company_id = %d AND is_active = 1 ORDER BY code', $company_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: [];
		$flash = $this->get_flash();
		include WPLEDGER_DIR . 'includes/admin/views/journal.php';
	}

	/**
	 * Financial statements viewer.
	 *
	 * @return void
	 */
	public function page_statements(): void {
		$this->require_cap();
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params.
		$company_id = absint( $_GET['company_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$statement = sanitize_key( $_GET['statement'] ?? 'balance_sheet' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$as_of = $this->sanitize_date_param( $_GET['as_of'] ?? '' ) ?: gmdate( 'Y-m-d' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$start = $this->sanitize_date_param( $_GET['start'] ?? '' ) ?: gmdate( 'Y-01-01' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$end   = $this->sanitize_date_param( $_GET['end'] ?? '' ) ?: gmdate( 'Y-m-d' );

		$companies = $wpdb->get_results( 'SELECT id, name, fiscal_year_start_month FROM ' . Schema::t( 'companies' ) . ' ORDER BY name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data      = null;

		if ( $company_id ) {
			$company_row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'companies' ) . ' WHERE id = %d', $company_id ),
				ARRAY_A
			);
			$fy_start = $company_row ? $this->fy_start( $company_row, $as_of ) : gmdate( 'Y-01-01' );

			try {
				if ( 'balance_sheet' === $statement ) {
					$data = Statements::balance_sheet( $company_id, $as_of, $fy_start );
				} elseif ( 'income_statement' === $statement ) {
					$data = Statements::income_statement( $company_id, $start, $end );
				} elseif ( 'cash_flow' === $statement ) {
					$data = Statements::cash_flow_statement( $company_id, $start, $end );
				}
			} catch ( \Throwable $e ) {
				$data = [ 'error' => $e->getMessage() ];
			}
		}

		include WPLEDGER_DIR . 'includes/admin/views/statements.php';
	}

	/**
	 * Journal ledger — paginated list of posted entries with their lines.
	 *
	 * @return void
	 */
	public function page_ledger(): void {
		$this->require_cap();
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$company_id = absint( $_GET['company_id'] ?? 0 );
		$per_page   = 25;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset = ( $paged - 1 ) * $per_page;

		$companies = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::t( 'companies' ) . ' ORDER BY name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$entries = [];
		$total   = 0;

		if ( $company_id ) {
			$et    = Schema::t( 'journal_entries' );
			$lt    = Schema::t( 'journal_lines' );
			$at    = Schema::t( 'accounts' );
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$et} WHERE company_id = %d AND posted = 1", $company_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$et} WHERE company_id = %d AND posted = 1 ORDER BY entry_date DESC, id DESC LIMIT %d OFFSET %d", $company_id, $per_page, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			foreach ( $rows as $entry ) {
				$entry->lines = $wpdb->get_results(
					$wpdb->prepare( "SELECT jl.*, a.code, a.name AS account_name FROM {$lt} jl JOIN {$at} a ON a.id = jl.account_id WHERE jl.entry_id = %d ORDER BY jl.id", (int) $entry->id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
				$entries[] = $entry;
			}
		}

		$pages = $total ? (int) ceil( $total / $per_page ) : 1;
		include WPLEDGER_DIR . 'includes/admin/views/ledger.php';
	}

	/**
	 * WooCommerce integration settings and historical sync page.
	 *
	 * @return void
	 */
	public function page_woocommerce(): void {
		$this->require_cap();
		global $wpdb;
		$companies = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::t( 'companies' ) . ' ORDER BY name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sync      = new WoocommerceSync();
		$settings  = $sync->settings();
		$wc_active = $sync->wc_active();
		$flash     = get_transient( 'wpl_wc_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'wpl_wc_flash_' . get_current_user_id() );
		}
		include WPLEDGER_DIR . 'includes/admin/views/woocommerce.php';
	}

	/**
	 * QuickBooks export page.
	 *
	 * @return void
	 */
	public function page_export(): void {
		$this->require_cap();

		global $wpdb;
		$companies = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::t( 'companies' ) . ' ORDER BY name' ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params.
		$company_id = absint( $_GET['company_id'] ?? ( $companies[0]->id ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$end   = $this->sanitize_date_param( wp_unslash( $_GET['end']   ?? '' ) ) ?: gmdate( 'Y-m-d' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$start = $this->sanitize_date_param( wp_unslash( $_GET['start'] ?? '' ) ) ?: gmdate( 'Y-01-01' );

		// Build the company selector snippet used in both forms in the view.
		ob_start();
		echo '<select name="company_id" style="margin-bottom:8px;display:block">';
		foreach ( $companies as $c ) {
			printf(
				'<option value="%d"%s>%s</option>',
				absint( $c->id ),
				selected( $company_id, $c->id, false ),
				esc_html( $c->name )
			);
		}
		echo '</select>';
		$company_selector = ob_get_clean();

		include WPLEDGER_DIR . 'includes/admin/views/export.php';
	}

	/**
	 * REST API info page — explains how to use Application Passwords.
	 *
	 * @return void
	 */
	public function page_api_info(): void {
		$this->require_cap();
		$rest_base = esc_url( rest_url( 'wpledger/v1/' ) );
		include WPLEDGER_DIR . 'includes/admin/views/api-info.php';
	}

	// -------------------------------------------------------------------------
	// Form handlers (admin-post.php actions)
	// -------------------------------------------------------------------------

	/**
	 * Handle company creation.
	 *
	 * @return void
	 */
	public function handle_create_company(): void {
		check_admin_referer( 'wpl_create_company' );
		$this->require_cap();

		global $wpdb;
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( ! $name ) {
			$this->set_flash( __( 'Company name is required.', 'wpledger' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=wpledger' ) );
			exit;
		}

		$wpdb->insert(
			Schema::t( 'companies' ),
			[
				'name'                    => $name,
				'legal_name'              => sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) ) ?: null,
				'fiscal_year_start_month' => min( 12, max( 1, absint( $_POST['fiscal_year_start_month'] ?? 1 ) ) ),
				'base_currency'           => strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['base_currency'] ?? 'USD' ) ), 0, 3 ) ),
			],
			[ '%s', '%s', '%d', '%s' ]
		);

		$cid = (int) $wpdb->insert_id;

		if ( $cid ) {
			Coa::seed_company( $cid );
			$this->set_flash( __( 'Company created and chart of accounts seeded.', 'wpledger' ) );
		} else {
			$this->set_flash( __( 'Could not create company.', 'wpledger' ), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpledger' ) );
		exit;
	}

	/**
	 * Handle manual journal entry form submission.
	 *
	 * @return void
	 */
	public function handle_post_entry(): void {
		check_admin_referer( 'wpl_post_entry' );
		$this->require_cap();

		$cid   = absint( $_POST['company_id'] ?? 0 );
		$date  = $this->sanitize_date_param( wp_unslash( $_POST['entry_date'] ?? '' ) );
		$memo  = sanitize_text_field( wp_unslash( $_POST['memo'] ?? '' ) );

		if ( ! $date ) {
			$this->set_flash( __( 'Invalid entry date.', 'wpledger' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=wpl-journal&company_id=' . $cid ) );
			exit;
		}

		$raw_accts  = array_map( 'absint', (array) ( $_POST['account_id'] ?? [] ) );
		$raw_debits = array_map(
			fn( $v ) => sanitize_text_field( wp_unslash( $v ) ),
			(array) ( $_POST['debit'] ?? [] )
		);
		$raw_credits = array_map(
			fn( $v ) => sanitize_text_field( wp_unslash( $v ) ),
			(array) ( $_POST['credit'] ?? [] )
		);

		$lines = [];
		foreach ( $raw_accts as $i => $acct_id ) {
			if ( ! $acct_id ) {
				continue;
			}
			$lines[] = [
				'account_id' => $acct_id,
				'debit'      => $this->sanitize_money( $raw_debits[ $i ] ?? '0' ),
				'credit'     => $this->sanitize_money( $raw_credits[ $i ] ?? '0' ),
			];
		}

		try {
			Ledger::post_entry( $cid, $date, $lines, $memo ?: null );
			$this->set_flash( __( 'Journal entry posted successfully.', 'wpledger' ) );
		} catch ( LedgerException $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpl-journal&company_id=' . $cid ) );
		exit;
	}

	/**
	 * Handle new account creation from the Chart of Accounts screen.
	 *
	 * @return void
	 */
	public function handle_create_account(): void {
		check_admin_referer( 'wpl_create_account' );
		$this->require_cap();

		global $wpdb;

		$cid     = absint( $_POST['company_id'] ?? 0 );
		$code    = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) ), 0, 20 ) );
		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$type    = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
		$subtype = sanitize_key( wp_unslash( $_POST['subtype'] ?? '' ) );
		$is_cash = isset( $_POST['is_cash'] ) ? 1 : 0;

		$valid_types    = [ 'ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE' ];
		$valid_subtypes = [ 'CURRENT_ASSET', 'NONCURRENT_ASSET', 'CURRENT_LIABILITY', 'NONCURRENT_LIABILITY', 'EQUITY', 'OPERATING_REVENUE', 'OTHER_REVENUE', 'COGS', 'OPERATING_EXPENSE', 'OTHER_EXPENSE' ];

		if ( ! $cid || ! $code || ! $name || ! in_array( $type, $valid_types, true ) || ! in_array( $subtype, $valid_subtypes, true ) ) {
			$this->set_flash( __( 'All fields are required and must be valid.', 'wpledger' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=wpl-accounts&company_id=' . $cid ) );
			exit;
		}

		$inserted = $wpdb->insert(
			Schema::t( 'accounts' ),
			[
				'company_id' => $cid,
				'code'       => $code,
				'name'       => $name,
				'type'       => $type,
				'subtype'    => $subtype,
				'is_cash'    => $is_cash,
				'is_active'  => 1,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%d' ]
		);

		if ( false === $inserted ) {
			$this->set_flash( __( 'Could not create account. The account code may already exist for this company.', 'wpledger' ), 'error' );
		} else {
			$this->set_flash( __( 'Account created.', 'wpledger' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpl-accounts&company_id=' . $cid ) );
		exit;
	}

	/**
	 * Toggle an account's active/inactive state.
	 *
	 * @return void
	 */
	public function handle_toggle_account(): void {
		check_admin_referer( 'wpl_toggle_account' );
		$this->require_cap();

		global $wpdb;

		$account_id = absint( $_POST['account_id'] ?? 0 );
		$company_id = absint( $_POST['company_id'] ?? 0 );

		if ( ! $account_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpl-accounts&company_id=' . $company_id ) );
			exit;
		}

		$at      = Schema::t( 'accounts' );
		$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$at} WHERE id = %d AND company_id = %d", $account_id, $company_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( $at, [ 'is_active' => $current ? 0 : 1 ], [ 'id' => $account_id ], [ '%d' ], [ '%d' ] );

		$this->set_flash( $current
			? __( 'Account deactivated.', 'wpledger' )
			: __( 'Account activated.', 'wpledger' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wpl-accounts&company_id=' . $company_id ) );
		exit;
	}

	/**
	 * Handle edit-account form (code, name, is_cash).
	 *
	 * @return void
	 */
	public function handle_edit_account(): void {
		check_admin_referer( 'wpl_edit_account' );
		$this->require_cap();

		global $wpdb;

		$account_id = absint( $_POST['account_id'] ?? 0 );
		$company_id = absint( $_POST['company_id'] ?? 0 );
		$code       = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$is_cash    = isset( $_POST['is_cash'] ) ? 1 : 0;

		if ( ! $account_id || ! $company_id || '' === $code || '' === $name ) {
			$this->set_flash( __( 'Invalid account data.', 'wpledger' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=wpl-accounts&company_id=' . $company_id ) );
			exit;
		}

		$at = Schema::t( 'accounts' );
		$wpdb->update(
			$at,
			[
				'code'    => $code,
				'name'    => $name,
				'is_cash' => $is_cash,
			],
			[ 'id' => $account_id, 'company_id' => $company_id ],
			[ '%s', '%s', '%d' ],
			[ '%d', '%d' ]
		);

		$this->set_flash( __( 'Account updated.', 'wpledger' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=wpl-accounts&company_id=' . $company_id ) );
		exit;
	}

	/**
	 * Handle PDF download from statements page.
	 *
	 * @return void
	 */
	public function handle_download_report(): void {
		check_admin_referer( 'wpl_download_report' );
		$this->require_cap();

		global $wpdb;
		$cid    = absint( $_POST['company_id'] ?? 0 );
		$report = sanitize_key( $_POST['report'] ?? 'balance_sheet' );
		$as_of  = $this->sanitize_date_param( wp_unslash( $_POST['as_of']  ?? '' ) ) ?: gmdate( 'Y-m-d' );
		$start  = $this->sanitize_date_param( wp_unslash( $_POST['start']  ?? '' ) ) ?: gmdate( 'Y-01-01' );
		$end    = $this->sanitize_date_param( wp_unslash( $_POST['end']    ?? '' ) ) ?: gmdate( 'Y-m-d' );

		$company = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Schema::t( 'companies' ) . ' WHERE id = %d', $cid ),
			ARRAY_A
		);
		if ( ! $company ) {
			wp_die( esc_html__( 'Company not found.', 'wpledger' ) );
		}

		$fy_start = $this->fy_start( $company, $as_of );

		switch ( $report ) {
			case 'balance_sheet':
				$data = Statements::balance_sheet( $cid, $as_of, $fy_start );
				$pdf  = PdfRenderer::render_html( PdfRenderer::balance_sheet_html( $company, $data ) );
				$fn   = "balance_sheet_{$as_of}.pdf";
				break;
			case 'income_statement':
				$data = Statements::income_statement( $cid, $start, $end );
				$pdf  = PdfRenderer::render_html( PdfRenderer::income_statement_html( $company, $data ) );
				$fn   = "income_statement_{$start}_{$end}.pdf";
				break;
			case 'cash_flow':
				$data = Statements::cash_flow_statement( $cid, $start, $end );
				$pdf  = PdfRenderer::render_html( PdfRenderer::cash_flow_html( $company, $data ) );
				$fn   = "cash_flow_{$start}_{$end}.pdf";
				break;
			case 'financial_package':
				$bs  = Statements::balance_sheet( $cid, $as_of, $fy_start );
				$is  = Statements::income_statement( $cid, $start, $end );
				$cf  = Statements::cash_flow_statement( $cid, $start, $end );
				$pdf = PdfRenderer::financial_package( $company, $bs, $is, $cf );
				$fn  = "financial_package_{$as_of}.pdf";
				break;
			default:
				wp_die( esc_html__( 'Unknown report type.', 'wpledger' ) );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $fn ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Cache-Control: private, no-cache, no-store' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $pdf;
		exit;
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/**
	 * Abort with wp_die() if the current user lacks manage_wpledger.
	 *
	 * @return void
	 */
	private function require_cap(): void {
		if ( ! current_user_can( 'manage_wpledger' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access WPLedger.', 'wpledger' ),
				403
			);
		}
	}

	/**
	 * Sanitize a monetary amount string to a 2-decimal bcmath-safe string.
	 *
	 * Strips everything except digits and a decimal point, then formats to
	 * exactly 2 decimal places using number_format (no arithmetic, so float
	 * cast is only for formatting, not computation).
	 *
	 * @param string $raw Raw value from form input.
	 * @return string e.g. "1000.00"
	 */
	private function sanitize_money( string $raw ): string {
		$clean = preg_replace( '/[^\d.]/', '', $raw );
		// number_format here is pure formatting — amounts fit within float precision.
		return number_format( (float) $clean, 2, '.', '' );
	}

	/**
	 * Sanitize and validate an ISO date string (YYYY-MM-DD).
	 *
	 * @param string $raw Raw value.
	 * @return string|false Clean date string or false if invalid.
	 */
	private function sanitize_date_param( string $raw ) {
		$clean = sanitize_text_field( $raw );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $clean ) ) {
			return false;
		}
		[ $y, $m, $d ] = explode( '-', $clean );
		return checkdate( (int) $m, (int) $d, (int) $y ) ? $clean : false;
	}

	/**
	 * Derive fiscal year start date from a company row and a reference date.
	 *
	 * @param array  $company Company row (associative).
	 * @param string $as_of   Reference date (ISO).
	 * @return string ISO date of fiscal year start.
	 */
	private function fy_start( array $company, string $as_of ): string {
		$month = (int) ( $company['fiscal_year_start_month'] ?? 1 );
		$year  = (int) gmdate( 'Y', strtotime( $as_of ) );
		if ( (int) gmdate( 'n', strtotime( $as_of ) ) < $month ) {
			--$year;
		}
		return sprintf( '%04d-%02d-01', $year, $month );
	}

	/**
	 * Store a flash message in a transient for the current user.
	 *
	 * @param string $message Message text.
	 * @param string $type    'success' or 'error'.
	 * @return void
	 */
	private function set_flash( string $message, string $type = 'success' ): void {
		set_transient(
			'wpl_flash_' . get_current_user_id(),
			[ 'type' => $type, 'message' => $message ],
			60
		);
	}

	/**
	 * Retrieve and delete the flash message for the current user.
	 *
	 * @return array|false Flash data or false if none.
	 */
	private function get_flash() {
		$key  = 'wpl_flash_' . get_current_user_id();
		$data = get_transient( $key );
		delete_transient( $key );
		return $data;
	}

	/**
	 * Save WooCommerce account mapping settings.
	 *
	 * @return void
	 */
	public function handle_wc_settings(): void {
		check_admin_referer( 'wpl_wc_settings' );
		$this->require_cap();

		$data = [
			'company_id'          => absint( $_POST['company_id'] ?? 0 ),
			'cash_code'           => sanitize_text_field( wp_unslash( $_POST['cash_code']     ?? '1000' ) ),
			'revenue_code'        => sanitize_text_field( wp_unslash( $_POST['revenue_code']  ?? '4000' ) ),
			'tax_code'            => sanitize_text_field( wp_unslash( $_POST['tax_code']      ?? '2200' ) ),
			'shipping_code'       => sanitize_text_field( wp_unslash( $_POST['shipping_code'] ?? '4100' ) ),
			'invoice_auto_email'  => isset( $_POST['invoice_auto_email'] ) ? 1 : 0,
		];

		( new WoocommerceSync() )->save_settings( $data );

		set_transient(
			'wpl_wc_flash_' . get_current_user_id(),
			[ 'type' => 'success', 'message' => __( 'WooCommerce settings saved.', 'wpledger' ) ],
			30
		);
		wp_safe_redirect( admin_url( 'admin.php?page=wpl-woocommerce' ) );
		exit;
	}

	/**
	 * Run historical WooCommerce sync for all completed/processing orders.
	 *
	 * @return void
	 */
	public function handle_wc_historical_sync(): void {
		check_admin_referer( 'wpl_wc_historical_sync' );
		$this->require_cap();

		$company_id = absint( $_POST['company_id'] ?? 0 );
		$result     = ( new WoocommerceSync() )->historical_sync( $company_id );

		$message = sprintf(
			/* translators: 1: synced count, 2: skipped count */
			__( 'Historical sync complete: %1$d entries posted, %2$d skipped (already synced).', 'wpledger' ),
			$result['synced'],
			$result['skipped']
		);

		$flash = [
			'type'    => empty( $result['errors'] ) ? 'success' : 'warning',
			'message' => $message,
			'details' => $result['errors'],
		];

		set_transient( 'wpl_wc_flash_' . get_current_user_id(), $flash, 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=wpl-woocommerce' ) );
		exit;
	}

	/**
	 * Handle QuickBooks export download (IIF or CSV).
	 *
	 * @return void
	 */
	public function handle_export_qb(): void {
		check_admin_referer( 'wpl_export_qb' );
		$this->require_cap();

		$company_id = absint( $_POST['company_id'] ?? 0 );
		$format     = sanitize_key( $_POST['format'] ?? 'csv' );
		$start      = $this->sanitize_date_param( wp_unslash( $_POST['start'] ?? '' ) ) ?: '';
		$end        = $this->sanitize_date_param( wp_unslash( $_POST['end']   ?? '' ) ) ?: '';

		if ( ! $company_id || ! $start || ! $end ) {
			wp_die( esc_html__( 'Company, start date, and end date are all required.', 'wpledger' ) );
		}

		$exporter = new QbExporter();

		if ( 'iif' === $format ) {
			$content  = $exporter->export_iif( $company_id, $start, $end );
			$filename = 'wpledger-' . $start . '-' . $end . '.iif';
			$mime     = 'application/octet-stream';
		} else {
			$content  = $exporter->export_csv( $company_id, $start, $end );
			$filename = 'wpledger-' . $start . '-' . $end . '.csv';
			$mime     = 'text/csv';
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $content;
		exit;
	}
}
