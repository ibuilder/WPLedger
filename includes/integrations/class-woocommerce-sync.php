<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * WooCommerce → WPLedger sync.
 *
 * Hooks into order status changes and automatically posts double-entry journal
 * entries. All accounting is idempotent: the idempotency key
 * wc_order_{order_id} prevents duplicate entries if a hook fires twice.
 *
 * @package WPLedger\Integrations
 */

namespace WPLedger\Integrations;

use WPLedger\Db\Schema;
use WPLedger\Services\Ledger;
use WPLedger\Services\LedgerException;

/**
 * Synchronises WooCommerce order events with the WPLedger general ledger.
 */
class WoocommerceSync {

	/** Option key that stores all WC sync settings. */
	const OPTION = 'wpledger_wc_settings';

	/**
	 * Register WooCommerce order hooks when WC is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->wc_active() ) {
			return;
		}

		add_action( 'woocommerce_order_status_processing', [ $this, 'sync_order' ], 10, 2 );
		add_action( 'woocommerce_order_status_completed',  [ $this, 'sync_order' ], 10, 2 );
		add_action( 'woocommerce_order_refunded',          [ $this, 'sync_refund' ], 10, 2 );
	}

	/**
	 * Post a journal entry when an order moves to processing or completed.
	 *
	 * DR  Cash / Bank (asset)
	 * CR  Sales Revenue
	 * CR  Sales Tax Payable   (if tax > 0)
	 * CR  Shipping Revenue    (if shipping > 0)
	 *
	 * @param int       $order_id WooCommerce order ID.
	 * @param \WC_Order $order    WooCommerce order object.
	 * @return void
	 */
	public function sync_order( int $order_id, \WC_Order $order ): void {
		$msg = $this->sync_order_internal( $order_id, $order );
		if ( 'synced' !== $msg && 'skipped' !== $msg ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[WPLedger] WC sync: ' . $msg );
			}
		}
	}

	/**
	 * Internal order sync — returns 'synced', 'skipped', or an error string.
	 *
	 * @param int       $order_id WooCommerce order ID.
	 * @param \WC_Order $order    WooCommerce order object.
	 * @return string 'synced' | 'skipped' | error message.
	 */
	private function sync_order_internal( int $order_id, \WC_Order $order ): string {
		$settings   = $this->settings();
		$company_id = absint( $settings['company_id'] ?? 0 );
		if ( ! $company_id ) {
			return 'skipped: no company configured';
		}

		$total    = $order->get_total();
		$tax      = $order->get_total_tax();
		$shipping = $order->get_shipping_total();
		$revenue  = bcsub( bcsub( (string) $total, (string) $tax, 2 ), (string) $shipping, 2 );

		if ( bccomp( (string) $total, '0', 2 ) <= 0 ) {
			return 'skipped: zero-value order';
		}

		$cash_code     = sanitize_text_field( $settings['cash_code']     ?? '1000' );
		$revenue_code  = sanitize_text_field( $settings['revenue_code']  ?? '4000' );
		$tax_code      = sanitize_text_field( $settings['tax_code']      ?? '2200' );
		$shipping_code = sanitize_text_field( $settings['shipping_code'] ?? '4100' );

		$cash_id    = $this->resolve_account( $company_id, $cash_code );
		$revenue_id = $this->resolve_account( $company_id, $revenue_code );

		if ( ! $cash_id || ! $revenue_id ) {
			return 'Order #' . $order_id . ': cash or revenue account not found.';
		}

		$lines = [
			[
				'account_id' => $cash_id,
				'debit'      => number_format( (float) $total, 2, '.', '' ),
				'credit'     => '0.00',
				'line_memo'  => sprintf( 'WC Order #%d', $order_id ),
			],
			[
				'account_id' => $revenue_id,
				'debit'      => '0.00',
				'credit'     => number_format( (float) $revenue, 2, '.', '' ),
				'line_memo'  => sprintf( 'Sales — WC Order #%d', $order_id ),
			],
		];

		if ( bccomp( (string) $tax, '0', 2 ) > 0 ) {
			$tax_id = $this->resolve_account( $company_id, $tax_code );
			if ( $tax_id ) {
				$lines[] = [
					'account_id' => $tax_id,
					'debit'      => '0.00',
					'credit'     => number_format( (float) $tax, 2, '.', '' ),
					'line_memo'  => sprintf( 'Tax — WC Order #%d', $order_id ),
				];
			}
		}

		if ( bccomp( (string) $shipping, '0', 2 ) > 0 ) {
			$shipping_id = $this->resolve_account( $company_id, $shipping_code );
			if ( $shipping_id ) {
				$lines[] = [
					'account_id' => $shipping_id,
					'debit'      => '0.00',
					'credit'     => number_format( (float) $shipping, 2, '.', '' ),
					'line_memo'  => sprintf( 'Shipping — WC Order #%d', $order_id ),
				];
			}
		}

		try {
			Ledger::post_entry(
				$company_id,
				gmdate( 'Y-m-d', (int) $order->get_date_created()->getTimestamp() ),
				$lines,
				sprintf( 'WooCommerce Order #%d — %s', $order_id, $order->get_billing_email() ),
				null,
				'woocommerce',
				'wc_order_' . $order_id
			);
			return 'synced';
		} catch ( LedgerException $e ) {
			if ( false !== strpos( $e->getMessage(), 'Duplicate' ) ) {
				return 'skipped';
			}
			return 'Order #' . $order_id . ': ' . $e->getMessage();
		}
	}

	/**
	 * Post a reversing entry when an order is refunded.
	 *
	 * DR  Sales Revenue  (reverse the sale)
	 * CR  Cash / Bank
	 *
	 * @param int $order_id  WooCommerce order ID.
	 * @param int $refund_id WooCommerce refund ID.
	 * @return void
	 */
	public function sync_refund( int $order_id, int $refund_id ): void {
		$settings   = $this->settings();
		$company_id = absint( $settings['company_id'] ?? 0 );
		if ( ! $company_id ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund ) {
			return;
		}

		$amount = abs( (float) $refund->get_total() );
		if ( $amount <= 0 ) {
			return;
		}

		$cash_code    = sanitize_text_field( $settings['cash_code']    ?? '1010' );
		$revenue_code = sanitize_text_field( $settings['revenue_code'] ?? '4010' );
		$amount_str   = number_format( $amount, 2, '.', '' );

		$cash_id    = $this->resolve_account( $company_id, $cash_code );
		$revenue_id = $this->resolve_account( $company_id, $revenue_code );

		if ( ! $cash_id || ! $revenue_id ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[WPLedger] WC refund sync skipped refund ' . $refund_id . ': account not found.' );
			}
			return;
		}

		try {
			Ledger::post_entry(
				$company_id,
				gmdate( 'Y-m-d' ),
				[
					[
						'account_id' => $revenue_id,
						'debit'      => $amount_str,
						'credit'     => '0.00',
						'line_memo'  => sprintf( 'Refund — WC Order #%d', $order_id ),
					],
					[
						'account_id' => $cash_id,
						'debit'      => '0.00',
						'credit'     => $amount_str,
						'line_memo'  => sprintf( 'Cash out — WC Refund #%d', $refund_id ),
					],
				],
				sprintf( 'WooCommerce Refund #%d (Order #%d)', $refund_id, $order_id ),
				null,
				'woocommerce',
				'wc_refund_' . $refund_id
			);
		} catch ( LedgerException $e ) {
			if ( false === strpos( $e->getMessage(), 'Duplicate' ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[WPLedger] WC refund sync failed for refund ' . $refund_id . ': ' . $e->getMessage() );
			}
			}
		}
	}

	/**
	 * Run a historical sync: post entries for all completed/processing orders
	 * that have not yet been synced (idempotency key absent).
	 *
	 * @param int $company_id Target company.
	 * @return array{ synced: int, skipped: int, errors: string[] }
	 */
	public function historical_sync( int $company_id ): array {
		$result = [ 'synced' => 0, 'skipped' => 0, 'errors' => [] ];

		if ( ! $this->wc_active() ) {
			$result['errors'][] = __( 'WooCommerce is not active.', 'wpledger' );
			return $result;
		}

		$orders = wc_get_orders( [
			'status' => [ 'processing', 'completed' ],
			'limit'  => -1,
			'return' => 'objects',
		] );

		foreach ( $orders as $order ) {
			$order_id = $order->get_id();
			$status   = $this->sync_order_internal( $order_id, $order );
			if ( 'synced' === $status ) {
				++$result['synced'];
			} elseif ( 'skipped' === $status ) {
				++$result['skipped'];
			} else {
				$result['errors'][] = $status;
			}
		}

		return $result;
	}

	/**
	 * Resolve an account code to its integer ID within a company.
	 *
	 * Returns 0 when the account is not found or inactive.
	 *
	 * @param int    $company_id Target company.
	 * @param string $code       Account code (e.g. '1010').
	 * @return int Account ID, or 0 if not found.
	 */
	private function resolve_account( int $company_id, string $code ): int {
		global $wpdb;
		$t  = Schema::t( 'accounts' );
		$id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$t} WHERE company_id = %d AND code = %s AND is_active = 1",
				$company_id,
				$code
			)
		);
		return (int) $id;
	}

	/**
	 * Return the saved WC sync settings.
	 *
	 * @return array<string,string>
	 */
	public function settings(): array {
		$saved = get_option( self::OPTION, [] );
		return is_array( $saved ) ? $saved : [];
	}

	/**
	 * Save WC sync settings from a sanitized array.
	 *
	 * @param array<string,string> $data Sanitized settings.
	 * @return void
	 */
	public function save_settings( array $data ): void {
		update_option( self::OPTION, $data );
	}

	/**
	 * Return true when WooCommerce is active.
	 *
	 * @return bool
	 */
	public function wc_active(): bool {
		return class_exists( 'WooCommerce' );
	}
}
