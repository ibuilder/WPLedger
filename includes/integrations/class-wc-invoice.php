<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * WooCommerce Invoice Generator.
 *
 * Produces a professional PDF invoice from a WooCommerce order using Dompdf
 * (already a WPLedger dependency). The invoice pulls company details from
 * the WPLedger company record selected in WC integration settings.
 *
 * Features:
 *   - Download invoice button in WC order admin meta box
 *   - Download invoice link in customer My Account → Orders
 *   - Optional auto-email PDF as attachment on order completion
 *
 * @package WPLedger\Integrations
 */

namespace WPLedger\Integrations;

use WPLedger\Db\Schema;
use WPLedger\Pdf\PdfRenderer;

/**
 * Generates and delivers WooCommerce order invoices as PDFs.
 */
class WcInvoice {

	/** Option key for invoice settings (shared with WoocommerceSync). */
	const OPTION = 'wpledger_wc_settings';

	/**
	 * Register all invoice hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Admin: meta box on single order screen.
		add_action( 'add_meta_boxes', [ $this, 'add_order_meta_box' ] );

		// Customer: link in My Account → Orders table.
		add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'add_my_account_action' ], 10, 2 );

		// Handle the PDF download request (both admin and frontend).
		add_action( 'admin_post_wpl_download_invoice',    [ $this, 'handle_download' ] );
		add_action( 'admin_post_nopriv_wpl_download_invoice', [ $this, 'handle_download_nopriv' ] );

		// Auto-email on order completion if enabled.
		add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_email_invoice' ], 20 );
	}

	/**
	 * Add the WPLedger Invoice meta box to WC order admin screens.
	 *
	 * @return void
	 */
	public function add_order_meta_box(): void {
		$screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
		foreach ( $screens as $screen ) {
			add_meta_box(
				'wpl_invoice',
				__( 'WPLedger Invoice', 'wpledger' ),
				[ $this, 'render_order_meta_box' ],
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or order object.
	 * @return void
	 */
	public function render_order_meta_box( $post_or_order ): void {
		$order_id = $post_or_order instanceof \WP_Post
			? $post_or_order->ID
			: $post_or_order->get_id();

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wpl_download_invoice&order_id=' . absint( $order_id ) ),
			'wpl_invoice_' . $order_id
		);
		echo '<p><a href="' . esc_url( $url ) . '" class="button">'
			. esc_html__( 'Download Invoice (PDF)', 'wpledger' )
			. '</a></p>';
	}

	/**
	 * Add invoice download link to My Account → Orders row actions.
	 *
	 * @param array     $actions Existing row actions.
	 * @param \WC_Order $order   Current order.
	 * @return array Modified actions.
	 */
	public function add_my_account_action( array $actions, \WC_Order $order ): array {
		if ( ! in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
			return $actions;
		}

		$order_id = $order->get_id();
		$actions['wpl_invoice'] = [
			'url'  => wp_nonce_url(
				add_query_arg(
					[ 'action' => 'wpl_download_invoice', 'order_id' => $order_id ],
					admin_url( 'admin-post.php' )
				),
				'wpl_invoice_' . $order_id
			),
			'name' => __( 'Invoice (PDF)', 'wpledger' ),
		];

		return $actions;
	}

	/**
	 * Handle invoice download for logged-in users (admin or customer).
	 *
	 * @return void
	 */
	public function handle_download(): void {
		$order_id = absint( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'wpl_invoice_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'wpledger' ) );
		}

		// Admins can download any invoice; customers only their own.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			if ( get_current_user_id() !== (int) $order->get_customer_id() ) {
				wp_die( esc_html__( 'You do not have permission to download this invoice.', 'wpledger' ) );
			}
		}

		$this->stream_pdf( $order );
	}

	/**
	 * Redirect unauthenticated users to login rather than exposing an error.
	 *
	 * @return void
	 */
	public function handle_download_nopriv(): void {
		wp_safe_redirect( wp_login_url( wp_get_referer() ?: home_url() ) );
		exit;
	}

