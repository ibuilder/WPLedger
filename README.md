# WPLedger Accounting

A WordPress plugin that brings full **double-entry accounting** to WordPress — chart of accounts, journal entries, financial statements, PDF export, WooCommerce integration, and a REST API.

## Features

- **Double-entry ledger** — every transaction balances debits and credits; corrections are new entries, nothing is ever overwritten
- **Chart of Accounts** — ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE with subtypes; active/inactive toggle
- **Financial Statements** — Balance Sheet, Income Statement, Cash Flow Statement, Trial Balance, General Ledger; all with PDF download (Dompdf)
- **Recurring Journal Entries** — templates that post automatically via WP-Cron (monthly, quarterly, annually) with idempotency protection
- **WP Dashboard Widget** — monthly P&L snapshot (Revenue, Expenses, Net Income, Cash Balance)
- **WooCommerce Integration** — automatically journals WC orders and refunds; customer PDF invoices on My Account
- **REST API** — `wpledger/v1` namespace, authenticated with WordPress Application Passwords; endpoints for companies, journal entries, and reports
- **QuickBooks CSV export** — IIF-style export for importing into external accounting software
- **Multi-company** — manage separate books for multiple entities in one install

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Composer (for local development)

## Installation

### From wordpress.org (recommended)
Search for **WPLedger Accounting** in *Plugins → Add New*.

### Manual
1. Download `wpledger-0.1.0.zip` from the [releases page](https://github.com/ibuilder/WPLedger/releases)
2. Upload via *Plugins → Add New → Upload Plugin*
3. Activate — tables are created automatically on activation

### From source
```bash
git clone https://github.com/ibuilder/WPLedger.git
cd WPLedger
composer install
```
Copy or symlink the folder into `wp-content/plugins/`, then activate.

## Building a submission zip

```powershell
.\build.ps1
```

Produces `wpledger-0.1.0.zip` — a clean, production-ready archive with dev files, hidden files, and unnecessary vendor stubs stripped out.

## Database tables

All tables use the WordPress table prefix + `wpl_`:

| Table | Purpose |
|---|---|
| `wpl_companies` | Company/entity records |
| `wpl_accounts` | Chart of accounts |
| `wpl_journal_entries` | Journal entry headers |
| `wpl_journal_lines` | Individual debit/credit lines |
| `wpl_recurring_entries` | Recurring entry templates |

Tables are created via `dbDelta()` on activation and dropped cleanly by `uninstall.php`.

## Accounting invariants

1. Every entry has ≥ 2 lines; total debits **must** equal total credits
2. Balances are never stored — they are always derived from the ledger
3. Assets = Liabilities + Equity (Balance Sheet `balanced` flag)
4. Net cash change = change in cash-flagged accounts (Cash Flow `reconciles` flag)
5. All monetary values are `DECIMAL(18,2)`; PHP arithmetic uses `bcmath`

## Architecture

```
wpledger.php          — plugin header + bootstrap
includes/
  Admin/              — AdminMenu, DashboardWidget
  Db/                 — Schema (dbDelta table creation)
  Export/             — QuickBooksExporter
  Integrations/       — WoocommerceSync, WcInvoice
  Pdf/                — PdfRenderer (Dompdf wrapper)
  Rest/               — RestCompanies, RestJournal, RestReports, RestIntegrations
  Services/           — Ledger, Statements, Recurring
assets/
  css/admin.css
  js/admin.js
vendor/               — Composer dependencies (Dompdf, Masterminds HTML5, etc.)
```

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)
