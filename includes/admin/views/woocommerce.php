<?php
/**
 * Admin view: WooCommerce sync settings and historical sync trigger.
 *
 * Variables: $settings (array), $flash (array|null), $wc_active (bool)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wpl-wrap">
	<h1><?php esc_html_e( 'WooCommerce Integration', 'wpledger' ); ?></h1>

	<?php if ( ! $wc_active ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'WooCommerce is not active. Activate WooCommerce to enable this integration.', 'wpledger' ); ?></p>
		</div>
	<?php else : ?>

		<?php if ( ! empty( $flash ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> inline is-dismissible">
				<p><?php echo esc_html( $flash['message'] ); ?></p>
				<?php if ( ! empty( $flash['details'] ) ) : ?>
					<ul>
					<?php foreach ( (array) $flash['details'] as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Account Mapping', 'wpledger' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Map WooCommerce order components to account codes in your chart of accounts. Entries are posted automatically when an order reaches Processing or Completed status.', 'wpledger' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wpl_wc_settings' ); ?>
			<input type="hidden" name="action" value="wpl_wc_settings">

			<table class="form-table">
				<tr>
					<th scope="row"><label for="wpl-wc-company"><?php esc_html_e( 'Company', 'wpledger' ); ?></label></th>
					<td>
						<select id="wpl-wc-company" name="company_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'wpledger' ); ?></option>
							<?php foreach ( $companies as $c ) : ?>
								<option value="<?php echo absint( $c->id ); ?>" <?php selected( $settings['company_id'] ?? '', $c->id ); ?>>
									<?php echo esc_html( $c->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Journal entries are posted to this company.', 'wpledger' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpl-wc-cash"><?php esc_html_e( 'Cash / Bank Account Code', 'wpledger' ); ?></label></th>
					<td>
						<input type="text" id="wpl-wc-cash" name="cash_code" class="small-text"
							value="<?php echo esc_attr( $settings['cash_code'] ?? '1000' ); ?>" maxlength="20">
						<p class="description"><?php esc_html_e( 'Debited when an order is paid (DR). Credited on refunds.', 'wpledger' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpl-wc-revenue"><?php esc_html_e( 'Sales Revenue Account Code', 'wpledger' ); ?></label></th>
					<td>
						<input type="text" id="wpl-wc-revenue" name="revenue_code" class="small-text"
							value="<?php echo esc_attr( $settings['revenue_code'] ?? '4000' ); ?>" maxlength="20">
						<p class="description"><?php esc_html_e( 'Credited with the net product amount (total minus tax and shipping).', 'wpledger' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpl-wc-tax"><?php esc_html_e( 'Sales Tax Payable Account Code', 'wpledger' ); ?></label></th>
					<td>
						<input type="text" id="wpl-wc-tax" name="tax_code" class="small-text"
							value="<?php echo esc_attr( $settings['tax_code'] ?? '2200' ); ?>" maxlength="20">
						<p class="description"><?php esc_html_e( 'Credited with the tax portion of each order (if any).', 'wpledger' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpl-wc-shipping"><?php esc_html_e( 'Shipping Revenue Account Code', 'wpledger' ); ?></label></th>
					<td>
						<input type="text" id="wpl-wc-shipping" name="shipping_code" class="small-text"
							value="<?php echo esc_attr( $settings['shipping_code'] ?? '4100' ); ?>" maxlength="20">
						<p class="description"><?php esc_html_e( 'Credited with the shipping amount (if any).', 'wpledger' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Email Invoice', 'wpledger' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="invoice_auto_email" value="1"
								<?php checked( ! empty( $settings['invoice_auto_email'] ) ); ?>>
							<?php esc_html_e( 'Automatically email a PDF invoice to the customer when an order is marked Completed.', 'wpledger' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'wpledger' ) ); ?>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Historical Sync', 'wpledger' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Post journal entries for all existing Processing and Completed orders that have not yet been synced. Entries that already exist are skipped automatically (idempotent).', 'wpledger' ); ?>
		</p>

		<?php if ( ! empty( $settings['company_id'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpl_wc_historical_sync' ); ?>
				<input type="hidden" name="action"     value="wpl_wc_historical_sync">
				<input type="hidden" name="company_id" value="<?php echo absint( $settings['company_id'] ); ?>">
				<?php submit_button( __( 'Run Historical Sync', 'wpledger' ), 'secondary' ); ?>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'Save a company selection above before running a historical sync.', 'wpledger' ); ?></p>
		<?php endif; ?>

	<?php endif; ?>
</div>
