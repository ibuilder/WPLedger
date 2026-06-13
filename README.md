# Project: WPLedger — WordPress Accounting Plugin

## What this is
A WordPress plugin implementing a double-entry accounting system: companies,
chart of accounts, journal entries, three financial statements (Balance Sheet,
Income Statement, Cash Flow Statement), PDF export, and a REST API that accepts
data from external invoicing and project-management tools.

## Non-negotiable accounting invariants
1. Every transaction is a journal entry with 2+ lines. Total debits MUST equal
   total credits, and the total must be > 0. The engine refuses to save anything
   that does not balance.
2. Balances are NEVER stored. A balance is derived: SUM(debit) - SUM(credit)
   (or the reverse) across journal lines, up to a date. Nothing is ever
   overwritten; corrections are new entries.
3. The accounting equation Assets = Liabilities + Equity must always hold. The
   Balance Sheet exposes a `balanced` flag; if it is ever false, that is a bug.
4. The Cash Flow Statement must reconcile: net change in cash from the statement
   must equal the actual change in cash-flagged accounts. It exposes a
   `reconciles` flag.
5. All three statements read the SAME general ledger. They cannot disagree.

## Money handling — CRITICAL
- All monetary DB columns are DECIMAL(18,2). NEVER use FLOAT or DOUBLE.
- In PHP, NEVER do float arithmetic on money. Use bcmath (bcadd, bcsub, bccomp,
  bcmul) with scale 2, treating amounts as strings.
- MySQL DECIMAL aggregation (SUM) is exact, so summing in SQL is safe; combining
  results in PHP uses bcmath.

## WordPress platform rules
- Ledger data lives in CUSTOM TABLES via $wpdb, created with dbDelta() in the
  activation hook. DO NOT use custom post types or postmeta for accounting data.
- Every table name uses $wpdb->prefix. Plugin tables are prefixed `lc_`
  (e.g. {$wpdb->prefix}lc_journal_entries).
- ALL queries that take variables use $wpdb->prepare(). No exceptions.
- REST routes are registered under namespace `wpledger/v1` via
  register_rest_route, each with a permission_callback. Never leave a route open.
- Admin pages require the `manage_wpledger` capability and verify nonces on
  every write.
- Sanitize all input (sanitize_text_field, absint, etc.); escape all output
  (esc_html, esc_attr, esc_url, wp_kses_post).
- Text domain is `wpledger`; wrap user-facing strings in __() / esc_html__().

## Code organization
- PSR-4 autoloading via Composer under namespace `WPLedger\`.
- Classes in includes/, one responsibility each. No business logic in the main
  plugin file — it only bootstraps.
- Dompdf is a Composer dependency for PDF rendering.

## Coding standards
- Follow WordPress PHP coding standards (WPCS). Run `composer run lint` if a
  phpcs config exists.
- Add a short PHPDoc block to every public method.
- Prefer small, testable methods. The ledger and statements classes must be unit
  tested.

## How we work
- Build in the phases described by the human. After each phase, STOP and report
  what to test. Do not start the next phase until told.
- Before writing the statements engine, the ledger engine must pass its tests.
- When unsure about an accounting rule, ask rather than guess.
