<?php
/**
 * Admin view: REST API information and Application Passwords guide.
 *
 * Variables: $rest_base (string)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$profile_url = admin_url( 'profile.php#application-passwords-section' );
?>
<div class="wrap lc-wrap">
	<h1><?php esc_html_e( 'WPLedger REST API', 'wpledger' ); ?></h1>

	<div class="notice notice-info">
		<p>
			<?php esc_html_e( 'WPLedger uses WordPress Application Passwords for external API authentication — no custom keys needed.', 'wpledger' ); ?>
		</p>
	</div>

	<h2><?php esc_html_e( 'How to authenticate external tools', 'wpledger' ); ?></h2>
	<ol>
		<li>
			<?php
			printf(
				/* translators: %s: link to user profile */
				esc_html__( 'Go to %s and create an Application Password for the tool you want to connect.', 'wpledger' ),
				'<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Users → Your Profile → Application Passwords', 'wpledger' ) . '</a>'
			);
			?>
		</li>
		<li><?php esc_html_e( 'The tool authenticates with HTTP Basic auth: username and the Application Password (spaces are ignored).', 'wpledger' ); ?></li>
		<li><?php esc_html_e( 'The WordPress user must have the manage_wpledger capability (Administrators have it by default).', 'wpledger' ); ?></li>
	</ol>

	<h2><?php esc_html_e( 'Base URL', 'wpledger' ); ?></h2>
	<code><?php echo esc_html( $rest_base ); ?></code>

	<h2><?php esc_html_e( 'Example: post an invoice', 'wpledger' ); ?></h2>
	<pre class="wpl-code"><?php echo esc_html(
		"curl -X POST \\\n" .
		"  '" . $rest_base . "integrations/invoices' \\\n" .
		"  -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' \\\n" .
		"  -H 'Content-Type: application/json' \\\n" .
		"  -d '{\n" .
		'    "company_id": 1,' . "\n" .
		'    "date": "2026-06-01",' . "\n" .
		'    "external_id": "INV-0042",' . "\n" .
		'    "customer": "Acme Corp",' . "\n" .
		'    "lines": [{"amount": 1000.00, "revenue_code": "4000"}],' . "\n" .
		'    "tax": 80.00' . "\n" .
		"  }'"
	); ?></pre>

	<h2><?php esc_html_e( 'Available endpoints', 'wpledger' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Method', 'wpledger' ); ?></th>
				<th><?php esc_html_e( 'Endpoint', 'wpledger' ); ?></th>
				<th><?php esc_html_e( 'Description', 'wpledger' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			/*
			 * PDF downloads are NOT served through the REST API — binary output
			 * conflicts with WP's REST response pipeline. Use the admin Statements
			 * screen download buttons (admin-post.php) for PDFs instead.
			 */
			$endpoints = [
				[ 'GET',  'companies',                  __( 'List all companies', 'wpledger' ) ],
				[ 'POST', 'companies',                  __( 'Create a company (seeds chart of accounts)', 'wpledger' ) ],
				[ 'GET',  'companies/{id}',             __( 'Get a single company', 'wpledger' ) ],
				[ 'PUT',  'companies/{id}',             __( 'Update a company', 'wpledger' ) ],
				[ 'GET',  'journal?company_id={id}',    __( 'List journal entries (optional ?start= &end=)', 'wpledger' ) ],
				[ 'POST', 'journal',                    __( 'Post a manual journal entry', 'wpledger' ) ],
				[ 'GET',  'journal/{id}',               __( 'Get one entry including its lines', 'wpledger' ) ],
				[ 'GET',  'reports/balance-sheet',      __( 'Balance Sheet JSON (?company_id= &as_of=)', 'wpledger' ) ],
				[ 'GET',  'reports/income-statement',   __( 'Income Statement JSON (?company_id= &start= &end=)', 'wpledger' ) ],
				[ 'GET',  'reports/cash-flow',          __( 'Cash Flow Statement JSON (?company_id= &start= &end=)', 'wpledger' ) ],
				[ 'POST', 'integrations/invoices',      __( 'Post a customer invoice', 'wpledger' ) ],
				[ 'POST', 'integrations/payments',      __( 'Post a customer payment', 'wpledger' ) ],
				[ 'POST', 'integrations/project-costs', __( 'Post a project cost from a PM tool', 'wpledger' ) ],
				[ 'POST', 'integrations/vendor-bills',  __( 'Post a vendor bill', 'wpledger' ) ],
			];
			foreach ( $endpoints as [ $method, $path, $desc ] ) :
			?>
				<tr>
					<td><code><?php echo esc_html( $method ); ?></code></td>
					<td><code><?php echo esc_html( $path ); ?></code></td>
					<td><?php echo esc_html( $desc ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Report query parameters', 'wpledger' ); ?></h2>
	<ul>
		<li><code>company_id</code> — <?php esc_html_e( '(required integer) Company to report on.', 'wpledger' ); ?></li>
		<li><code>as_of</code> — <?php esc_html_e( 'Snapshot date for Balance Sheet (YYYY-MM-DD). Default: today.', 'wpledger' ); ?></li>
		<li><code>start</code> / <code>end</code> — <?php esc_html_e( 'Period dates for Income Statement and Cash Flow (YYYY-MM-DD).', 'wpledger' ); ?></li>
	</ul>

	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'PDF downloads', 'wpledger' ); ?></strong> —
			<?php esc_html_e( 'PDF export is available in the admin Statements screen. Binary output cannot be served safely through the WordPress REST API.', 'wpledger' ); ?>
		</p>
	</div>
</div>
