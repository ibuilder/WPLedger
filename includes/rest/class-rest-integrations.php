<?php
/**
 * REST integration endpoints — converts external business documents into journal entries.
 *
 * Routes:
 *   POST /wpledger/v1/integrations/invoices       – customer invoice
 *   POST /wpledger/v1/integrations/payments       – customer payment
 *   POST /wpledger/v1/integrations/project-costs  – project / job cost
 *   POST /wpledger/v1/integrations/vendor-bills   – vendor bill
 *
 * Authentication: WordPress Application Passwords (HTTP Basic auth).
 * The external tool creates an Application Password in WP Admin →
 * Users → Profile → Application Passwords, then sends:
 *   Authorization: Basic base64(username:app_password)
 *
 * Idempotency: the uq_idempotency unique key on (company_id, source, source_external_id)
 * prevents double-posting retried webhooks at the database level.
 *
 * @package WPLedger\Rest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace WPLedger\Rest;

use WPLedger\Db\Schema;
use WPLedger\Services\Ledger;
use WPLedger\Services\LedgerException;

/**
 * Inbound REST endpoints for invoicing and project-management tool integrations.
 * Each endpoint maps an external document to a balanced journal entry.
 */
class RestIntegrations {

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
	 * Register all integration routes with full args schema.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$perm = [ RestAuth::class, 'can_write' ];

