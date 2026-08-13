<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Main plugin bootstrap — wires all components via WordPress hooks.
 *
 * @package WPLedger
 */

namespace WPLedger;

use WPLedger\Rest\RestCompanies;
use WPLedger\Rest\RestJournal;
use WPLedger\Rest\RestReports;
use WPLedger\Rest\RestIntegrations;
use WPLedger\Admin\AdminMenu;
use WPLedger\Integrations\WoocommerceSync;
use WPLedger\Integrations\WcInvoice;

/**
 * Orchestrates hook registration for every plugin subsystem.
 */
class Plugin {

	/**
	 * Register all hooks and bootstrap subsystems.
	 *
	 * @return void
	 */
	public function run(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// REST API — all routes under wpledger/v1.
		( new RestCompanies() )->register();
		( new RestJournal() )->register();
		( new RestReports() )->register();
		( new RestIntegrations() )->register();

		// Expose the REST nonce for browser-side wp.apiFetch calls.
		// Priority 11 — must run after enqueue_assets (priority 10) so the script exists.
		add_action( 'admin_enqueue_scripts', [ $this, 'localize_rest_nonce' ], 11 );

		// WooCommerce integration — registers hooks after plugins loaded.
		add_action( 'plugins_loaded', [ $this, 'init_woocommerce' ] );

		// Admin UI — only in admin context.
		if ( is_admin() ) {
			( new AdminMenu() )->register();
		}
	}

	/**
	 * Initialise WooCommerce integration after all plugins have loaded.
	 *
	 * @return void
	 */
	public function init_woocommerce(): void {
		( new WoocommerceSync() )->register();
		( new WcInvoice() )->register();
	}

	/**
	 * Load the plugin text domain for i18n.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wpledger',
			false,
			dirname( plugin_basename( WPLEDGER_FILE ) ) . '/languages'
		);
	}

	/**
	 * Localize wpledgerData with the REST nonce for any screen that loads
	 * the admin JS — allows wp.apiFetch to authenticate browser requests.
	 *
	 * @return void
	 */
	public function localize_rest_nonce(): void {
		// Only localize when our admin CSS is enqueued (i.e. on our screens).
		if ( ! wp_script_is( 'wpledger-admin', 'enqueued' ) ) {
			return;
		}

		wp_localize_script(
			'wpledger-admin',
			'wpledgerData',
			[
				'restUrl' => esc_url_raw( rest_url( 'wpledger/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => [
					'debits'    => __( 'Debits', 'wpledger' ),
					'credits'   => __( 'Credits', 'wpledger' ),
					'balanced'  => __( 'Balanced', 'wpledger' ),
					'unbalanced' => __( 'Unbalanced', 'wpledger' ),
				],
			]
		);
	}
}
