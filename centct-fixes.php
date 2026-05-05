<?php
/**
 * Centreville Tech LLC – schema and query fixes for the forked 404 handler.
 *
 * Idempotent. Bumps the schema version option once applied so subsequent
 * plugins_loaded calls short-circuit cheaply.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const CENTCT_P404_SCHEMA_VERSION = '1';
const CENTCT_P404_SCHEMA_OPTION  = 'centct_p404_schema_version';

/**
 * Ensure the 404 log table has: real BINARY(16) url_hash key, count index,
 * and that the duplicate idx_url_hash prefix index is removed.
 */
function centct_p404_ensure_schema() {
    if ( get_option( CENTCT_P404_SCHEMA_OPTION ) === CENTCT_P404_SCHEMA_VERSION ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'redirects_404';

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        return;
    }

    // 1. Drop the misnamed duplicate prefix index if still present.
    $dup = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'idx_url_hash'
        )
    );
    if ( ! empty( $dup ) && $dup[0]->COLUMN_NAME === 'url' && (int) $dup[0]->SUB_PART === 191 ) {
        $wpdb->query( "ALTER TABLE `{$table}` DROP KEY `idx_url_hash`" );
    }

    // 2. Add a real hash column if missing.
    $has_col = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table, 'url_hash'
        )
    );
    if ( ! $has_col ) {
        $wpdb->query(
            "ALTER TABLE `{$table}`
             ADD COLUMN `url_hash` BINARY(16)
             GENERATED ALWAYS AS (UNHEX(MD5(`url`))) STORED"
        );
    }

    // 3. Add real hash key.
    $has_hash_key = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'idx_url_hash_real'
        )
    );
    if ( ! $has_hash_key ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_url_hash_real` (`url_hash`)" );
    }

    // 4. Add count index for admin sort-by-hits.
    $has_count_key = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'idx_count'
        )
    );
    if ( ! $has_count_key ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_count` (`count`)" );
    }

    update_option( CENTCT_P404_SCHEMA_OPTION, CENTCT_P404_SCHEMA_VERSION, false );
}

/**
 * Fast existence check for a recent (url, ip, referrer) row, using url_hash.
 * Returns the row object or null.
 */
function centct_p404_find_recent_entry( $url, $ip_address, $referrer, $cutoff_datetime ) {
    global $wpdb;
    $table = $wpdb->prefix . 'redirects_404';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, count FROM `{$table}`
             WHERE url_hash = UNHEX(MD5(%s))
               AND url = %s
               AND ip_address = %s
               AND referrer = %s
               AND last_redirected > %s
             ORDER BY last_redirected DESC
             LIMIT 1",
            $url, $url, $ip_address, $referrer, $cutoff_datetime
        )
    );
}
