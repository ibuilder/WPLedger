=== WPLedger Accounting ===
Contributors: ibuilder
Tags: accounting, bookkeeping, double-entry, financial statements, invoicing
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Double-entry accounting for WordPress: chart of accounts, journal entries, Balance Sheet, Income Statement, Cash Flow, PDF export, and a REST API.

== Description ==

WPLedger brings a full double-entry accounting engine into WordPress.
Manage multiple companies, maintain an industry-standard chart of accounts,
post journal entries, and produce the three core financial statements — all
stored in your own database, never in a third-party cloud.

**Key features:**

* **Double-entry ledger** — every transaction must balance; the engine refuses
  to save anything that does not. Balances are always derived, never stored.
* **Chart of accounts** — 24-account default COA seeded automatically (assets,
  liabilities, equity, revenue, expenses). Fully editable.
* **Balance Sheet** — point-in-time snapshot. Includes the current-year
  earnings not yet closed to Retained Earnings. Exposes a `balanced` flag.
* **Income Statement** — revenue, COGS, gross profit, operating expenses,
  operating income, other income/expense, net income — over any date range.
* **Cash Flow Statement** — indirect method with operating/investing/financing
  sections and a `reconciles` flag that guarantees accuracy.
* **PDF export** — all three statements (and a combined financial package) via
  Dompdf, streamed as attachment-download PDFs.
* **REST API** — all reports and journal entries accessible via
  `wpledger/v1/` endpoints. Authentication uses WordPress Application
  Passwords (built-in since WP 5.6) — no custom keys needed.
* **Integration endpoints** — purpose-built routes let invoicing and
  project-management tools push invoices, payments, and project costs; the
  idempotency key prevents double-posting retried webhooks.
* **Admin UI** — Companies, Chart of Accounts (add/edit/toggle accounts),
  Manual Journal Entry, Journal Ledger (browse all posted entries),
  Statements viewer (with PDF download), and REST API information.
* **WooCommerce integration** — automatically post double-entry journal entries
  when orders reach Processing or Completed status (DR Cash / CR Sales Revenue
  + Tax Payable + Shipping). Refunds post a reversing entry. A one-click
  historical sync posts entries for all past orders. Idempotent: duplicate
  orders are silently skipped.

**Non-negotiable accounting invariants enforced by the engine:**

1. Every journal entry has ≥ 2 lines; total debits must equal total credits and
   the total must be > 0.
2. Balances are derived (`SUM(debit) − SUM(credit)`) — nothing is ever
   overwritten; corrections are new entries.
3. The accounting equation Assets = Liabilities + Equity always holds.
4. The Cash Flow Statement reconciles against actual cash-account movement.
5. All three statements read the same general ledger — they cannot disagree.

**Money handling:** All amounts are stored as `DECIMAL(18,2)` — never `FLOAT`.
All arithmetic in PHP uses `bcmath` with scale 2.

**Privacy:** WPLedger does not collect, store, or transmit any user data to
external servers. All ledger data lives in your own WordPress database tables.
No tracking, no phone-home, no external service required.

== Installation ==

**Automatic installation (recommended)**

1. In your WordPress admin, go to Plugins → Add New Plugin.
2. Search for "WPLedger Accounting".
3. Click Install Now, then Activate.

**Manual installation**

1. Download the plugin .zip file.
2. In your WordPress admin, go to Plugins → Add New Plugin → Upload Plugin.
3. Choose the .zip file and click Install Now, then Activate.
4. On first activation the plugin creates its database tables and seeds a
   "Demo Company" with a standard chart of accounts.

**After activation**

1. Navigate to **WPLedger** in the admin sidebar.
2. Create your company (or rename the Demo Company).
3. Review the Chart of Accounts; add or deactivate accounts as needed.
4. Start posting Journal Entries, or see **WPLedger → REST API** for
   instructions on connecting an external invoicing / PM tool via
   WordPress Application Passwords.

== Frequently Asked Questions ==

= Does WPLedger store data in custom post types or postmeta? =

No. All ledger data lives in four dedicated custom tables
(`lc_companies`, `lc_accounts`, `lc_journal_entries`, `lc_journal_lines`)
created via dbDelta on activation.

= Can I have more than one company? =

Yes. You can create as many companies as you need. Each company gets its own
chart of accounts and general ledger. Reports and API keys are company-scoped.

= What happens if I try to post an unbalanced journal entry? =

The engine throws a `LedgerException` and rolls back the database transaction.
Nothing is written. The REST API returns HTTP 422 with the error message.

= Are monetary amounts stored as floats? =

Never. All amounts in the database are `DECIMAL(18,2)`. All PHP arithmetic
uses `bcmath` with scale 2, treating amounts as strings.

= How do I prevent double-posting from a retried webhook? =

Pass a unique `external_id` with each integration request. The
`uq_idempotency` unique key on `(company_id, source, source_external_id)`
rejects any duplicate combination at the database level.

= Can I delete or edit a posted journal entry? =

No — by design. Double-entry accounting is immutable: corrections are new
reversing entries. This preserves a complete, tamper-evident audit trail.

= What PDF library does WPLedger use? =

Dompdf (^3.0), included as a Composer dependency. Remote resource loading is
disabled (`isRemoteEnabled = false`) so statement HTML cannot trigger external
HTTP requests.

= Will uninstalling the plugin delete my data? =

Yes. `uninstall.php` drops all plugin tables (`wpl_companies`, `wpl_accounts`,
`wpl_journal_entries`, `wpl_journal_lines`, `wpl_recurring_entries`) and the
`wpledger_db_version` option when you delete the plugin via the admin.
Deactivating (without deleting) leaves data intact.

= Does this plugin send data to external servers? =

No. WPLedger makes no external HTTP requests. API keys authenticate inbound
connections from your tools; the plugin itself never "phones home".


== Changelog ==

= 0.1.0 =
* Initial release.
* Double-entry ledger engine with `bcmath` arithmetic.
* Default 24-account chart of accounts, auto-seeded on company creation.
* Balance Sheet, Income Statement, and Cash Flow Statement (indirect method).
* PDF export via Dompdf for all three statements and a combined package.
* REST API under `wpledger/v1` authenticated via WordPress Application Passwords.
* Integration endpoints for invoices, payments, project costs, and vendor bills.
* Admin UI: Companies, Chart of Accounts (add/toggle), Manual Journal Entry, Journal Ledger, Statements, REST API info.
* WooCommerce integration: automatic order/refund sync, historical sync, idempotent posting.
* Idempotency key prevents double-posting retried webhooks.

== Upgrade Notice ==

= 0.1.0 =
Initial release — no upgrade steps required.
