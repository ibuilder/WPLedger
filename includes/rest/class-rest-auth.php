<?php
/**
 * REST API permission callbacks.
 *
 * Authentication is handled entirely by WordPress core — no custom key table needed:
 *
 *   Browser requests  → cookie auth + X-WP-Nonce header (wp.apiFetch sets this automatically)
 *   External tools    → WordPress Application Passwords (HTTP Basic auth)
 *                       WP Admin → Users → Profile → Application Passwords
 *
 * All routes require the manage_wpledger capability, which is granted to
 * administrators on plugin activation.
 *
 * @package WPLedger\Rest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace WPLedger\Rest;

/**
 * Provides reusable static permission callbacks for all WPLedger REST routes.
 *
 * Every route in the plugin uses one of these callbacks. None of them use
 * __return_true — every route is authenticated.
 */
class RestAuth {

	/**
	 * Base permission check: user must hold the manage_wpledger capability.
	 *
	 * Works for cookie-authenticated browser sessions and for external tools
	 * using WordPress Application Passwords (Basic auth).
	 *
	 * Returns WP_Error (not false) so that unauthenticated requests receive a
	 * proper 401/403 JSON response instead of a generic "Sorry, you are not
	 * allowed to do that" string.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_manage() {
		if ( ! current_user_can( 'manage_wpledger' ) ) {
			return new \WP_Error(
				'wpledger_forbidden',
				__( 'You do not have permission to access WPLedger data.', 'wpledger' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Permission callback for read-only endpoints (companies list, reports, journal reads).
	 *
	 * Delegates to can_manage(). Defined separately so callers are self-documenting
	 * and so a future role split (e.g. a read-only "accountant" cap) can be added
	 * here without touching every route registration.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_read() {
		return self::can_manage();
	}

	/**
	 * Permission callback for write endpoints (post journal entries, integrations).
	 *
	 * Delegates to can_manage(). Separated from can_read() for the same forward-
	 * compatibility reason.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_write() {
		return self::can_manage();
	}
}
