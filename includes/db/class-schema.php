<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Schema — table name helpers and dbDelta creation SQL.
 *
 * Authentication for the REST API uses WordPress Application Passwords
 * (built-in since WP 5.6) — no custom key table is needed.
 *
 * @package WPLedger\Db
 */

namespace WPLedger\Db;

/**
 * Manages custom table creation and version upgrades via dbDelta.
 */
class Schema {

	/** Plugin tables — order matters for FK-safe drops (children first). */
	public const TABLES = [ 'journal_lines', 'journal_entries', 'accounts', 'companies' ];

	/**
	 * Return the full prefixed table name for a given lc_ table.
	 *
	 * @param string $name Table suffix after lc_ (e.g. 'companies').
	 * @return string Full table name including WP prefix.
	 */
	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wpl_' . $name;
	}

	/**
	 * Create or upgrade all plugin tables using dbDelta.
	 *
	 * dbDelta rules: two spaces before PRIMARY KEY, each field on its own line,
	 * use KEY not INDEX.
	 *
	 * @return void
	 */
	public static function create(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$companies = self::t( 'companies' );
		$accounts  = self::t( 'accounts' );
		$entries   = self::t( 'journal_entries' );
		$lines     = self::t( 'journal_lines' );

		$sql = [];

		$sql[] = "CREATE TABLE {$companies} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            legal_name VARCHAR(200) NULL,
            ein VARCHAR(20) NULL,
            address TEXT NULL,
            fiscal_year_start_month TINYINT NOT NULL DEFAULT 1,
            base_currency CHAR(3) NOT NULL DEFAULT 'USD',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset};";

		$sql[] = "CREATE TABLE {$accounts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(200) NOT NULL,
            type VARCHAR(12) NOT NULL,
            subtype VARCHAR(24) NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            is_cash TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_company_code (company_id, code),
            KEY idx_company_type (company_id, type)
        ) {$charset};";

		// uq_idempotency: the DB-level guard against double-posting retried webhooks.
		// source_external_id is nullable; NULL values are exempt from the unique constraint.
		$sql[] = "CREATE TABLE {$entries} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            entry_date DATE NOT NULL,
            memo TEXT NULL,
            reference VARCHAR(100) NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'manual',
            source_external_id VARCHAR(100) NULL,
            posted TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_idempotency (company_id, source, source_external_id),
            KEY idx_company_date (company_id, entry_date)
        ) {$charset};";

		$sql[] = "CREATE TABLE {$lines} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            debit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            credit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            line_memo VARCHAR(255) NULL,
            PRIMARY KEY  (id),
            KEY idx_entry (entry_id),
            KEY idx_account (account_id)
        ) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'wpledger_db_version', WPLEDGER_VERSION );
	}

	/**
	 * Drop all plugin tables in child-first order (safe for FK constraints).
	 *
	 * Called by uninstall.php only.
	 *
	 * @return void
	 */
	public static function drop_all(): void {
		global $wpdb;

		foreach ( self::TABLES as $name ) {
			$table = self::t( $name );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
