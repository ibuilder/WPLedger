<?php
/**
 * Admin view: Companies list and create form.
 *
 * Variables available: $companies (array), $flash (array|false)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap wpl-wrap">
	<h1><?php esc_html_e( 'WPLedger — Companies', 'wpledger' ); ?></h1>

	<?php if ( $flash ) : ?>
		<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Companies', 'wpledger' ); ?></h2>
	<?php if ( $companies ) : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Name', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Currency', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'FY Start Month', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wpledger' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $companies as $c ) : ?>
					<tr>
						<td><?php echo absint( $c->id ); ?></td>
						<td><?php echo esc_html( $c->name ); ?></td>
						<td><?php echo esc_html( $c->base_currency ); ?></td>
						<td><?php echo absint( $c->fiscal_year_start_month ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpl-accounts&company_id=' . absint( $c->id ) ) ); ?>"><?php esc_html_e( 'Chart of Accounts', 'wpledger' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpl-statements&company_id=' . absint( $c->id ) ) ); ?>"><?php esc_html_e( 'Statements', 'wpledger' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php esc_html_e( 'No companies yet.', 'wpledger' ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Add Company', 'wpledger' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wpl_create_company' ); ?>
		<input type="hidden" name="action" value="wpl_create_company">
		<table class="form-table">
			<tr>
				<th><label for="wpl-name"><?php esc_html_e( 'Company Name', 'wpledger' ); ?></label></th>
				<td><input type="text" id="wpl-name" name="name" class="regular-text" required></td>
			</tr>
			<tr>
				<th><label for="wpl-legal"><?php esc_html_e( 'Legal Name', 'wpledger' ); ?></label></th>
				<td><input type="text" id="wpl-legal" name="legal_name" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="wpl-currency"><?php esc_html_e( 'Base Currency', 'wpledger' ); ?></label></th>
				<td><input type="text" id="wpl-currency" name="base_currency" value="USD" maxlength="3" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="wpl-fymonth"><?php esc_html_e( 'Fiscal Year Start Month', 'wpledger' ); ?></label></th>
				<td>
					<select id="wpl-fymonth" name="fiscal_year_start_month">
						<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>"><?php echo esc_html( gmdate( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?></option>
						<?php endfor; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Create Company', 'wpledger' ) ); ?>
	</form>
</div>
