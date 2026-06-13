<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * PDF Renderer — wraps Dompdf to produce financial statement PDFs.
 *
 * @package WPLedger\Pdf
 */

namespace WPLedger\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Builds HTML representations of financial statements and renders them to PDF via Dompdf.
 * All dynamic output is escaped before insertion into HTML.
 */
class PdfRenderer {

	/**
	 * Render an HTML string to raw PDF bytes via Dompdf.
	 *
	 * @param string $html Complete HTML document.
	 * @return string Raw PDF bytes.
	 */
	public static function render_html( string $html ): string {
		$options = new Options();
		$options->set( 'isRemoteEnabled', false ); // Never fetch remote resources.
		$options->set( 'defaultFont', 'Helvetica' );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'letter', 'portrait' );
		$dompdf->render();

		return $dompdf->output();
	}

	/**
	 * Shared CSS for all statement PDFs.
	 *
	 * @return string <style> block.
	 */
	private static function base_css(): string {
		return '<style>
			@page { margin: 1in; }
			body { font-family: Helvetica, sans-serif; font-size: 11px; color: #1a1a1a; }
			h1 { font-size: 18px; margin: 0; }
			.sub { color: #666; margin-top: 2px; margin-bottom: 4px; }
			table { width: 100%; border-collapse: collapse; margin-top: 18px; }
			td { padding: 4px 0; }
			.amt { text-align: right; }
			.section { font-weight: 700; padding-top: 10px; background: #f5f5f5; }
			.total { border-top: 1px solid #000; font-weight: 700; }
			.grand { border-top: 1px solid #000; border-bottom: 3px double #000; font-weight: 700; }
			.indent { padding-left: 16px; }
			.negative { color: #c00; }
		</style>';
	}

	/**
	 * Build the Balance Sheet HTML (all output escaped).
	 *
	 * @param array $company Array with 'name' key.
	 * @param array $bs      Balance sheet data from Statements::balance_sheet().
	 * @return string Complete HTML document.
	 */
	public static function balance_sheet_html( array $company, array $bs ): string {
		$fmt  = fn( $v ) => number_format( (float) $v, 2 );
		$rows = function ( array $section ) use ( $fmt ) {
			$out = '';

			foreach ( $section['rows'] as $r ) {
				$out .= '<tr><td class="indent">' . esc_html( $r['name'] ) . '</td>'
					. '<td class="amt">' . esc_html( $fmt( $r['amount'] ) ) . '</td></tr>';
			}

			return $out;
		};

		return '<html><head>' . self::base_css() . '</head><body>'
			. '<h1>' . esc_html( $company['name'] ) . '</h1>'
			. '<div class="sub">' . esc_html__( 'Balance Sheet', 'wpledger' ) . ' &mdash; '
			. esc_html__( 'As of', 'wpledger' ) . ' ' . esc_html( $bs['as_of'] ) . '</div>'
			. '<table>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'ASSETS', 'wpledger' ) . '</td></tr>'
			. '<tr><td><em>' . esc_html__( 'Current Assets', 'wpledger' ) . '</em></td><td></td></tr>'
			. $rows( $bs['current_assets'] )
			. '<tr class="total"><td>' . esc_html__( 'Total Current Assets', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $bs['current_assets']['total'] ) ) . '</td></tr>'
			. '<tr><td><em>' . esc_html__( 'Non-Current Assets', 'wpledger' ) . '</em></td><td></td></tr>'
			. $rows( $bs['noncurrent_assets'] )
			. '<tr class="total"><td>' . esc_html__( 'Total Non-Current Assets', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $bs['noncurrent_assets']['total'] ) ) . '</td></tr>'
			. '<tr class="grand"><td>' . esc_html__( 'TOTAL ASSETS', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $bs['total_assets'] ) ) . '</td></tr>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'LIABILITIES', 'wpledger' ) . '</td></tr>'
			. '<tr><td><em>' . esc_html__( 'Current Liabilities', 'wpledger' ) . '</em></td><td></td></tr>'
			. $rows( $bs['current_liabilities'] )
			. '<tr class="total"><td>' . esc_html__( 'Total Current Liabilities', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $bs['current_liabilities']['total'] ) ) . '</td></tr>'
			. '<tr><td><em>' . esc_html__( 'Non-Current Liabilities', 'wpledger' ) . '</em></td><td></td></tr>'
			. $rows( $bs['noncurrent_liabilities'] )
			. '<tr class="total"><td>' . esc_html__( 'Total Liabilities', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $bs['total_liabilities'] ) ) . '</td></tr>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'EQUITY', 'wpledger' ) . '</td></tr>'
			. $rows( $bs['equity'] )
			. '<tr><td class="indent">' . esc_html__( 'Current Year Earnings', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $bs['current_year_earnings'] ) ) . '</td></tr>'
			. '<tr class="total"><td>' . esc_html__( 'Total Equity', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $bs['total_equity'] ) ) . '</td></tr>'
			. '<tr class="grand"><td>' . esc_html__( 'TOTAL LIABILITIES & EQUITY', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $bs['total_liab_and_equity'] ) ) . '</td></tr>'
			. '</table></body></html>';
	}

	/**
	 * Build the Income Statement HTML (all output escaped).
	 *
	 * @param array $company Array with 'name' key.
	 * @param array $is      Income statement data from Statements::income_statement().
	 * @return string Complete HTML document.
	 */
	public static function income_statement_html( array $company, array $is ): string {
		$fmt  = fn( $v ) => number_format( (float) $v, 2 );
		$rows = function ( array $section ) use ( $fmt ) {
			$out = '';

			foreach ( $section['rows'] as $r ) {
				$out .= '<tr><td class="indent">' . esc_html( $r['name'] ) . '</td>'
					. '<td class="amt">' . esc_html( $fmt( $r['amount'] ) ) . '</td></tr>';
			}

			return $out;
		};

		return '<html><head>' . self::base_css() . '</head><body>'
			. '<h1>' . esc_html( $company['name'] ) . '</h1>'
			. '<div class="sub">' . esc_html__( 'Income Statement', 'wpledger' ) . ' &mdash; '
			. esc_html( $is['period']['start'] ) . ' ' . esc_html__( 'to', 'wpledger' ) . ' ' . esc_html( $is['period']['end'] ) . '</div>'
			. '<table>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'REVENUE', 'wpledger' ) . '</td></tr>'
			. $rows( $is['revenue'] )
			. '<tr class="total"><td>' . esc_html__( 'Total Revenue', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $is['revenue']['total'] ) ) . '</td></tr>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'COST OF GOODS SOLD', 'wpledger' ) . '</td></tr>'
			. $rows( $is['cogs'] )
			. '<tr class="total"><td>' . esc_html__( 'Gross Profit', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $is['gross_profit'] ) ) . '</td></tr>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'OPERATING EXPENSES', 'wpledger' ) . '</td></tr>'
			. $rows( $is['operating_expenses'] )
			. '<tr class="total"><td>' . esc_html__( 'Operating Income', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $is['operating_income'] ) ) . '</td></tr>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'OTHER INCOME / EXPENSE', 'wpledger' ) . '</td></tr>'
			. $rows( $is['other_income'] )
			. $rows( $is['other_expense'] )
			. '<tr class="grand"><td>' . esc_html__( 'NET INCOME', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $is['net_income'] ) ) . '</td></tr>'
			. '</table></body></html>';
	}

	/**
	 * Build the Cash Flow Statement HTML (all output escaped).
	 *
	 * @param array $company Array with 'name' key.
	 * @param array $cf      Cash flow data from Statements::cash_flow_statement().
	 * @return string Complete HTML document.
	 */
	public static function cash_flow_html( array $company, array $cf ): string {
		$fmt  = fn( $v ) => number_format( (float) $v, 2 );
		$rows = function ( array $section ) use ( $fmt ) {
			$out = '';

			foreach ( $section['rows'] as $r ) {
				$out .= '<tr><td class="indent">' . esc_html( $r['label'] ) . '</td>'
					. '<td class="amt">' . esc_html( $fmt( $r['amount'] ) ) . '</td></tr>';
			}

			return $out;
		};

		return '<html><head>' . self::base_css() . '</head><body>'
			. '<h1>' . esc_html( $company['name'] ) . '</h1>'
			. '<div class="sub">' . esc_html__( 'Cash Flow Statement (Indirect Method)', 'wpledger' ) . ' &mdash; '
			. esc_html( $cf['period']['start'] ) . ' ' . esc_html__( 'to', 'wpledger' ) . ' ' . esc_html( $cf['period']['end'] ) . '</div>'
			. '<table>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'OPERATING ACTIVITIES', 'wpledger' ) . '</td></tr>'
			. $rows( $cf['operating'] )
			. '<tr class="total"><td>' . esc_html__( 'Net Cash from Operating', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $cf['operating']['total'] ) ) . '</td></tr>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'INVESTING ACTIVITIES', 'wpledger' ) . '</td></tr>'
			. $rows( $cf['investing'] )
			. '<tr class="total"><td>' . esc_html__( 'Net Cash from Investing', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $cf['investing']['total'] ) ) . '</td></tr>'
			. '<tr class="section"><td colspan="2">' . esc_html__( 'FINANCING ACTIVITIES', 'wpledger' ) . '</td></tr>'
			. $rows( $cf['financing'] )
			. '<tr class="total"><td>' . esc_html__( 'Net Cash from Financing', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $cf['financing']['total'] ) ) . '</td></tr>'
			. '<tr class="grand"><td>' . esc_html__( 'NET CHANGE IN CASH', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $cf['net_change_in_cash'] ) ) . '</td></tr>'
			. '<tr><td>' . esc_html__( 'Beginning Cash Balance', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $cf['beginning_cash'] ) ) . '</td></tr>'
			. '<tr class="grand"><td>' . esc_html__( 'Ending Cash Balance', 'wpledger' ) . '</td>'
			. '<td class="amt">' . esc_html( $fmt( $cf['ending_cash'] ) ) . '</td></tr>'
			. '</table></body></html>';
	}

	/**
	 * Build a combined financial package PDF (all three statements, page-broken).
	 *
	 * @param array $company Company info array.
	 * @param array $bs      Balance sheet data.
	 * @param array $is      Income statement data.
	 * @param array $cf      Cash flow data.
	 * @return string Raw PDF bytes.
	 */
	public static function financial_package( array $company, array $bs, array $is, array $cf ): string {
		$page_break = '<div style="page-break-after: always;"></div>';

		$html = self::balance_sheet_html( $company, $bs )
			. $page_break
			. self::income_statement_html( $company, $is )
			. $page_break
			. self::cash_flow_html( $company, $cf );

		return self::render_html( $html );
	}
}