		register_rest_route(
			'wpledger/v1',
			'/integrations/invoices',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'receive_invoice' ],
				'permission_callback' => $perm,
				'args'                => array_merge(
					$this->base_args(),
					[
						'customer' => [
							'description'       => __( 'Customer name or identifier (used in memo).', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 200,
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						],
						'lines'    => [
							'description'       => __( 'Invoice line items — at least one required.', 'wpledger' ),
							'type'              => 'array',
							'required'          => true,
							'minItems'          => 1,
							'validate_callback' => 'rest_validate_request_arg',
							'items'             => [
								'type'       => 'object',
								'required'   => [ 'amount', 'revenue_code' ],
								'properties' => [
									'amount'       => [
										'type'    => 'number',
										'minimum' => 0.01,
									],
									'revenue_code' => [
										'type'      => 'string',
										'maxLength' => 20,
									],
								],
							],
						],
						'tax'      => [
							'description'       => __( 'Tax amount: added to AR total, credited to Accrued Liabilities (2100).', 'wpledger' ),
							'type'              => 'number',
							'minimum'           => 0,
							'required'          => false,
							'default'           => 0,
							'validate_callback' => 'rest_validate_request_arg',
						],
					]
				),
			]
		);

		register_rest_route(
			'wpledger/v1',
			'/integrations/payments',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'receive_payment' ],
				'permission_callback' => $perm,
				'args'                => array_merge(
					$this->base_args(),
					[
						'amount'    => [
							'description'       => __( 'Payment amount received.', 'wpledger' ),
							'type'              => 'number',
							'minimum'           => 0.01,
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
						],
						'cash_code' => [
							'description'       => __( 'Account code for the cash account (default: 1000).', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 20,
							'required'          => false,
							'default'           => '1000',
							'sanitize_callback' => [ self::class, 'sanitize_account_code' ],
							'validate_callback' => 'rest_validate_request_arg',
						],
					]
				),
			]
		);

		register_rest_route(
			'wpledger/v1',
			'/integrations/project-costs',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'receive_project_cost' ],
				'permission_callback' => $perm,
				'args'                => array_merge(
					$this->base_args(),
					[
						'amount'       => [
							'description'       => __( 'Cost amount.', 'wpledger' ),
							'type'              => 'number',
							'minimum'           => 0.01,
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
						],
						'expense_code' => [
							'description'       => __( 'Expense account code (default: 5000 COGS).', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 20,
							'required'          => false,
							'default'           => '5000',
							'sanitize_callback' => [ self::class, 'sanitize_account_code' ],
							'validate_callback' => 'rest_validate_request_arg',
						],
						'project_id'   => [
							'description'       => __( 'External project identifier (included in line memo).', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 100,
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						],
						'project_name' => [
							'description'       => __( 'Human-readable project name (included in entry memo).', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 200,
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						],
					]
				),
			]
		);

		register_rest_route(
			'wpledger/v1',
			'/integrations/vendor-bills',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'receive_vendor_bill' ],
				'permission_callback' => $perm,
				'args'                => array_merge(
					$this->base_args(),
					[
						'amount'       => [
							'description'       => __( 'Bill amount.', 'wpledger' ),
							'type'              => 'number',
							'minimum'           => 0.01,
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
						],
						'expense_code' => [
							'description'       => __( 'Expense account code (default: 6300 Office & Admin).', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 20,
							'required'          => false,
							'default'           => '6300',
							'sanitize_callback' => [ self::class, 'sanitize_account_code' ],
							'validate_callback' => 'rest_validate_request_arg',
						],
						'vendor'       => [
							'description'       => __( 'Vendor name (included in entry memo).', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 200,
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						],
					]
				),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * POST /integrations/invoices
	 * Maps a customer invoice to: DR Accounts Receivable / CR Revenue (+ CR Tax Payable).
	 *
	 * @param \WP_REST_Request $req Request (args validated and sanitized by schema).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function receive_invoice( \WP_REST_Request $req ) {
		$cid = (int) $req->get_param( 'company_id' );

		$ar = $this->resolve_account( $cid, '1200' );
		if ( is_wp_error( $ar ) ) {
			return $ar;
		}

		$subtotal = '0';
		$lines    = [];

		foreach ( (array) $req->get_param( 'lines' ) as $line ) {
			$amt      = $this->money( $line['amount'] );
			$subtotal = bcadd( $subtotal, $amt, 2 );

			$rev_code = self::sanitize_account_code( $line['revenue_code'] );
			$rev      = $this->resolve_account( $cid, $rev_code );
			if ( is_wp_error( $rev ) ) {
				return $rev;
			}

			$lines[] = [
				'account_id' => $rev,
				'debit'      => '0',
				'credit'     => $amt,
			];
		}

		$tax   = $this->money( $req->get_param( 'tax' ) ?? 0 );
		$total = bcadd( $subtotal, $tax, 2 );
		$extid = (string) $req->get_param( 'external_id' );

		// Accounts Receivable debit heads the entry.
		array_unshift(
			$lines,
			[
				'account_id' => $ar,
				'debit'      => $total,
				'credit'     => '0',
				'line_memo'  => 'Invoice ' . $extid,
			]
		);

		// Optional tax credit to Accrued Liabilities.
		if ( bccomp( $tax, '0', 2 ) === 1 ) {
			$tax_liab = $this->resolve_account( $cid, '2100' );
			if ( is_wp_error( $tax_liab ) ) {
				return $tax_liab;
			}
			$lines[] = [
				'account_id' => $tax_liab,
				'debit'      => '0',
				'credit'     => $tax,
			];
		}

		$memo = 'Invoice to ' . (string) $req->get_param( 'customer' );

		return $this->post_entry( $cid, $req, $lines, $memo, $extid, 'invoicing_api', $extid );
	}

	/**
	 * POST /integrations/payments
	 * Maps a customer payment to: DR Cash / CR Accounts Receivable.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function receive_payment( \WP_REST_Request $req ) {
		$cid  = (int) $req->get_param( 'company_id' );
		$cash = $this->resolve_account( $cid, (string) $req->get_param( 'cash_code' ) );
		$ar   = $this->resolve_account( $cid, '1200' );

		if ( is_wp_error( $cash ) ) { return $cash; }
		if ( is_wp_error( $ar ) )   { return $ar; }

		$amt   = $this->money( $req->get_param( 'amount' ) );
		$extid = (string) $req->get_param( 'external_id' );

		return $this->post_entry(
			$cid,
			$req,
			[
				[ 'account_id' => $cash, 'debit' => $amt, 'credit' => '0' ],
				[ 'account_id' => $ar,   'debit' => '0',  'credit' => $amt ],
			],
			'Customer payment',
			$extid,
			'invoicing_api',
			'pay_' . $extid
		);
	}

	/**
	 * POST /integrations/project-costs
	 * Maps a project cost to: DR Expense / CR Accounts Payable.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function receive_project_cost( \WP_REST_Request $req ) {
		$cid = (int) $req->get_param( 'company_id' );
		$exp = $this->resolve_account( $cid, (string) $req->get_param( 'expense_code' ) );
		$ap  = $this->resolve_account( $cid, '2000' );

		if ( is_wp_error( $exp ) ) { return $exp; }
		if ( is_wp_error( $ap ) )  { return $ap; }

		$amt   = $this->money( $req->get_param( 'amount' ) );
		$extid = (string) $req->get_param( 'external_id' );

		return $this->post_entry(
			$cid,
			$req,
			[
				[
					'account_id' => $exp,
					'debit'      => $amt,
					'credit'     => '0',
					'line_memo'  => 'Project ' . (string) $req->get_param( 'project_id' ),
				],
				[ 'account_id' => $ap, 'debit' => '0', 'credit' => $amt ],
			],
			'Project cost — ' . (string) $req->get_param( 'project_name' ),
			$extid,
			'pm_api',
			$extid
		);
	}

	/**
	 * POST /integrations/vendor-bills
	 * Maps a vendor bill to: DR Expense / CR Accounts Payable.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function receive_vendor_bill( \WP_REST_Request $req ) {
		$cid = (int) $req->get_param( 'company_id' );
		$exp = $this->resolve_account( $cid, (string) $req->get_param( 'expense_code' ) );
		$ap  = $this->resolve_account( $cid, '2000' );

		if ( is_wp_error( $exp ) ) { return $exp; }
		if ( is_wp_error( $ap ) )  { return $ap; }

		$amt   = $this->money( $req->get_param( 'amount' ) );
		$extid = (string) $req->get_param( 'external_id' );

		return $this->post_entry(
			$cid,
			$req,
			[
				[ 'account_id' => $exp, 'debit' => $amt, 'credit' => '0' ],
				[ 'account_id' => $ap,  'debit' => '0',  'credit' => $amt ],
			],
			'Vendor bill — ' . (string) $req->get_param( 'vendor' ),
			$extid,
			'invoicing_api',
			'bill_' . $extid
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Args shared by all integration endpoints.
	 *
	 * @return array
	 */
	private function base_args(): array {
		return [
			'company_id'  => self::company_id_arg(),
			'date'        => self::required_date_arg( __( 'Transaction date (YYYY-MM-DD).', 'wpledger' ) ),
			'external_id' => [
				'description'       => __( 'Idempotency key. Duplicate (source + external_id) pairs are rejected by the database.', 'wpledger' ),
				'type'              => 'string',
				'minLength'         => 1,
				'maxLength'         => 100,
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];
	}

	/**
	 * Resolve an account ID by code for a company; return WP_Error if not found.
	 *
	 * Note: error message does NOT use esc_html() because it is serialised to JSON,
	 * not rendered as HTML. The code is already sanitized before reaching this point.
	 *
	 * @param int    $company_id Company context.
	 * @param string $code       Account code (e.g. '1200').
	 * @return int|\WP_Error Account ID integer, or WP_Error with HTTP 400.
	 */
	private function resolve_account( int $company_id, string $code ) {
		global $wpdb;

		$t  = Schema::t( 'accounts' );
		$id = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE company_id = %d AND code = %s AND is_active = 1",
				$company_id,
				$code
			)
		);

		if ( ! $id ) {
			return new \WP_Error(
				'wpledger_missing_account',
				/* translators: %s: account code string */
				sprintf( __( 'Account %s not found or inactive for this company.', 'wpledger' ), $code ),
				[ 'status' => 400 ]
			);
		}

		return (int) $id;
	}

	/**
	 * Call Ledger::post_entry() and return a standard 201 REST response.
	 *
	 * Wraps the LedgerException into a 422 WP_Error so the caller gets a clean
	 * JSON error body instead of an uncaught exception.
	 *
	 * @param int              $cid    Company ID.
	 * @param \WP_REST_Request $req    Original request (provides the transaction date).
	 * @param array            $lines  Balanced journal lines.
	 * @param string           $memo   Entry narrative.
	 * @param string           $ref    External reference shown in the entry.
	 * @param string           $source Source system slug (e.g. 'invoicing_api').
	 * @param string           $extid  Idempotency key.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function post_entry(
		int $cid,
		\WP_REST_Request $req,
		array $lines,
		string $memo,
		string $ref,
		string $source,
		string $extid
	) {
		try {
			$id = Ledger::post_entry(
				$cid,
				(string) $req->get_param( 'date' ),
				$lines,
				$memo,
				$ref,
				$source,
				$extid
			);
		} catch ( LedgerException $e ) {
			return new \WP_Error(
				'wpledger_entry_invalid',
				$e->getMessage(),
				[ 'status' => 422 ]
			);
		}

		$response = rest_ensure_response(
			[
				'journal_entry_id' => $id,
				'status'           => 'posted',
			]
		);
		$response->set_status( 201 );
		return $response;
	}
}
