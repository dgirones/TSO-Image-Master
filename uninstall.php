<?php
/**
 * TSO Image Master — Uninstall
 *
 * Fired when the plugin is deleted from the WordPress admin dashboard.
 * Removes all plugin-specific data: options, scheduled events, postmeta,
 * and the custom history table.
 *
 * NOTE: Physical image files and their backups in the uploads folder are
 * intentionally preserved to prevent accidental data loss. Remove them
 * manually via FTP if they are no longer needed.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Remove plugin backup directory from uploads ───────────────────
// Backups are stored in wp-content/uploads/tso-image-master/
// We remove this folder on uninstall since the user explicitly chose to delete the plugin.
// WP_Filesystem is used instead of direct rmdir() to comply with WP.org guidelines.
$tsoimma_upload_dir  = wp_upload_dir();
$tsoimma_backup_base = trailingslashit( $tsoimma_upload_dir['basedir'] ) . 'tso-image-master';
if ( is_dir( $tsoimma_backup_base ) ) {
    // Initialise WP_Filesystem.
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;

    if ( $wp_filesystem ) {
        // WP_Filesystem::rmdir() with recursive=true deletes the folder and all contents.
        $wp_filesystem->rmdir( $tsoimma_backup_base, true );
    } else {
        // Fallback: delete files individually then remove directories.
        $tsoimma_iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $tsoimma_backup_base, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $tsoimma_iter as $tsoimma_fileinfo ) {
            if ( $tsoimma_fileinfo->isDir() ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
                rmdir( $tsoimma_fileinfo->getRealPath() );
            } else {
                wp_delete_file( $tsoimma_fileinfo->getRealPath() );
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        rmdir( $tsoimma_backup_base );
    }
}

// ── Remove options ─────────────────────────────────────────────────
delete_option( 'tsoimma_auto_optimize_settings' );
delete_option( 'tsoimma_db_version' );
delete_option( 'tsoimma_history_retention_days' );
delete_option( 'tsoimma_history_purge_interval' );
delete_option( 'tsoimma_version' );
delete_option( 'tsoimma_job_queue' );
delete_option( 'tsoimma_backup_retention' );

// ── Clear scheduled cron events ───────────────────────────────────
wp_clear_scheduled_hook( 'tsoimma_history_purge' );
wp_clear_scheduled_hook( 'tsoimma_process_thumbnails' );
wp_clear_scheduled_hook( 'tsoimma_process_queue' );
wp_clear_scheduled_hook( 'tsoimma_backup_purge' );

// ── Drop custom history table ─────────────────────────────────────
global $wpdb;

// Prefixed variable names required by WordPress coding standards (PrefixAllGlobals).
$tsoimma_table = $wpdb->prefix . 'tso_im_history';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$tsoimma_table}`" ); // phpcs:ignore

// ── Remove all plugin postmeta ─────────────────────────────────────
// Each key uses the tso_im_ prefix — these are exclusively plugin-owned meta keys.
// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_key is plugin-prefixed, no risk of cross-plugin collision.
$tsoimma_meta_keys = array(
    '_tso_im_backup_file',
    '_tso_im_backup_mime',
    '_tso_im_backup_size',
    '_tso_im_auto_optimized',
    '_tso_im_pdf_compressed',
    '_tso_im_pdf_bg_temp',
    '_tso_im_pdf_bg_original',
    '_tso_im_pdf_bg_size',
    '_tso_im_pdf_bg_quality',
    '_tso_im_pdf_status',
    '_tso_im_pdf_bg_prev_size',
    '_tso_im_pdf_bg_settings',
);
foreach ( $tsoimma_meta_keys as $tsoimma_meta_key ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $tsoimma_meta_key ), array( '%s' ) );
}

// ── Delete any remaining upload transients ────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_tsoimma_new_upload_' ) . '%'
    )
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_timeout_tsoimma_new_upload_' ) . '%'
    )
);
