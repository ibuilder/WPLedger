<?php
/**
 * Uninstall WPLedger — drops all plugin tables and cleans up options.
 *
 * This file is executed by WordPress when the plugin is deleted via the admin.
 * Deactivating the plugin does NOT trigger this file; tables are only removed
 * on explicit deletion.
 */

// Guard: only run when WordPress itself triggers the uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Autoload must be available for Schema::drop_all().
$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
	\WPLedger\Db\Schema::drop_all();
}

// Clean up options regardless of autoload state.
delete_option( 'wpledger_db_version' );

// Remove the capability from all roles that have it.
global $wp_roles;
if ( ! isset( $wp_roles ) ) {
	$wp_roles = new WP_Roles();
}
foreach ( $wp_roles->roles as $role_name => $role_info ) {
	if ( isset( $role_info['capabilities']['manage_wpledger'] ) ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->remove_cap( 'manage_wpledger' );
		}
	}
}
