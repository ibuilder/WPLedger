<?php
/**
 * REST endpoints for financial reports — JSON responses only.
 *
 * Routes:
 *   GET /wpledger/v1/reports/balance-sheet     – Balance Sheet
 *   GET /wpledger/v1/reports/income-statement  – Income Statement
 *   GET /wpledger/v1/reports/cash-flow         – Cash Flow Statement
 *
 * PDF downloads are served through admin-post.php (action: lc_download_report),
 * not through the REST API. Binary output sent inside a REST callback conflicts
 * with WordPress's response pipeline and produces the "Output blocked by content
 * filtering policy" error.
 *
 * Authentication: WordPress Application Passwords (external tools) or
 * cookie + X-WP-Nonce header (browser). Capability: manage_wpledger.
 *
 * @package WPLedger\Rest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace WPLedger\Rest;

use WPLedger\Db\Schema;
use WPLedger\Services\Statements;

/**
 * Read-only GET routes for the three core financial statements.
 * All responses are JSON — no binary output.
 */
class RestReports {

	use RestHelpers;

	/**
	 * Hook route registration onto rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all report routes with full args schema.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		$perm       = [ RestAuth::class, 'can_read' ];
		$company    = self::company_id_arg();

		register_rest_route(
			'wpledger/v1',
			'/reports/balance-sheet',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'balance_sheet' ],
				'permission_callback' => $perm,
				'args'                => [
					'company_id' => $company,
					'as_of'      => self::date_arg(
						__( 'Snapshot date (YYYY-MM-DD). Defaults to today.', 'wpledger' )
					),
				],
			]
		);

		register_rest_route(
			'wpledger/v1',
			'/reports/income-statement',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'income_statement' ],
				'permission_callback' => $perm,
				'args'                => [
					'company_id' => $company,
					'start'      => self::date_arg( __( 'Period start date (YYYY-MM-DD).', 'wpledger' ) ),
					'end'        => self::date_arg( __( 'Period end date (YYYY-MM-DD).', 'wpledger' ) ),
				],
			]
		);

		register_rest_route(
			'wpledger/v1',
			'/reports/cash-flow',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'cash_flow' ],
				'permission_callback' => $perm,
				'args'                => [
					'company_id' => $company,
					'start'      => self::date_arg( __( 'Period start date (YYYY-MM-DD).', 'wpledger' ) ),
					'end'        => self::date_arg( __( 'Period end date (YYYY-MM-DD).', 'wpledger' ) ),
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /reports/balance-sheet — point-in-time balance sheet.
	 *
	 * Includes a `balanced` boolean flag. If false, that is a bug in the ledger.
	 *
	 * @param \WP_REST_Request $req Request (args validated and sanitized).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function balance_sheet( \WP_REST_Request $req ) {
		$cid     = (int) $req->get_param( 'company_id' );
		$as_of   = $req->get_param( 'as_of' ) ?: gmdate( 'Y-m-d' );
		$company = $this->fetch_company( $cid );

		if ( is_wp_error( $company ) ) {
			return $company;
		}

		return rest_ensure_response(
			Statements::balance_sheet( $cid, $as_of, $this->fy_start( $company, $as_of ) )
		);
	}

	/**
	 * GET /reports/income-statement — revenue, expenses, and net income for a period.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function income_statement( \WP_REST_Request $req ) {
		$cid     = (int) $req->get_param( 'company_id' );
		$start   = $req->get_param( 'start' ) ?: gmdate( 'Y-01-01' );
		$end     = $req->get_param( 'end' )   ?: gmdate( 'Y-m-d' );
		$company = $this->fetch_company( $cid );

		if ( is_wp_error( $company ) ) {
			return $company;
		}

		return rest_ensure_response(
			Statements::income_statement( $cid, $start, $end )
		);
	}

	/**
	 * GET /reports/cash-flow — cash flow statement (indirect method).
	 *
	 * Includes a `reconciles` boolean flag that verifies the statement against
	 * the actual movement in cash-flagged accounts.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cash_flow( \WP_REST_Request $req ) {
		$cid     = (int) $req->get_param( 'company_id' );
		$start   = $req->get_param( 'start' ) ?: gmdate( 'Y-01-01' );
		$end     = $req->get_param( 'end' )   ?: gmdate( 'Y-m-d' );
		$company = $this->fetch_company( $cid );

		if ( is_wp_error( $company ) ) {
			return $company;
		}

		return rest_ensure_response(
			Statements::cash_flow_statement( $cid, $start, $end )
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch a company row (associative array) or return a 404 WP_Error.
	 *
	 * @param int $company_id Company primary key.
	 * @return array|\WP_Error
	 */
	private function fetch_company( int $company_id ) {
		global $wpdb;
		$t   = Schema::t( 'companies' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $company_id ), ARRAY_A );

		if ( ! $row ) {
			return new \WP_Error(
				'wpledger_not_found',
				__( 'Company not found.', 'wpledger' ),
				[ 'status' => 404 ]
			);
		}

		return $row;
	}

	/**
	 * Derive the fiscal year start date from a company row and a reference date.
	 *
	 * @param array  $company Associative company row.
	 * @param string $as_of   ISO reference date (YYYY-MM-DD).
	 * @return string ISO fiscal year start, e.g. "2026-01-01".
	 */
	private function fy_start( array $company, string $as_of ): string {
		$month = max( 1, min( 12, (int) ( $company['fiscal_year_start_month'] ?? 1 ) ) );
		$year  = (int) gmdate( 'Y', strtotime( $as_of ) );

		if ( (int) gmdate( 'n', strtotime( $as_of ) ) < $month ) {
			--$year;
		}

		return sprintf( '%04d-%02d-01', $year, $month );
	}
}
