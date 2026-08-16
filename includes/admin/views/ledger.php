<?php
/**
 * Admin view: Journal Ledger — paginated list of posted entries.
 *
 * Variables: $companies, $company_id, $entries, $total, $pages, $paged, $per_page
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap wpl-wrap">
	<h1><?php esc_html_e( 'Journal Ledger', 'wpledger' ); ?></h1>

	<form method="get" style="margin-bottom:16px">
		<input type="hidden" name="page" value="wpl-ledger">
		<select name="company_id" onchange="this.form.submit()">
			<option value=""><?php esc_html_e( '— Select Company —', 'wpledger' ); ?></option>
			<?php foreach ( $companies as $c ) : ?>
				<option value="<?php echo absint( $c->id ); ?>" <?php selected( $company_id, $c->id ); ?>>
					<?php echo esc_html( $c->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<?php if ( $company_id && empty( $entries ) ) : ?>
		<p><?php esc_html_e( 'No journal entries posted yet.', 'wpledger' ); ?></p>
	<?php elseif ( $entries ) : ?>

		<p class="description">
			<?php
			printf(
				/* translators: 1: current page, 2: total pages, 3: total entries */
				esc_html__( 'Page %1$d of %2$d — %3$d entries total', 'wpledger' ),
				(int) $paged,
				(int) $pages,
				(int) $total
			);
			?>
		</p>

		<?php foreach ( $entries as $entry ) : ?>
			<table class="widefat wpl-statement" style="margin-bottom:16px">
				<thead>
					<tr class="wpl-section">
						<th colspan="4">
							#<?php echo absint( $entry->id ); ?>
							&nbsp;&mdash;&nbsp;
							<?php echo esc_html( $entry->entry_date ); ?>
							<?php if ( $entry->memo ) : ?>
								&nbsp;&mdash;&nbsp;<?php echo esc_html( $entry->memo ); ?>
							<?php endif; ?>
							<?php if ( $entry->reference ) : ?>
								<span style="font-weight:400;color:#666">(<?php echo esc_html( $entry->reference ); ?>)</span>
							<?php endif; ?>
							<span style="font-weight:400;float:right;color:#666"><?php echo esc_html( $entry->source ); ?></span>
						</th>
					</tr>
					<tr>
						<th style="width:8%"><?php esc_html_e( 'Code', 'wpledger' ); ?></th>
						<th><?php esc_html_e( 'Account', 'wpledger' ); ?></th>
						<th class="wpl-amt"><?php esc_html_e( 'Debit', 'wpledger' ); ?></th>
						<th class="wpl-amt"><?php esc_html_e( 'Credit', 'wpledger' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entry->lines as $line ) : ?>
						<tr>
							<td><?php echo esc_html( $line->code ); ?></td>
							<td><?php echo esc_html( $line->account_name ); ?>
								<?php if ( $line->line_memo ) : ?>
									<span style="color:#888"> — <?php echo esc_html( $line->line_memo ); ?></span>
								<?php endif; ?>
							</td>
							<td class="wpl-amt"><?php echo $line->debit > 0 ? esc_html( number_format( (float) $line->debit, 2 ) ) : ''; ?></td>
							<td class="wpl-amt"><?php echo $line->credit > 0 ? esc_html( number_format( (float) $line->credit, 2 ) ) : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post( paginate_links( [
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'total'   => $pages,
						'current' => $paged,
					] ) );
					?>
				</div>
			</div>
		<?php endif; ?>

	<?php endif; ?>
</div>
