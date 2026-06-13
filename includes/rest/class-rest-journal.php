<?php
/**
 * REST endpoints for journal entries.
 *
 * Routes:
 *   GET  /wpledger/v1/journal                – list entries (requires ?company_id=)
 *   POST /wpledger/v1/journal                – post a manual journal entry
 *   GET  /wpledger/v1/journal/{id}           – fetch one entry with its lines
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
use WPLedger\Services\Ledger;
use WPLedger\Services\LedgerException;

/**
 * Registers REST routes for journal entries.
 * All input is validated and sanitized at the route-args level before callbacks run.
 */
class RestJournal {

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
	 * Register all journal routes with full args schema.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		// Collection: list + create.
		register_rest_route(
			'wpledger/v1',
			'/journal',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_entries' ],
					'permission_callback' => [ RestAuth::class, 'can_read' ],
					'args'                => [
						'company_id' => self::company_id_arg(),
						'start'      => self::date_arg( __( 'Filter: entries on or after this date (YYYY-MM-DD).', 'wpledger' ) ),
						'end'        => self::date_arg( __( 'Filter: entries on or before this date (YYYY-MM-DD).', 'wpledger' ) ),
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'post_entry' ],
					'permission_callback' => [ RestAuth::class, 'can_write' ],
					'args'                => [
						'company_id' => self::company_id_arg(),
						'entry_date' => self::required_date_arg( __( 'Journal entry date (YYYY-MM-DD).', 'wpledger' ) ),
						'memo'       => [
							'description'       => __( 'Optional narrative for this entry.', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 500,
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						],
						'reference'  => [
							'description'       => __( 'Optional external reference number.', 'wpledger' ),
							'type'              => 'string',
							'maxLength'         => 100,
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						],
						'lines'      => [
							'description'       => __( 'Journal lines — minimum 2, total debits must equal total credits.', 'wpledger' ),
							'type'              => 'array',
							'required'          => true,
							'minItems'          => 2,
							'validate_callback' => [ $this, 'validate_lines' ],
							'items'             => [
								'type'       => 'object',
								'required'   => [ 'account_id' ],
								'properties' => [
									'account_id' => [
										'type'    => 'integer',
										'minimum' => 1,
									],
									'debit'      => [
										'type'    => 'string',
										// Positive decimal, up to 2 decimal places.
										'pattern' => '^\d+(\.\d{1,2})?$',
										'default' => '0',
									],
									'credit'     => [
										'type'    => 'string',
										'pattern' => '^\d+(\.\d{1,2})?$',
										'default' => '0',
									],
									'line_memo'  => [
										'type'      => 'string',
										'maxLength' => 255,
									],
								],
							],
						],
					],
				],
			]
		);

		// Single entry.
		register_rest_route(
			'wpledger/v1',
			'/journal/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_entry' ],
				'permission_callback' => [ RestAuth::class, 'can_read' ],
				'args'                => [
					'id' => [
						'description'       => __( 'Journal entry ID.', 'wpledger' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /journal — list journal entries for a company.
	 *
	 * Returns up to 200 most-recent posted entries. Supports optional date range.
	 *
	 * @param \WP_REST_Request $req Request (args already sanitized).
	 * @return \WP_REST_Response
	 */
	public function list_entries( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;

		$et     = Schema::t( 'journal_entries' );
		$cid    = (int) $req->get_param( 'company_id' );
		$params = [ $cid ];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$et} WHERE company_id = %d AND posted = 1";

		$start = $req->get_param( 'start' );
		$end   = $req->get_param( 'end' );

		if ( $start ) {
			$sql     .= ' AND entry_date >= %s';
			$params[] = $start;
		}

		if ( $end ) {
			$sql     .= ' AND entry_date <= %s';
			$params[] = $end;
		}

		$sql .= ' ORDER BY entry_date DESC, id DESC LIMIT 200';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$entries = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return rest_ensure_response( $entries ?: [] );
	}

	/**
	 * GET /journal/{id} — fetch one entry with its lines.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_entry( \WP_REST_Request $req ) {
		global $wpdb;

		$et  = Schema::t( 'journal_entries' );
		$lt  = Schema::t( 'journal_lines' );
		$id  = (int) $req['id'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$et} WHERE id = %d", $id ) );

		if ( ! $entry ) {
			return new \WP_Error(
				'wpledger_not_found',
				__( 'Journal entry not found.', 'wpledger' ),
				[ 'status' => 404 ]
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$entry->lines = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$lt} WHERE entry_id = %d ORDER BY id", $id )
		);

		return rest_ensure_response( $entry );
	}

	/**
	 * POST /journal — post a new manual journal entry.
	 *
	 * The double-entry validation (balanced debits/credits) is enforced by
	 * Ledger::post_entry() inside a DB transaction.
	 *
	 * @param \WP_REST_Request $req Request (args validated and sanitized).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_entry( \WP_REST_Request $req ) {
		$cid   = (int) $req->get_param( 'company_id' );
		$lines = $this->normalize_lines( (array) $req->get_param( 'lines' ) );

		try {
			$entry_id = Ledger::post_entry(
				$cid,
				(string) $req->get_param( 'entry_date' ),
				$lines,
				$req->get_param( 'memo' )      ?: null,
				$req->get_param( 'reference' ) ?: null,
				'manual'
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
				'journal_entry_id' => $entry_id,
				'status'           => 'posted',
			]
		);
		$response->set_status( 201 );
		return $response;
	}

	// -------------------------------------------------------------------------
	// Validation callbacks
	// -------------------------------------------------------------------------

	/**
	 * Validate the lines array before the callback runs.
	 *
	 * Checks that every line has a positive account_id and that the amounts
	 * are non-negative numbers. The deeper balance check happens in Ledger::post_entry.
	 *
	 * @param mixed            $value The lines parameter value.
	 * @param \WP_REST_Request $req   Full request.
	 * @param string           $param Parameter name ("lines").
	 * @return true|\WP_Error
	 */
	public function validate_lines( $value, $req, $param ) {
		if ( ! is_array( $value ) || count( $value ) < 2 ) {
			return new \WP_Error(
				'wpledger_lines_invalid',
				__( 'A journal entry requires at least two lines.', 'wpledger' ),
				[ 'status' => 400 ]
			);
		}

		foreach ( $value as $i => $line ) {
			if ( empty( $line['account_id'] ) || (int) $line['account_id'] < 1 ) {
				return new \WP_Error(
					'wpledger_lines_invalid',
					/* translators: %d: line index (1-based) */
					sprintf( __( 'Line %d is missing a valid account_id.', 'wpledger' ), $i + 1 ),
					[ 'status' => 400 ]
				);
			}

			$debit  = (float) ( $line['debit']  ?? 0 );
			$credit = (float) ( $line['credit'] ?? 0 );

			if ( $debit < 0 || $credit < 0 ) {
				return new \WP_Error(
					'wpledger_lines_invalid',
					/* translators: %d: line index (1-based) */
					sprintf( __( 'Line %d: debit and credit amounts must be non-negative.', 'wpledger' ), $i + 1 ),
					[ 'status' => 400 ]
				);
			}

			if ( $debit > 0 && $credit > 0 ) {
				return new \WP_Error(
					'wpledger_lines_invalid',
					/* translators: %d: line index (1-based) */
					sprintf( __( 'Line %d cannot have both a debit and a credit.', 'wpledger' ), $i + 1 ),
					[ 'status' => 400 ]
				);
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalize the lines array to the shape expected by Ledger::post_entry().
	 *
	 * Sanitizes all string fields and converts amounts to 2-decimal strings.
	 * Skips lines without a valid account_id (already caught by validate_lines).
	 *
	 * @param array $lines Raw lines from the request.
	 * @return array Normalized lines.
	 */
	private function normalize_lines( array $lines ): array {
		$clean = [];

		foreach ( $lines as $line ) {
			$account_id = absint( $line['account_id'] ?? 0 );

			if ( ! $account_id ) {
				continue;
			}

			$clean[] = [
				'account_id' => $account_id,
				'debit'      => self::sanitize_amount( $line['debit']  ?? '0' ),
				'credit'     => self::sanitize_amount( $line['credit'] ?? '0' ),
				'line_memo'  => isset( $line['line_memo'] )
					? sanitize_text_field( (string) $line['line_memo'] )
					: null,
			];
		}

		return $clean;
	}
}
