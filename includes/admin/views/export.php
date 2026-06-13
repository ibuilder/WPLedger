<?php
/**
 * Admin view: QuickBooks Export.
 *
 * Variables: $companies (array), $company_id (int), $start (string), $end (string)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wpl-wrap">
	<h1><?php esc_html_e( 'QuickBooks Export', 'wpledger' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Export journal entries from your ledger in a format compatible with QuickBooks. Choose the date range and your QuickBooks version, then click Download.', 'wpledger' ); ?>
	</p>

	<table class="form-table" style="max-width:600px">
		<tr>
			<th scope="row"><?php esc_html_e( 'QuickBooks Desktop (IIF)', 'wpledger' ); ?></th>
			<td>
				<p class="description">
					<?php esc_html_e( 'Intuit Interchange Format — imports into QuickBooks Pro, Premier, and Enterprise via File → Utilities → Import → IIF Files.', 'wpledger' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wpl_export_qb' ); ?>
					<input type="hidden" name="action" value="wpl_export_qb">
					<input type="hidden" name="format" value="iif">
					<?php echo $company_selector; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<label><?php esc_html_e( 'From:', 'wpledger' ); ?>
						<input type="date" name="start" value="<?php echo esc_attr( $start ); ?>" required>
					</label>
					&nbsp;
					<label><?php esc_html_e( 'To:', 'wpledger' ); ?>
						<input type="date" name="end" value="<?php echo esc_attr( $end ); ?>" required>
					</label>
					<br><br>
					<?php submit_button( __( 'Download IIF', 'wpledger' ), 'primary', 'submit', false ); ?>
				</form>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'QuickBooks Online (CSV)', 'wpledger' ); ?></th>
			<td>
				<p class="description">
					<?php esc_html_e( 'General Journal CSV — imports into QuickBooks Online via Accountant → Journal Entries → Import.', 'wpledger' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wpl_export_qb' ); ?>
					<input type="hidden" name="action" value="wpl_export_qb">
					<input type="hidden" name="format" value="csv">
					<?php echo $company_selector; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<label><?php esc_html_e( 'From:', 'wpledger' ); ?>
						<input type="date" name="start" value="<?php echo esc_attr( $start ); ?>" required>
					</label>
					&nbsp;
					<label><?php esc_html_e( 'To:', 'wpledger' ); ?>
						<input type="date" name="end" value="<?php echo esc_attr( $end ); ?>" required>
					</label>
					<br><br>
					<?php submit_button( __( 'Download CSV', 'wpledger' ), 'secondary', 'submit', false ); ?>
				</form>
			</td>
		</tr>
	</table>

	<hr>
	<h2><?php esc_html_e( 'Import Instructions', 'wpledger' ); ?></h2>
	<h3><?php esc_html_e( 'QuickBooks Desktop (IIF)', 'wpledger' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'In QuickBooks Desktop, go to File → Utilities → Import → IIF Files.', 'wpledger' ); ?></li>
		<li><?php esc_html_e( 'Select the downloaded .iif file and click Open.', 'wpledger' ); ?></li>
		<li><?php esc_html_e( 'QuickBooks will import the chart of accounts and journal entries. Review the transaction journal after import.', 'wpledger' ); ?></li>
	</ol>
	<h3><?php esc_html_e( 'QuickBooks Online (CSV)', 'wpledger' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'In QuickBooks Online, go to Accounting → Chart of Accounts and ensure the account names match the exported codes.', 'wpledger' ); ?></li>
		<li><?php esc_html_e( 'Go to Accountant → Journal Entries and look for an Import option, or use the CSV import tool in your Accountant view.', 'wpledger' ); ?></li>
		<li><?php esc_html_e( 'Select the downloaded .csv file. Map the columns as prompted.', 'wpledger' ); ?></li>
	</ol>
	<p class="description">
		<?php esc_html_e( 'Tip: always reconcile your QB ledger against the WPLedger statements after import to confirm the totals match.', 'wpledger' ); ?>
	</p>
</div>
