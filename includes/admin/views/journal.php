<?php
/**
 * Admin view: Manual Journal Entry form.
 *
 * Variables: $companies, $accounts, $company_id, $flash
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap lc-wrap">
	<h1><?php esc_html_e( 'New Journal Entry', 'wpledger' ); ?></h1>

	<?php if ( $flash ) : ?>
		<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="get" style="margin-bottom:16px">
		<input type="hidden" name="page" value="wpl-journal">
		<select name="company_id" onchange="this.form.submit()">
			<option value=""><?php esc_html_e( '— Select Company —', 'wpledger' ); ?></option>
			<?php foreach ( $companies as $c ) : ?>
				<option value="<?php echo absint( $c->id ); ?>" <?php selected( $company_id, $c->id ); ?>><?php echo esc_html( $c->name ); ?></option>
			<?php endforeach; ?>
		</select>
	</form>

	<?php if ( $company_id ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wpl_post_entry' ); ?>
			<input type="hidden" name="action" value="wpl_post_entry">
			<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">

			<table class="form-table">
				<tr>
					<th><label for="wpl-date"><?php esc_html_e( 'Entry Date', 'wpledger' ); ?></label></th>
					<td><input type="date" id="wpl-date" name="entry_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="wpl-memo"><?php esc_html_e( 'Memo', 'wpledger' ); ?></label></th>
					<td><input type="text" id="wpl-memo" name="memo" class="regular-text"></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Lines', 'wpledger' ); ?></h3>
			<table class="widefat" id="wpl-lines-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Account', 'wpledger' ); ?></th>
						<th><?php esc_html_e( 'Debit', 'wpledger' ); ?></th>
						<th><?php esc_html_e( 'Credit', 'wpledger' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php for ( $i = 0; $i < 5; $i++ ) : ?>
						<tr>
							<td>
								<select name="account_id[]">
									<option value=""><?php esc_html_e( '— Account —', 'wpledger' ); ?></option>
									<?php foreach ( $accounts as $a ) : ?>
										<option value="<?php echo absint( $a->id ); ?>"><?php echo esc_html( $a->code . ' ' . $a->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="text" name="debit[]" value="" placeholder="0.00" class="wpl-amount-input"></td>
							<td><input type="text" name="credit[]" value="" placeholder="0.00" class="wpl-amount-input"></td>
						</tr>
					<?php endfor; ?>
				</tbody>
			</table>

			<p id="wpl-totals" class="wpl-totals"></p>

			<?php submit_button( __( 'Post Entry', 'wpledger' ) ); ?>
		</form>
	<?php endif; ?>
</div>
