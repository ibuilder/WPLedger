<?php
/**
 * REST endpoints for the companies resource.
 *
 * Routes:
 *   GET  /wpledger/v1/companies         – list all companies
 *   POST /wpledger/v1/companies         – create a company (seeds chart of accounts)
 *   GET  /wpledger/v1/companies/{id}    – get one company
 *   PUT  /wpledger/v1/companies/{id}    – update company fields
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
use WPLedger\Models\Coa;

/**
 * CRUD REST routes for companies.
 */
class RestCompanies {

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
	 * Register all company routes with full args schema.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			'wpledger/v1',
			'/companies',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_companies' ],
					'permission_callback' => [ RestAuth::class, 'can_read' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_company' ],
					'permission_callback' => [ RestAuth::class, 'can_write' ],
					'args'                => $this->company_write_args( true ),
				],
			]
		);

		register_rest_route(
			'wpledger/v1',
			'/companies/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_company' ],
					'permission_callback' => [ RestAuth::class, 'can_read' ],
					'args'                => [
						'id' => $this->id_arg(),
					],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_company' ],
					'permission_callback' => [ RestAuth::class, 'can_write' ],
					'args'                => array_merge(
						[ 'id' => $this->id_arg() ],
						$this->company_write_args( false )
					),
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /companies — list all companies ordered by name.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_companies(): \WP_REST_Response {
		global $wpdb;
		$t = Schema::t( 'companies' );
		// Table name is controlled by the plugin — no user input. Safe without prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY name" );
		return rest_ensure_response( $rows ?: [] );
	}

	/**
	 * GET /companies/{id} — fetch one company.
	 *
	 * @param \WP_REST_Request $req Request (id sanitized by schema).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_company( \WP_REST_Request $req ) {
		$row = $this->fetch_company( (int) $req['id'] );
		return is_wp_error( $row ) ? $row : rest_ensure_response( $row );
	}

	/**
	 * POST /companies — create a company and seed its default chart of accounts.
	 *
	 * @param \WP_REST_Request $req Request (args validated and sanitized by schema).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_company( \WP_REST_Request $req ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::t( 'companies' ),
			[
				'name'                    => $req->get_param( 'name' ),
				'legal_name'              => $req->get_param( 'legal_name' ) ?: null,
				'ein'                     => $req->get_param( 'ein' ) ?: null,
				'address'                 => $req->get_param( 'address' ) ?: null,
				'fiscal_year_start_month' => (int) $req->get_param( 'fiscal_year_start_month' ),
				'base_currency'           => $req->get_param( 'base_currency' ) ?: 'USD',
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'wpledger_db_error',
				__( 'Could not create company. Please try again.', 'wpledger' ),
				[ 'status' => 500 ]
			);
		}

		$id = (int) $wpdb->insert_id;
		Coa::seed_company( $id );

		$response = rest_ensure_response( [ 'id' => $id, 'status' => 'created' ] );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * PUT /companies/{id} — update mutable company fields.
	 *
	 * Only fields present in the request body are updated; omitted fields are unchanged.
	 *
	 * @param \WP_REST_Request $req Request (args validated and sanitized by schema).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_company( \WP_REST_Request $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$t  = Schema::t( 'companies' );

		// Verify existence before attempting update.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d", $id ) ) ) {
			return new \WP_Error(
				'wpledger_not_found',
				__( 'Company not found.', 'wpledger' ),
				[ 'status' => 404 ]
			);
		}

		$updatable = [
			'name'                    => '%s',
			'legal_name'              => '%s',
			'ein'                     => '%s',
			'address'                 => '%s',
			'fiscal_year_start_month' => '%d',
			'base_currency'           => '%s',
		];

		$data    = [];
		$formats = [];

		foreach ( $updatable as $field => $fmt ) {
			// Only include fields explicitly sent in the request body.
			if ( null !== $req->get_param( $field ) ) {
				$data[ $field ] = $req->get_param( $field );
				$formats[]      = $fmt;
			}
		}

		if ( ! empty( $data ) ) {
			$wpdb->update( $t, $data, [ 'id' => $id ], $formats, [ '%d' ] );
		}

		// Return the updated row rather than making the caller do a second request.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
		return rest_ensure_response( $row );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch a single company row or return a 404 WP_Error.
	 *
	 * @param int $id Company primary key.
	 * @return object|\WP_Error stdClass row or WP_Error.
	 */
	private function fetch_company( int $id ) {
		global $wpdb;
		$t   = Schema::t( 'companies' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );

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
	 * URL parameter arg for a numeric company or entry ID.
	 *
	 * @return array
	 */
	private function id_arg(): array {
		return [
			'description'       => __( 'Resource primary key.', 'wpledger' ),
			'type'              => 'integer',
			'minimum'           => 1,
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];
	}

	/**
	 * Args schema shared between company create and update.
	 *
	 * @param bool $all_required True = all fields required (create). False = all optional (update).
	 * @return array
	 */
	private function company_write_args( bool $all_required ): array {
		return [
			'name'                    => [
				'description'       => __( 'Display name of the company.', 'wpledger' ),
				'type'              => 'string',
				'minLength'         => 1,
				'maxLength'         => 200,
				'required'          => $all_required,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'legal_name'              => [
				'description'       => __( 'Legal / registered company name.', 'wpledger' ),
				'type'              => 'string',
				'maxLength'         => 200,
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'ein'                     => [
				'description'       => __( 'Employer Identification Number.', 'wpledger' ),
				'type'              => 'string',
				'maxLength'         => 20,
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'address'                 => [
				'description'       => __( 'Company mailing address.', 'wpledger' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'fiscal_year_start_month' => [
				'description'       => __( 'Month the fiscal year begins (1–12).', 'wpledger' ),
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 12,
				'required'          => false,
				'default'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
			],
			'base_currency'           => [
				'description'       => __( 'ISO 4217 three-letter currency code (e.g. USD).', 'wpledger' ),
				'type'              => 'string',
				'minLength'         => 3,
				'maxLength'         => 3,
				'pattern'           => '^[A-Z]{3}$',
				'required'          => false,
				'default'           => 'USD',
				// Named static method — no anonymous function, works reliably as a callback.
				'sanitize_callback' => [ self::class, 'sanitize_currency' ],
				'validate_callback' => 'rest_validate_request_arg',
			],
		];
	}

	/**
	 * Sanitize a currency code: uppercase, letters only, 3 chars.
	 *
	 * Named static so it can be used reliably as a WordPress REST callback.
	 *
	 * @param mixed $value Raw value.
	 * @return string e.g. "USD"
	 */
	public static function sanitize_currency( $value ): string {
		$clean = preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( (string) $value ) );
		return strtoupper( substr( $clean, 0, 3 ) );
	}
}
