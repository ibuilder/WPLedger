<?php
/**
 * Shared REST API helper methods.
 *
 * Used by every REST controller class to avoid code duplication and
 * ensure consistent sanitization and validation behaviour.
 *
 * @package WPLedger\Rest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace WPLedger\Rest;

/**
 * Provides date sanitization, monetary formatting, and company/account look-ups
 * shared by all WPLedger REST controllers.
 */
trait RestHelpers {

	/**
	 * Sanitize and validate an ISO date string (YYYY-MM-DD).
	 *
	 * Used as both `sanitize_callback` and standalone guard.
	 * Returns false on invalid input; WordPress then emits a 400 automatically.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return string|false Clean date string, or false if the value is not a valid calendar date.
	 */
	public static function sanitize_date( $value ) {
		$clean = sanitize_text_field( (string) $value );

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $clean ) ) {
			return false;
		}

		list( $y, $m, $d ) = explode( '-', $clean );

		return checkdate( (int) $m, (int) $d, (int) $y ) ? $clean : false;
	}

	/**
	 * Validate that a parameter is a proper ISO date.
	 *
	 * Used as `validate_callback` in route args. Returns WP_Error on failure
	 * so WordPress sends an informative 400 response.
	 *
	 * @param mixed            $value  Parameter value.
	 * @param \WP_REST_Request $req    Full request object.
	 * @param string           $param  Parameter key name.
	 * @return true|\WP_Error
	 */
	public static function validate_date( $value, $req, $param ) {
		if ( false === self::sanitize_date( $value ) ) {
			return new \WP_Error(
				'wpledger_invalid_date',
				/* translators: %s: parameter name */
				sprintf( __( 'Invalid date for parameter "%s". Expected YYYY-MM-DD.', 'wpledger' ), $param ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Convert any numeric value to a 2-decimal monetary string safe for bcmath and DB storage.
	 *
	 * @param mixed $value Raw value (string, int, or float).
	 * @return string e.g. "1000.00"
	 */
	protected function money( $value ): string {
		// Strip anything that is not a digit or a decimal point.
		$clean = preg_replace( '/[^\d.]/', '', (string) $value );
		return number_format( (float) $clean, 2, '.', '' );
	}

	/**
	 * Sanitize a monetary amount string coming from request parameters.
	 *
	 * @param mixed $value Raw amount.
	 * @return string Sanitized 2-decimal string.
	 */
	public static function sanitize_amount( $value ): string {
		$clean = preg_replace( '/[^\d.]/', '', sanitize_text_field( (string) $value ) );
		return number_format( (float) $clean, 2, '.', '' );
	}

	/**
	 * Validate that a parameter is a positive monetary amount (> 0.00).
	 *
	 * @param mixed            $value  Parameter value.
	 * @param \WP_REST_Request $req    Full request object.
	 * @param string           $param  Parameter key name.
	 * @return true|\WP_Error
	 */
	public static function validate_positive_amount( $value, $req, $param ) {
		$clean = preg_replace( '/[^\d.]/', '', (string) $value );
		if ( ! is_numeric( $clean ) || (float) $clean <= 0 ) {
			return new \WP_Error(
				'wpledger_invalid_amount',
				/* translators: %s: parameter name */
				sprintf( __( 'Parameter "%s" must be a positive number.', 'wpledger' ), $param ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Sanitize an account code (alphanumeric, max 20 chars).
	 *
	 * @param mixed $value Raw account code.
	 * @return string Sanitized code.
	 */
	public static function sanitize_account_code( $value ): string {
		return substr( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( (string) $value ) ), 0, 20 );
	}

	/**
	 * Reusable route arg definition for a company_id parameter.
	 *
	 * @return array
	 */
	protected static function company_id_arg(): array {
		return [
			'description'       => __( 'Company ID.', 'wpledger' ),
			'type'              => 'integer',
			'minimum'           => 1,
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];
	}

	/**
	 * Reusable route arg definition for an optional ISO date parameter.
	 *
	 * @param string $description Human-readable description.
	 * @return array
	 */
	protected static function date_arg( string $description ): array {
		return [
			'description'       => $description,
			'type'              => 'string',
			'format'            => 'date',
			'required'          => false,
			'validate_callback' => [ static::class, 'validate_date' ],
			'sanitize_callback' => [ static::class, 'sanitize_date' ],
		];
	}

	/**
	 * Reusable route arg definition for a required ISO date parameter.
	 *
	 * @param string $description Human-readable description.
	 * @return array
	 */
	protected static function required_date_arg( string $description ): array {
		return array_merge(
			self::date_arg( $description ),
			[ 'required' => true ]
		);
	}
}
