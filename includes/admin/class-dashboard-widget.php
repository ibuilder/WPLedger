<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * WPLedger Dashboard Widget.
 *
 * Displays current-month Revenue, Expenses, Net Income, and Cash Balance
 * on the WordPress admin dashboard for the first company found.
 *
 * @package WPLedger\Admin
 */

namespace WPLedger\Admin;

use WPLedger\Db\Schema;
use WPLedger\Services\Statements;
use WPLedger\Services\Ledger;

/**
 * Registers and renders the WPLedger summary dashboard widget.
 */
class DashboardWidget {

	/**
	 * Register the dashboard widget hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'add_widget' ] );
	}

	/**
	 * Register the widget with WordPress.
	 *
	 * @return void
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'manage_wpledger' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wpl_summary',
			__( 'WPLedger — Financial Summary', 'wpledger' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the widget content.
	 *
	 * @return void
	 */
	public function render(): void {
		global $wpdb;

		$company = $wpdb->get_row( 'SELECT * FROM ' . Schema::t( 'companies' ) . ' ORDER BY id LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $company ) {
			echo '<p>' . esc_html__( 'No company found. Create one in WPLedger → Companies.', 'wpledger' ) . '</p>';
			return;
		}

		$company_id = (int) $company['id'];
		$today      = gmdate( 'Y-m-d' );
		$month_start = gmdate( 'Y-m-01' );

		try {
			$is = Statements::income_statement( $company_id, $month_start, $today );
		} catch ( \Throwable $e ) {
			echo '<p>' . esc_html__( 'Error loading financial data.', 'wpledger' ) . '</p>';
			return;
		}

		// Cash balance: sum all cash-flagged accounts.
		$at      = Schema::t( 'accounts' );
		$cash_accounts = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM {$at} WHERE company_id = %d AND is_cash = 1", $company_id )
		) ?: [];

		$cash = '0.00';
		foreach ( $cash_accounts as $a ) {
			$cash = bcadd( $cash, Ledger::account_balance( (int) $a->id, $today ), 2 );
		}

		$fmt     = fn( $v ) => number_format( (float) $v, 2 );
		$revenue = $is['revenue']['total'];
		$opex    = $is['operating_expenses']['total'];
		$net     = $is['net_income'];

		$color_net  = bccomp( $net,  '0', 2 ) >= 0 ? '#246a24' : '#a00';
		$color_cash = bccomp( $cash, '0', 2 ) >= 0 ? '#246a24' : '#a00';

		$month_label = gmdate( 'F Y' );
		$manage_url  = admin_url( 'admin.php?page=wpl-statements&company_id=' . $company_id );

		?>
		<p style="margin:0 0 4px;color:#666;font-size:11px">
			<?php echo esc_html( $company['name'] ); ?> &mdash;
			<?php echo esc_html( $month_label ); ?>
		</p>
		<table style="width:100%;border-collapse:collapse">
			<tr>
				<td style="padding:6px 0;border-bottom:1px solid #eee"><?php esc_html_e( 'Revenue', 'wpledger' ); ?></td>
				<td style="text-align:right;padding:6px 0;border-bottom:1px solid #eee"><?php echo esc_html( $fmt( $revenue ) ); ?></td>
			</tr>
			<tr>
				<td style="padding:6px 0;border-bottom:1px solid #eee"><?php esc_html_e( 'Operating Expenses', 'wpledger' ); ?></td>
				<td style="text-align:right;padding:6px 0;border-bottom:1px solid #eee"><?php echo esc_html( $fmt( $opex ) ); ?></td>
			</tr>
			<tr>
				<td style="padding:6px 0;border-bottom:2px solid #ccc;font-weight:700"><?php esc_html_e( 'Net Income', 'wpledger' ); ?></td>
				<td style="text-align:right;padding:6px 0;border-bottom:2px solid #ccc;font-weight:700;color:<?php echo esc_attr( $color_net ); ?>"><?php echo esc_html( $fmt( $net ) ); ?></td>
			</tr>
			<tr>
				<td style="padding:8px 0 0;font-weight:700"><?php esc_html_e( 'Cash Balance', 'wpledger' ); ?></td>
				<td style="text-align:right;padding:8px 0 0;font-weight:700;color:<?php echo esc_attr( $color_cash ); ?>"><?php echo esc_html( $fmt( $cash ) ); ?></td>
			</tr>
		</table>
		<p style="margin:10px 0 0;text-align:right">
			<a href="<?php echo esc_url( $manage_url ); ?>" class="button button-small">
				<?php esc_html_e( 'View Statements &rarr;', 'wpledger' ); ?>
			</a>
		</p>
		<?php
	}
}
