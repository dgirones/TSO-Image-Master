<?php
/**
 * TSO Image Master — Uninstall
 *
 * Fired when the plugin is deleted from the WordPress admin dashboard.
 * Removes all plugin-specific data: options, scheduled events, postmeta,
 * and the custom history table.
 *
 * NOTE: The uploads/tso-image-master/ backup folder is deleted on uninstall
 * because those files are plugin-owned copies. Original media library images
 * under uploads/YYYY/MM/ are never deleted.
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
delete_option( 'tsoimma_history_legacy_merged' );
delete_option( 'tsoimma_history_retention_days' );
delete_option( 'tsoimma_history_purge_interval' );
delete_option( 'tsoimma_version' );
delete_option( 'tsoimma_job_queue' );
delete_option( 'tsoimma_backup_retention' );
delete_option( 'tsoimma_queue_lock' );

// ── Clear scheduled cron events ───────────────────────────────────
wp_clear_scheduled_hook( 'tsoimma_history_purge' );
wp_clear_scheduled_hook( 'tsoimma_process_thumbnails' );
wp_clear_scheduled_hook( 'tsoimma_process_queue' );
wp_clear_scheduled_hook( 'tsoimma_backup_purge' );

// ── Drop custom history tables ────────────────────────────────────
global $wpdb;

$tsoimma_storage = __DIR__ . '/includes/tsoimma-storage.php';
if ( file_exists( $tsoimma_storage ) ) {
	require_once $tsoimma_storage;
}

// Prefixed variable names required by WordPress coding standards (PrefixAllGlobals).
$tsoimma_history_tables = array(
	$wpdb->prefix . 'tsoimma_history',
	$wpdb->prefix . 'tso_im_history',
);
foreach ( $tsoimma_history_tables as $tsoimma_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$tsoimma_table}`" ); // phpcs:ignore
}

// ── Remove all plugin postmeta ─────────────────────────────────────
// Canonical and legacy keys from tsoimma_attachment_meta_key_map().
$tsoimma_meta_keys = array();
if ( function_exists( 'tsoimma_attachment_meta_key_map' ) ) {
	foreach ( tsoimma_attachment_meta_key_map() as $tsoimma_meta_pair ) {
		if ( ! empty( $tsoimma_meta_pair[0] ) ) {
			$tsoimma_meta_keys[] = $tsoimma_meta_pair[0];
		}
		if ( ! empty( $tsoimma_meta_pair[1] ) ) {
			$tsoimma_meta_keys[] = $tsoimma_meta_pair[1];
		}
	}
}
$tsoimma_meta_keys = array_values( array_unique( $tsoimma_meta_keys ) );
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