	/**
	 * Auto-email the invoice PDF when an order is marked Completed, if enabled.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function maybe_email_invoice( int $order_id ): void {
		$settings = get_option( self::OPTION, [] );
		if ( empty( $settings['invoice_auto_email'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$pdf      = $this->build_pdf( $order );
		$filename = $this->filename( $order );
		$to       = $order->get_billing_email();
		$subject  = sprintf(
			/* translators: %s: order number */
			__( 'Invoice for Order #%s', 'wpledger' ),
			$order->get_order_number()
		);
		$message  = sprintf(
			/* translators: %s: order number */
			__( 'Please find your invoice for Order #%s attached.', 'wpledger' ),
			$order->get_order_number()
		);

		// Write PDF to a temp file for wp_mail attachment.
		$tmp = wp_tempnam( $filename );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $pdf );

		wp_mail(
			$to,
			$subject,
			$message,
			[],
			[ $tmp ]
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $tmp );
	}

	/**
	 * Stream a PDF invoice to the browser as a download.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return void
	 */
	private function stream_pdf( \WC_Order $order ): void {
		$pdf      = $this->build_pdf( $order );
		$filename = $this->filename( $order );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $pdf;
		exit;
	}

	/**
	 * Build the PDF bytes for an order invoice.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return string Raw PDF bytes.
	 */
	public function build_pdf( \WC_Order $order ): string {
		$company = $this->get_company_details();
		$html    = $this->build_html( $order, $company );
		return PdfRenderer::render_html( $html );
	}

	/**
	 * Build the invoice HTML document.
	 *
	 * @param \WC_Order $order   WooCommerce order.
	 * @param object    $company WPLedger company row (or null).
	 * @return string Complete HTML document.
	 */
	private function build_html( \WC_Order $order, ?object $company ): string {
		$company_name    = $company ? $company->name : get_bloginfo( 'name' );
		$company_address = $company ? nl2br( esc_html( $company->address ?? '' ) ) : '';
		$order_number    = $order->get_order_number();
		$order_date      = wc_format_datetime( $order->get_date_created() );
		$order_status    = wc_get_order_status_name( $order->get_status() );

		$billing = $order->get_formatted_billing_address();

		ob_start();
		?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #222; margin: 0; padding: 0; }
  .wrap { padding: 40px; }
  .header { display: table; width: 100%; margin-bottom: 32px; }
  .header-left { display: table-cell; vertical-align: top; width: 60%; }
  .header-right { display: table-cell; vertical-align: top; text-align: right; }
  h1.company { font-size: 22px; margin: 0 0 4px; color: #1d4e6b; }
  h2.invoice-title { font-size: 28px; margin: 0; color: #1d4e6b; }
  .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  .meta-table td { padding: 4px 8px; vertical-align: top; }
  .meta-table td:first-child { font-weight: bold; width: 130px; }
  table.items { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  table.items th { background: #1d4e6b; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; }
  table.items td { padding: 7px 10px; border-bottom: 1px solid #eee; }
  table.items .amt { text-align: right; }
  .totals { width: 260px; margin-left: auto; border-collapse: collapse; }
  .totals td { padding: 5px 10px; }
  .totals td.label { font-weight: bold; }
  .totals td.amt { text-align: right; }
  .totals tr.grand td { font-size: 14px; font-weight: bold; border-top: 2px solid #1d4e6b; padding-top: 8px; }
  .footer { margin-top: 48px; font-size: 10px; color: #888; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
	<div class="header-left">
	  <h1 class="company"><?php echo esc_html( $company_name ); ?></h1>
	  <?php if ( $company_address ) : ?>
	  <div><?php echo $company_address; ?></div>
	  <?php endif; ?>
	</div>
	<div class="header-right">
	  <h2 class="invoice-title"><?php esc_html_e( 'INVOICE', 'wpledger' ); ?></h2>
	</div>
  </div>

  <table class="meta-table">
	<tr>
	  <td><?php esc_html_e( 'Invoice #', 'wpledger' ); ?></td>
	  <td><?php echo esc_html( $order_number ); ?></td>
	  <td style="width:40px"></td>
	  <td style="font-weight:bold"><?php esc_html_e( 'Bill To', 'wpledger' ); ?></td>
	  <td><?php echo wp_kses_post( $billing ); ?></td>
	</tr>
	<tr>
	  <td><?php esc_html_e( 'Date', 'wpledger' ); ?></td>
	  <td><?php echo esc_html( $order_date ); ?></td>
	  <td></td>
	  <td><?php esc_html_e( 'Status', 'wpledger' ); ?></td>
	  <td><?php echo esc_html( $order_status ); ?></td>
	</tr>
	<?php if ( $order->get_billing_email() ) : ?>
	<tr>
	  <td><?php esc_html_e( 'Email', 'wpledger' ); ?></td>
	  <td><?php echo esc_html( $order->get_billing_email() ); ?></td>
	</tr>
	<?php endif; ?>
  </table>

  <table class="items">
	<thead>
	  <tr>
		<th><?php esc_html_e( 'Description', 'wpledger' ); ?></th>
		<th><?php esc_html_e( 'SKU', 'wpledger' ); ?></th>
		<th><?php esc_html_e( 'Qty', 'wpledger' ); ?></th>
		<th class="amt"><?php esc_html_e( 'Unit Price', 'wpledger' ); ?></th>
		<th class="amt"><?php esc_html_e( 'Line Total', 'wpledger' ); ?></th>
	  </tr>
	</thead>
	<tbody>
	<?php foreach ( $order->get_items() as $item ) :
		/** @var \WC_Order_Item_Product $item */
		$product  = $item->get_product();
		$sku      = $product ? esc_html( $product->get_sku() ) : '';
		$qty      = (int) $item->get_quantity();
		$subtotal = (float) $item->get_subtotal();
		$unit     = $qty > 0 ? $subtotal / $qty : 0;
	?>
	  <tr>
		<td><?php echo esc_html( $item->get_name() ); ?></td>
		<td><?php echo $sku; ?></td>
		<td><?php echo $qty; ?></td>
		<td class="amt"><?php echo esc_html( wc_price( $unit ) ); ?></td>
		<td class="amt"><?php echo esc_html( wc_price( $subtotal ) ); ?></td>
	  </tr>
	<?php endforeach; ?>
	</tbody>
  </table>

  <table class="totals">
	<tr>
	  <td class="label"><?php esc_html_e( 'Subtotal', 'wpledger' ); ?></td>
	  <td class="amt"><?php echo esc_html( wc_price( $order->get_subtotal() ) ); ?></td>
	</tr>
	<?php if ( (float) $order->get_shipping_total() > 0 ) : ?>
	<tr>
	  <td class="label"><?php esc_html_e( 'Shipping', 'wpledger' ); ?></td>
	  <td class="amt"><?php echo esc_html( wc_price( $order->get_shipping_total() ) ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( (float) $order->get_total_discount() > 0 ) : ?>
	<tr>
	  <td class="label"><?php esc_html_e( 'Discount', 'wpledger' ); ?></td>
	  <td class="amt">-<?php echo esc_html( wc_price( $order->get_total_discount() ) ); ?></td>
	</tr>
	<?php endif; ?>
	<?php foreach ( $order->get_tax_totals() as $tax ) : ?>
	<tr>
	  <td class="label"><?php echo esc_html( $tax->label ); ?></td>
	  <td class="amt"><?php echo esc_html( wc_price( $tax->amount ) ); ?></td>
	</tr>
	<?php endforeach; ?>
	<tr class="grand">
	  <td class="label"><?php esc_html_e( 'TOTAL', 'wpledger' ); ?></td>
	  <td class="amt"><?php echo esc_html( wc_price( $order->get_total() ) ); ?></td>
	</tr>
  </table>

  <?php if ( $order->get_customer_note() ) : ?>
  <p><strong><?php esc_html_e( 'Note:', 'wpledger' ); ?></strong>
	 <?php echo esc_html( $order->get_customer_note() ); ?></p>
  <?php endif; ?>

  <div class="footer">
	<?php
	printf(
		/* translators: %s: company name */
		esc_html__( 'Thank you for your business — %s', 'wpledger' ),
		esc_html( $company_name )
	);
	?>
  </div>
</div>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Return the company row for the company selected in WC settings, or null.
	 *
	 * @return object|null
	 */
	private function get_company_details(): ?object {
		global $wpdb;

		$settings   = get_option( self::OPTION, [] );
		$company_id = absint( $settings['company_id'] ?? 0 );
		if ( ! $company_id ) {
			return null;
		}

		$t = Schema::t( 'companies' );
		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$t} WHERE id = %d",
				$company_id
			)
		);
	}

	/**
	 * Build a safe PDF filename for an order.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return string e.g. invoice-1042.pdf
	 */
	private function filename( \WC_Order $order ): string {
		return sanitize_file_name( 'invoice-' . $order->get_order_number() . '.pdf' );
	}
}
