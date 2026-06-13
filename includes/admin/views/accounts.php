<?php
/**
 * Admin view: Chart of Accounts — list, add, and toggle active/inactive.
 *
 * Variables: $companies (array), $accounts (array), $company_id (int), $flash (array|false)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$type_subtypes = [
	'ASSET'     => [ 'CURRENT_ASSET', 'NONCURRENT_ASSET' ],
	'LIABILITY' => [ 'CURRENT_LIABILITY', 'NONCURRENT_LIABILITY' ],
	'EQUITY'    => [ 'EQUITY' ],
	'REVENUE'   => [ 'OPERATING_REVENUE', 'OTHER_REVENUE' ],
	'EXPENSE'   => [ 'COGS', 'OPERATING_EXPENSE', 'OTHER_EXPENSE' ],
];
?>
<div class="wrap lc-wrap">
	<h1><?php esc_html_e( 'Chart of Accounts', 'wpledger' ); ?></h1>

	<?php if ( $flash ) : ?>
		<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="get">
		<input type="hidden" name="page" value="wpl-accounts">
		<select name="company_id" onchange="this.form.submit()">
			<option value=""><?php esc_html_e( '— Select Company —', 'wpledger' ); ?></option>
			<?php foreach ( $companies as $c ) : ?>
				<option value="<?php echo absint( $c->id ); ?>" <?php selected( $company_id, $c->id ); ?>>
					<?php echo esc_html( $c->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<?php if ( $accounts ) : ?>
		<table class="widefat striped" style="margin-top:16px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Name', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Subtype', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Cash', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Active', 'wpledger' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wpledger' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $accounts as $a ) : ?>
					<tr<?php echo $a->is_active ? '' : ' style="opacity:0.5"'; ?>>
						<td><?php echo esc_html( $a->code ); ?></td>
						<td><?php echo esc_html( $a->name ); ?></td>
						<td><?php echo esc_html( $a->type ); ?></td>
						<td><?php echo esc_html( $a->subtype ); ?></td>
						<td><?php echo $a->is_cash ? '&#10003;' : ''; ?></td>
						<td><?php echo $a->is_active ? '&#10003;' : ''; ?></td>
						<td style="white-space:nowrap">
							<button type="button" class="button button-small wpl-edit-btn"
								data-account-id="<?php echo absint( $a->id ); ?>"
								data-code="<?php echo esc_attr( $a->code ); ?>"
								data-name="<?php echo esc_attr( $a->name ); ?>"
								data-is-cash="<?php echo (int) $a->is_cash; ?>">
								<?php esc_html_e( 'Edit', 'wpledger' ); ?>
							</button>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'wpl_toggle_account' ); ?>
								<input type="hidden" name="action"     value="wpl_toggle_account">
								<input type="hidden" name="account_id" value="<?php echo absint( $a->id ); ?>">
								<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">
								<button type="submit" class="button button-small">
									<?php echo $a->is_active ? esc_html__( 'Deactivate', 'wpledger' ) : esc_html__( 'Activate', 'wpledger' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php elseif ( $company_id ) : ?>
		<p><?php esc_html_e( 'No accounts found for this company.', 'wpledger' ); ?></p>
	<?php endif; ?>

	<?php if ( $company_id ) : ?>
		<h2 style="margin-top:24px"><?php esc_html_e( 'Add Account', 'wpledger' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wpl_create_account' ); ?>
			<input type="hidden" name="action"     value="wpl_create_account">
			<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="wpl-acc-code"><?php esc_html_e( 'Code', 'wpledger' ); ?></label></th>
					<td><input type="text" id="wpl-acc-code" name="code" class="small-text" maxlength="20" required
						placeholder="<?php esc_attr_e( 'e.g. 1050', 'wpledger' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="wpl-acc-name"><?php esc_html_e( 'Name', 'wpledger' ); ?></label></th>
					<td><input type="text" id="wpl-acc-name" name="name" class="regular-text" maxlength="200" required></td>
				</tr>
				<tr>
					<th><label for="wpl-acc-type"><?php esc_html_e( 'Type', 'wpledger' ); ?></label></th>
					<td>
						<select id="wpl-acc-type" name="type" required onchange="lcUpdateSubtypes(this.value)">
							<option value=""><?php esc_html_e( '— Select —', 'wpledger' ); ?></option>
							<?php foreach ( array_keys( $type_subtypes ) as $t ) : ?>
								<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="wpl-acc-subtype"><?php esc_html_e( 'Subtype', 'wpledger' ); ?></label></th>
					<td>
						<select id="wpl-acc-subtype" name="subtype" required
							data-placeholder="<?php esc_attr_e( '— Select Type first —', 'wpledger' ); ?>">
							<option value=""><?php esc_html_e( '— Select Type first —', 'wpledger' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Is Cash Account?', 'wpledger' ); ?></th>
					<td><label><input type="checkbox" name="is_cash" value="1">
						<?php esc_html_e( 'Include in cash flow reconciliation', 'wpledger' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( __( 'Add Account', 'wpledger' ) ); ?>
		</form>

	<?php endif; ?>
</div>

<?php if ( $company_id ) : ?>
<div id="wpl-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
	<div style="background:#fff;padding:24px 28px;border-radius:4px;min-width:340px;max-width:90vw">
		<h2 style="margin-top:0"><?php esc_html_e( 'Edit Account', 'wpledger' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wpl_edit_account' ); ?>
			<input type="hidden" name="action"     value="wpl_edit_account">
			<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">
			<input type="hidden" name="account_id" id="wpl-edit-id">
			<table class="form-table" style="margin:0">
				<tr>
					<th><label for="wpl-edit-code"><?php esc_html_e( 'Code', 'wpledger' ); ?></label></th>
					<td><input type="text" id="wpl-edit-code" name="code" class="small-text" maxlength="20" required></td>
				</tr>
				<tr>
					<th><label for="wpl-edit-name"><?php esc_html_e( 'Name', 'wpledger' ); ?></label></th>
					<td><input type="text" id="wpl-edit-name" name="name" class="regular-text" maxlength="200" required></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Is Cash Account?', 'wpledger' ); ?></th>
					<td><label><input type="checkbox" id="wpl-edit-cash" name="is_cash" value="1">
						<?php esc_html_e( 'Include in cash flow reconciliation', 'wpledger' ); ?></label></td>
				</tr>
			</table>
			<div style="margin-top:16px;display:flex;gap:8px">
				<?php submit_button( __( 'Save Changes', 'wpledger' ), 'primary', 'submit', false ); ?>
				<button type="button" class="button wpl-modal-cancel">
					<?php esc_html_e( 'Cancel', 'wpledger' ); ?>
				</button>
			</div>
		</form>
	</div>
</div>
<?php endif; ?>
