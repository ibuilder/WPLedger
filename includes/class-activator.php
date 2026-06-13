<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Plugin activation handler.
 *
 * @package WPLedger
 */

namespace WPLedger;

use WPLedger\Db\Schema;
use WPLedger\Models\Coa;

/**
 * Runs on plugin activation: creates tables, grants capability, seeds demo data.
 */
class Activator {

	/**
	 * Run all activation routines.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::create();

		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_wpledger' );
		}

		Coa::maybe_seed_default_company();
	}
}
