<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Plugin deactivation handler.
 *
 * @package WPLedger
 */

namespace WPLedger;

/**
 * Runs on plugin deactivation. Tables and data are retained; only runtime
 * state (scheduled events, rewrite rules) is cleaned up.
 */
class Deactivator {

	/**
	 * Run deactivation cleanup.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
