<?php
/**
 * Admin view: Financial Statements viewer.
 *
 * Variables: $companies, $company_id, $statement, $as_of, $start, $end, $data
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$fmt = fn( $v ) => number_format( (float) $v, 2 );
?>
<div class="wrap lc-wrap">
	<h1><?php esc_html_e( 'Financial Statements', 'wpledger' ); ?></h1>

	<form method="get" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
		<input type="hidden" name="page" value="wpl-statements">
		<select name="company_id" onchange="this.form.submit()">
			<option value=""><?php esc_html_e( '— Company —', 'wpledger' ); ?></option>
			<?php foreach ( $companies as $c ) : ?>
				<option value="<?php echo absint( $c->id ); ?>" <?php selected( $company_id, $c->id ); ?>><?php echo esc_html( $c->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="statement">
			<option value="balance_sheet"    <?php selected( $statement, 'balance_sheet' ); ?>><?php esc_html_e( 'Balance Sheet', 'wpledger' ); ?></option>
			<option value="income_statement" <?php selected( $statement, 'income_statement' ); ?>><?php esc_html_e( 'Income Statement', 'wpledger' ); ?></option>
			<option value="cash_flow"        <?php selected( $statement, 'cash_flow' ); ?>><?php esc_html_e( 'Cash Flow', 'wpledger' ); ?></option>
		</select>
		<?php if ( 'balance_sheet' === $statement ) : ?>
			<label><?php esc_html_e( 'As of:', 'wpledger' ); ?> <input type="date" name="as_of" value="<?php echo esc_attr( $as_of ); ?>"></label>
		<?php else : ?>
			<label><?php esc_html_e( 'From:', 'wpledger' ); ?> <input type="date" name="start" value="<?php echo esc_attr( $start ); ?>"></label>
			<label><?php esc_html_e( 'To:', 'wpledger' ); ?>   <input type="date" name="end"   value="<?php echo esc_attr( $end ); ?>"></label>
		<?php endif; ?>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'View', 'wpledger' ); ?></button>
	</form>

	<?php if ( $data && isset( $data['error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $data['error'] ); ?></p></div>
	<?php elseif ( $data ) : ?>

		<?php /* PDF download buttons — served via admin-post.php, not the REST API */ ?>
		<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">

			<?php /* Download current statement */ ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpl_download_report' ); ?>
				<input type="hidden" name="action"     value="wpl_download_report">
				<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">
				<input type="hidden" name="report"     value="<?php echo esc_attr( $statement ); ?>">
				<input type="hidden" name="as_of"      value="<?php echo esc_attr( $as_of ); ?>">
				<input type="hidden" name="start"      value="<?php echo esc_attr( $start ); ?>">
				<input type="hidden" name="end"        value="<?php echo esc_attr( $end ); ?>">
				<button type="submit" class="button">
					<?php esc_html_e( '⬇ Download This Statement (PDF)', 'wpledger' ); ?>
				</button>
			</form>

			<?php /* Download all three statements as one PDF package */ ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpl_download_report' ); ?>
				<input type="hidden" name="action"     value="wpl_download_report">
				<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">
				<input type="hidden" name="report"     value="financial_package">
				<input type="hidden" name="as_of"      value="<?php echo esc_attr( $as_of ); ?>">
				<input type="hidden" name="start"      value="<?php echo esc_attr( $start ); ?>">
				<input type="hidden" name="end"        value="<?php echo esc_attr( $end ); ?>">
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( '⬇ Download Full Package (PDF)', 'wpledger' ); ?>
				</button>
			</form>

		</div>

		<?php if ( 'balance_sheet' === $statement ) : ?>
			<h2><?php esc_html_e( 'Balance Sheet', 'wpledger' ); ?> — <?php echo esc_html( $data['as_of'] ); ?>
				<?php if ( ! $data['balanced'] ) : ?>
					<span style="color:red"> ⚠ <?php esc_html_e( 'NOT BALANCED', 'wpledger' ); ?></span>
				<?php endif; ?>
			</h2>
			<table class="widefat lc-statement">
				<tbody>
					<tr class="wpl-section"><td colspan="2"><?php esc_html_e( 'ASSETS', 'wpledger' ); ?></td></tr>
					<tr><td colspan="2"><em><?php esc_html_e( 'Current Assets', 'wpledger' ); ?></em></td></tr>
					<?php foreach ( $data['current_assets']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['name'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr class="wpl-subtotal"><td><?php esc_html_e( 'Total Current Assets', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['current_assets']['total'] ) ); ?></td></tr>
					<tr><td colspan="2"><em><?php esc_html_e( 'Non-Current Assets', 'wpledger' ); ?></em></td></tr>
					<?php foreach ( $data['noncurrent_assets']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['name'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr class="wpl-total"><td><?php esc_html_e( 'TOTAL ASSETS', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['total_assets'] ) ); ?></td></tr>

					<tr class="wpl-section"><td colspan="2"><?php esc_html_e( 'LIABILITIES', 'wpledger' ); ?></td></tr>
					<?php foreach ( $data['current_liabilities']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['name'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<?php foreach ( $data['noncurrent_liabilities']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['name'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr class="wpl-subtotal"><td><?php esc_html_e( 'Total Liabilities', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['total_liabilities'] ) ); ?></td></tr>

					<tr class="wpl-section"><td colspan="2"><?php esc_html_e( 'EQUITY', 'wpledger' ); ?></td></tr>
					<?php foreach ( $data['equity']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['name'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr><td class="wpl-indent"><?php esc_html_e( 'Current Year Earnings', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['current_year_earnings'] ) ); ?></td></tr>
					<tr class="wpl-subtotal"><td><?php esc_html_e( 'Total Equity', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['total_equity'] ) ); ?></td></tr>

					<tr class="wpl-total"><td><?php esc_html_e( 'TOTAL LIABILITIES & EQUITY', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['total_liab_and_equity'] ) ); ?></td></tr>
				</tbody>
			</table>

		<?php elseif ( 'income_statement' === $statement ) : ?>
			<h2><?php esc_html_e( 'Income Statement', 'wpledger' ); ?> — <?php echo esc_html( $data['period']['start'] ); ?> <?php esc_html_e( 'to', 'wpledger' ); ?> <?php echo esc_html( $data['period']['end'] ); ?></h2>
			<table class="widefat lc-statement">
				<tbody>
					<tr class="wpl-section"><td colspan="2"><?php esc_html_e( 'REVENUE', 'wpledger' ); ?></td></tr>
					<?php foreach ( $data['revenue']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['name'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr class="wpl-subtotal"><td><?php esc_html_e( 'Gross Profit', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['gross_profit'] ) ); ?></td></tr>
					<tr class="wpl-section"><td colspan="2"><?php esc_html_e( 'OPERATING EXPENSES', 'wpledger' ); ?></td></tr>
					<?php foreach ( $data['operating_expenses']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['name'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr class="wpl-subtotal"><td><?php esc_html_e( 'Operating Income', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['operating_income'] ) ); ?></td></tr>
					<tr class="wpl-total"><td><?php esc_html_e( 'NET INCOME', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['net_income'] ) ); ?></td></tr>
				</tbody>
			</table>

		<?php elseif ( 'cash_flow' === $statement ) : ?>
			<h2><?php esc_html_e( 'Cash Flow Statement', 'wpledger' ); ?> — <?php echo esc_html( $data['period']['start'] ); ?> <?php esc_html_e( 'to', 'wpledger' ); ?> <?php echo esc_html( $data['period']['end'] ); ?>
				<?php if ( ! $data['reconciles'] ) : ?>
					<span style="color:red"> ⚠ <?php esc_html_e( 'DOES NOT RECONCILE', 'wpledger' ); ?></span>
				<?php endif; ?>
			</h2>
			<table class="widefat lc-statement">
				<tbody>
					<tr class="wpl-section"><td colspan="2"><?php esc_html_e( 'OPERATING ACTIVITIES', 'wpledger' ); ?></td></tr>
					<?php foreach ( $data['operating']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['label'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr class="wpl-subtotal"><td><?php esc_html_e( 'Net Cash from Operating', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['operating']['total'] ) ); ?></td></tr>
					<tr class="wpl-section"><td colspan="2"><?php esc_html_e( 'INVESTING ACTIVITIES', 'wpledger' ); ?></td></tr>
					<?php foreach ( $data['investing']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['label'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr class="wpl-subtotal"><td><?php esc_html_e( 'Net Cash from Investing', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['investing']['total'] ) ); ?></td></tr>
					<tr class="wpl-section"><td colspan="2"><?php esc_html_e( 'FINANCING ACTIVITIES', 'wpledger' ); ?></td></tr>
					<?php foreach ( $data['financing']['rows'] as $r ) : ?>
						<tr><td class="wpl-indent"><?php echo esc_html( $r['label'] ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $r['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<tr class="wpl-subtotal"><td><?php esc_html_e( 'Net Cash from Financing', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['financing']['total'] ) ); ?></td></tr>
					<tr class="wpl-total"><td><?php esc_html_e( 'NET CHANGE IN CASH', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['net_change_in_cash'] ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Beginning Cash', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['beginning_cash'] ) ); ?></td></tr>
					<tr class="wpl-total"><td><?php esc_html_e( 'Ending Cash', 'wpledger' ); ?></td><td class="wpl-amt"><?php echo esc_html( $fmt( $data['ending_cash'] ) ); ?></td></tr>
				</tbody>
			</table>
		<?php endif; ?>

	<?php endif; ?>
</div>
