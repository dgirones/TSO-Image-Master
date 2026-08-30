<?php
/**
 * Central input helpers, nonce verification, and attachment meta key migration.
 *
 * @package TSO_Image_Master
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOIMMA_NONCE_AJAX' ) ) {
	define( 'TSOIMMA_NONCE_AJAX', 'tsoimma_image_master_ajax' );
}
if ( ! defined( 'TSOIMMA_NONCE_AJAX_LEGACY' ) ) {
	define( 'TSOIMMA_NONCE_AJAX_LEGACY', 'tso_im_nonce' );
}

/**
 * Attachment meta: symbolic id => array( canonical, legacy ).
 *
 * @return array<string, array{0: string, 1: string}>
 */
function tsoimma_attachment_meta_key_map() {
	return array(
		'backup_file'            => array( '_tsoimma_backup_file', '_tso_im_backup_file' ),
		'backup_mime'            => array( '_tsoimma_backup_mime', '_tso_im_backup_mime' ),
		'backup_size'            => array( '_tsoimma_backup_size', '_tso_im_backup_size' ),
		'backup_attached_file'   => array( '_tsoimma_backup_attached_file', '_tso_im_backup_attached_file' ),
		'backup_current_name'    => array( '_tsoimma_backup_current_name', '_tso_im_backup_current_name' ),
		'auto_optimized'         => array( '_tsoimma_auto_optimized', '_tso_im_auto_optimized' ),
		'pdf_compressed'         => array( '_tsoimma_pdf_compressed', '_tso_im_pdf_compressed' ),
		'pdf_non_compressible'   => array( '_tsoimma_pdf_non_compressible', '_tso_im_pdf_non_compressible' ),
		'pdf_bg_temp'            => array( '_tsoimma_pdf_bg_temp', '_tso_im_pdf_bg_temp' ),
		'pdf_bg_original'        => array( '_tsoimma_pdf_bg_original', '_tso_im_pdf_bg_original' ),
		'pdf_bg_size'            => array( '_tsoimma_pdf_bg_size', '_tso_im_pdf_bg_size' ),
		'pdf_bg_quality'         => array( '_tsoimma_pdf_bg_quality', '_tso_im_pdf_bg_quality' ),
		'pdf_bg_settings'        => array( '_tsoimma_pdf_bg_settings', '_tso_im_pdf_bg_settings' ),
		'pdf_bg_started'         => array( '_tsoimma_pdf_bg_started', '_tso_im_pdf_bg_started' ),
		'pdf_bg_fallback_tried'  => array( '_tsoimma_pdf_bg_fallback_tried', '_tso_im_pdf_bg_fallback_tried' ),
		'pdf_bg_prev_size'       => array( '_tsoimma_pdf_bg_prev_size', '_tso_im_pdf_bg_prev_size' ),
		'pdf_status'             => array( '_tsoimma_pdf_status', '_tso_im_pdf_status' ),
	);
}

/**
 * Resolve canonical meta key for a symbolic id.
 *
 * @param string $symbol Symbolic meta id.
 * @return string
 */
function tsoimma_get_attachment_meta_key( $symbol ) {
	$map = tsoimma_attachment_meta_key_map();
	return isset( $map[ $symbol ][0] ) ? $map[ $symbol ][0] : '';
}

/**
 * @param string $symbol Symbolic meta id.
 * @return string
 */
function tsoimma_get_attachment_meta_key_legacy( $symbol ) {
	$map = tsoimma_attachment_meta_key_map();
	return isset( $map[ $symbol ][1] ) ? $map[ $symbol ][1] : '';
}

/**
 * @param int    $attachment_id Attachment ID.
 * @param string $symbol        Symbolic meta id.
 * @param bool   $single        Single value.
 * @return mixed
 */
function tsoimma_get_attachment_meta( $attachment_id, $symbol, $single = true ) {
	$attachment_id = absint( $attachment_id );
	$key         = tsoimma_get_attachment_meta_key( $symbol );
	$legacy_key  = tsoimma_get_attachment_meta_key_legacy( $symbol );
	if ( '' === $key ) {
		return $single ? '' : array();
	}

	$value = get_post_meta( $attachment_id, $key, $single );
	if ( ( $single && ( '' === $value || null === $value ) ) || ( ! $single && empty( $value ) ) ) {
		if ( '' !== $legacy_key ) {
			$value = get_post_meta( $attachment_id, $legacy_key, $single );
		}
	}

	return $value;
}

/**
 * @param int    $attachment_id Attachment ID.
 * @param string $symbol        Symbolic meta id.
 * @param mixed  $value         Meta value.
 * @return bool|int
 */
function tsoimma_update_attachment_meta( $attachment_id, $symbol, $value ) {
	$attachment_id = absint( $attachment_id );
	$key         = tsoimma_get_attachment_meta_key( $symbol );
	$legacy_key  = tsoimma_get_attachment_meta_key_legacy( $symbol );
	if ( '' === $key ) {
		return false;
	}

	$result = update_post_meta( $attachment_id, $key, $value );
	if ( '' !== $legacy_key ) {
		delete_post_meta( $attachment_id, $legacy_key );
	}

	return $result;
}

/**
 * @param int    $attachment_id Attachment ID.
 * @param string $symbol        Symbolic meta id.
 * @return bool
 */
