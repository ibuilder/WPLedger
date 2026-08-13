<?php
/**
 * Admin view: Recurring Journal Entries.
 *
 * Variables: $companies (array), $company_id (int), $entries (array),
 *            $accounts (array), $flash (array|null)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wpl-wrap">
	<h1><?php esc_html_e( 'Recurring Journal Entries', 'wpledger' ); ?></h1>

	<?php if ( ! empty( $flash ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Set up journal entries that post automatically on a recurring schedule — ideal for monthly depreciation, rent, loan payments, and prepaid amortization.', 'wpledger' ); ?>
	</p>

	<?php /* Company selector */ ?>
	<form method="get" style="margin-bottom:16px;display:flex;gap:8px;align-items:center">
		<input type="hidden" name="page" value="wpl-recurring">
		<select name="company_id" onchange="this.form.submit()">
			<option value=""><?php esc_html_e( '— Select Company —', 'wpledger' ); ?></option>
			<?php foreach ( $companies as $c ) : ?>
				<option value="<?php echo absint( $c->id ); ?>" <?php selected( $company_id, $c->id ); ?>>
					<?php echo esc_html( $c->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<?php if ( $company_id ) : ?>

		<?php /* Existing recurring entries */ ?>
		<?php if ( ! empty( $entries ) ) : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'wpledger' ); ?></th>
						<th><?php esc_html_e( 'Frequency', 'wpledger' ); ?></th>
						<th><?php esc_html_e( 'Next Run', 'wpledger' ); ?></th>
						<th><?php esc_html_e( 'Memo', 'wpledger' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wpledger' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wpledger' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr <?php echo $e->is_active ? '' : 'style="opacity:.5"'; ?>>
							<td><?php echo esc_html( $e->name ); ?></td>
							<td><?php echo esc_html( ucfirst( $e->frequency ) ); ?></td>
							<td><?php echo esc_html( $e->next_run_date ); ?></td>
							<td><?php echo esc_html( $e->memo ?? '' ); ?></td>
							<td><?php echo $e->is_active ? esc_html__( 'Active', 'wpledger' ) : esc_html__( 'Paused', 'wpledger' ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( 'wpl_toggle_recurring' ); ?>
									<input type="hidden" name="action"     value="wpl_toggle_recurring">
									<input type="hidden" name="id"         value="<?php echo absint( $e->id ); ?>">
									<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">
									<button type="submit" class="button button-small">
										<?php echo $e->is_active ? esc_html__( 'Pause', 'wpledger' ) : esc_html__( 'Resume', 'wpledger' ); ?>
									</button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php esc_attr_e( 'Delete this recurring entry?', 'wpledger' ); ?>')">
									<?php wp_nonce_field( 'wpl_delete_recurring' ); ?>
									<input type="hidden" name="action"     value="wpl_delete_recurring">
									<input type="hidden" name="id"         value="<?php echo absint( $e->id ); ?>">
									<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">
									<button type="submit" class="button button-small button-link-delete">
										<?php esc_html_e( 'Delete', 'wpledger' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No recurring entries yet. Create one below.', 'wpledger' ); ?></p>
		<?php endif; ?>

		<hr>

		<?php /* Create new recurring entry */ ?>
		<h2><?php esc_html_e( 'Add Recurring Entry', 'wpledger' ); ?></h2>

		<?php if ( empty( $accounts ) ) : ?>
			<p class="notice notice-warning inline"><span><?php esc_html_e( 'Please create accounts for this company before adding recurring entries.', 'wpledger' ); ?></span></p>
		<?php else : ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wpl-recurring-form">
				<?php wp_nonce_field( 'wpl_create_recurring' ); ?>
				<input type="hidden" name="action"     value="wpl_create_recurring">
				<input type="hidden" name="company_id" value="<?php echo absint( $company_id ); ?>">

				<table class="form-table">
					<tr>
						<th><label for="wpl-rec-name"><?php esc_html_e( 'Name', 'wpledger' ); ?></label></th>
						<td>
							<input type="text" id="wpl-rec-name" name="name" class="regular-text" required
								placeholder="<?php esc_attr_e( 'e.g. Monthly Depreciation', 'wpledger' ); ?>">
						</td>
					</tr>
					<tr>
						<th><label for="wpl-rec-frequency"><?php esc_html_e( 'Frequency', 'wpledger' ); ?></label></th>
						<td>
							<select id="wpl-rec-frequency" name="frequency">
								<option value="monthly"><?php esc_html_e( 'Monthly', 'wpledger' ); ?></option>
								<option value="quarterly"><?php esc_html_e( 'Quarterly', 'wpledger' ); ?></option>
								<option value="annually"><?php esc_html_e( 'Annually', 'wpledger' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wpl-rec-start"><?php esc_html_e( 'First Run Date', 'wpledger' ); ?></label></th>
						<td>
							<input type="date" id="wpl-rec-start" name="start_date" required
								value="<?php echo esc_attr( gmdate( 'Y-m-01', strtotime( '+1 month' ) ) ); ?>">
							<p class="description"><?php esc_html_e( 'The entry will first post on this date, then repeat at the chosen frequency.', 'wpledger' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="wpl-rec-memo"><?php esc_html_e( 'Memo', 'wpledger' ); ?></label></th>
						<td>
							<input type="text" id="wpl-rec-memo" name="memo" class="regular-text"
								placeholder="<?php esc_attr_e( 'Optional narrative', 'wpledger' ); ?>">
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Journal Lines', 'wpledger' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Add at least two lines. Total debits must equal total credits.', 'wpledger' ); ?></p>

				<table class="widefat" id="wpl-rec-lines" style="max-width:700px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Account', 'wpledger' ); ?></th>
							<th><?php esc_html_e( 'Debit', 'wpledger' ); ?></th>
							<th><?php esc_html_e( 'Credit', 'wpledger' ); ?></th>
							<th><?php esc_html_e( 'Line Memo', 'wpledger' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="wpl-rec-lines-body">
						<?php for ( $i = 0; $i < 2; $i++ ) : ?>
							<tr>
								<td>
									<select name="account_id[]" required style="width:100%">
										<option value=""><?php esc_html_e( '— Select —', 'wpledger' ); ?></option>
										<?php foreach ( $accounts as $a ) : ?>
											<option value="<?php echo absint( $a->id ); ?>"><?php echo esc_html( $a->code . ' ' . $a->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="number" name="debit[]"     step="0.01" min="0" value="0.00" class="wpl-amount-input"></td>
								<td><input type="number" name="credit[]"    step="0.01" min="0" value="0.00" class="wpl-amount-input"></td>
								<td><input type="text"   name="line_memo[]" class="regular-text" maxlength="255"></td>
								<td><button type="button" class="button button-small wpl-remove-line">&times;</button></td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>

				<p style="margin-top:8px">
					<button type="button" class="button" id="wpl-add-rec-line">
						<?php esc_html_e( '+ Add Line', 'wpledger' ); ?>
					</button>
				</p>

				<?php submit_button( __( 'Save Recurring Entry', 'wpledger' ), 'primary', 'submit', false ); ?>
			</form>

		<?php endif; ?>

	<?php endif; ?>
</div>
