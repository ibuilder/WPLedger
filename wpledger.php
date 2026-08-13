<?php
/**
 * Plugin Name:       WPLedger Accounting
 * Plugin URI:        https://wordpress.org/plugins/wpledger
 * Description:       Double-entry accounting for WordPress: chart of accounts, journal entries, Balance Sheet, Income Statement, Cash Flow Statement, PDF export, and a REST API for invoicing and project-management integrations.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            WPLedger
 * Author URI:        https://wordpress.org/plugins/wpledger
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpledger
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPLEDGER_VERSION', '0.1.0' );
define( 'WPLEDGER_FILE', __FILE__ );
define( 'WPLEDGER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPLEDGER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Display an admin notice when Composer dependencies have not been installed.
 *
 * @return void
 */
function wpledger_missing_vendor_notice() {
	echo '<div class="notice notice-error"><p><strong>WPLedger:</strong> '
		. esc_html__( 'Composer dependencies are missing. Run "composer install" inside the plugin directory.', 'wpledger' )
		. '</p></div>';
}

$wpledger_autoload = WPLEDGER_DIR . 'vendor/autoload.php';
if ( ! file_exists( $wpledger_autoload ) ) {
	add_action( 'admin_notices', 'wpledger_missing_vendor_notice' );
	return;
}
require_once $wpledger_autoload;

register_activation_hook( __FILE__, [ 'WPLedger\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WPLedger\\Deactivator', 'deactivate' ] );
register_deactivation_hook( __FILE__, [ 'WPLedger\\Services\\Recurring', 'unschedule' ] );

add_action( 'plugins_loaded', function () {
	( new WPLedger\Plugin() )->run();
} );