function tsoimma_delete_attachment_meta( $attachment_id, $symbol ) {
	$attachment_id = absint( $attachment_id );
	$key         = tsoimma_get_attachment_meta_key( $symbol );
	$legacy_key  = tsoimma_get_attachment_meta_key_legacy( $symbol );
	$ok          = true;
	if ( '' !== $key ) {
		$ok = delete_post_meta( $attachment_id, $key ) || $ok;
	}
	if ( '' !== $legacy_key ) {
		$ok = delete_post_meta( $attachment_id, $legacy_key ) || $ok;
	}
	return (bool) $ok;
}

/**
 * Verify AJAX nonce (canonical, then legacy).
 *
 * @return void
 */
function tsoimma_verify_ajax_nonce() {
	if ( check_ajax_referer( TSOIMMA_NONCE_AJAX, 'nonce', false ) ) {
		return;
	}
	check_ajax_referer( TSOIMMA_NONCE_AJAX_LEGACY, 'nonce' );
}

/**
 * Raw POST value after nonce verification in the caller.
 *
 * @param string $key     POST key.
 * @param mixed  $default Default when missing.
 * @return mixed
 */
function tsoimma_get_ajax_post_raw( $key, $default = '' ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller must call tsoimma_verify_ajax_nonce() first.
	if ( ! isset( $_POST[ $key ] ) ) {
		return $default;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed for sanitize helpers.
	return wp_unslash( $_POST[ $key ] );
}

/**
 * @param string $key     POST key.
 * @param int    $default Default.
 * @return int
 */
function tsoimma_get_ajax_post_int( $key, $default = 0 ) {
	return absint( tsoimma_get_ajax_post_raw( $key, $default ) );
}

/**
 * @param string $key     POST key.
 * @param string $default Default.
 * @return string
 */
function tsoimma_get_ajax_post_text( $key, $default = '' ) {
	return sanitize_text_field( (string) tsoimma_get_ajax_post_raw( $key, $default ) );
}

/**
 * @param string $key     POST key.
 * @param string $default Default.
 * @return string
 */
function tsoimma_get_ajax_post_key( $key, $default = '' ) {
	return sanitize_key( (string) tsoimma_get_ajax_post_raw( $key, $default ) );
}

/**
 * @param string $key POST key.
 * @return bool
 */
function tsoimma_get_ajax_post_bool( $key ) {
	return ! empty( tsoimma_get_ajax_post_raw( $key ) );
}

/**
 * @param string $key POST key.
 * @return array
 */
function tsoimma_get_ajax_post_array( $key ) {
	$raw = tsoimma_get_ajax_post_raw( $key, array() );
	return is_array( $raw ) ? $raw : array();
}

/**
 * @param string $key POST key.
 * @return int[]
 */
function tsoimma_get_ajax_post_int_array( $key ) {
	return array_values( array_filter( array_map( 'absint', tsoimma_get_ajax_post_array( $key ) ) ) );
}

/**
 * @param string $key POST key.
 * @return string[]
 */
function tsoimma_get_ajax_post_text_array( $key ) {
	$out = array();
	foreach ( tsoimma_get_ajax_post_array( $key ) as $item ) {
		$item = sanitize_text_field( (string) $item );
		if ( '' !== $item ) {
			$out[] = $item;
		}
	}
	return $out;
}

/**
 * Whether a POST key is present (after nonce verification in caller).
 *
 * @param string $key POST key.
 * @return bool
 */
function tsoimma_ajax_post_has( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies nonce first.
	return isset( $_POST[ $key ] );
}

/**
 * Registered AJAX action names (canonical tsoimma_*).
 *
 * @return string[]
 */
function tsoimma_get_ajax_action_names() {
	return array(
		'tsoimma_optimize_image',
		'tsoimma_optimize_thumbnails',
		'tsoimma_optimize_bulk',
		'tsoimma_find_orphans',
		'tsoimma_delete_images',
		'tsoimma_rename_image',
		'tsoimma_update_seo',
		'tsoimma_get_images',
		'tsoimma_get_image_info',
		'tsoimma_get_history',
		'tsoimma_clear_history',
		'tsoimma_get_history_retention',
		'tsoimma_save_history_retention',
		'tsoimma_get_history_stats',
		'tsoimma_save_auto_settings',
		'tsoimma_get_auto_settings',
		'tsoimma_compress_pdf',
		'tsoimma_pdf_status',
		'tsoimma_mark_pdf_non_compressible',
		'tsoimma_get_pdfs',
		'tsoimma_revert_image',
		'tsoimma_delete_backup',
		'tsoimma_scan_rogue_files',
		'tsoimma_delete_rogue_files',
		'tsoimma_scan_url_issues',
		'tsoimma_fix_orphan_meta',
		'tsoimma_fix_mime_mismatch',
		'tsoimma_fix_url_issues',
		'tsoimma_remove_url_issues',
		'tsoimma_find_ghost_attachments',
		'tsoimma_delete_ghost_attachments',
		'tsoimma_get_dashboard_overview',
		'tsoimma_get_missing_alt',
		'tsoimma_bulk_fill_alt',
		'tsoimma_enqueue_optimize_queue',
		'tsoimma_get_queue_status',
		'tsoimma_cancel_queue',
		'tsoimma_get_backup_retention',
		'tsoimma_save_backup_retention',
		'tsoimma_purge_backups_now',
		'tsoimma_scan_duplicates',
	);
}

/**
 * Legacy AJAX action name for a canonical action.
 *
 * @param string $canonical Canonical action.
 * @return string
 */
function tsoimma_get_ajax_action_legacy( $canonical ) {
	return str_replace( 'tsoimma_', 'tso_im_', $canonical );
}
