<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_Ajax_Handler {

    /** @var int Max attachments processed per synchronous bulk optimize request. */
    private const BULK_OPTIMIZE_MAX = 25;

    /** @var int Max attachments enqueued per optimize-queue request. */
    private const QUEUE_ENQUEUE_MAX = 100;

    public static function init() {
        foreach ( tsoimma_get_ajax_action_names() as $action ) {
            $legacy  = tsoimma_get_ajax_action_legacy( $action );
            $handler = array( __CLASS__, 'handle_' . $legacy );
            add_action( 'wp_ajax_' . $action, $handler );
            if ( $legacy !== $action ) {
                add_action( 'wp_ajax_' . $legacy, $handler );
            }
        }
    }

    // ----------------------------------------------------------------
    // Optimitzar una imatge (retorna RAPIDAMENT — thumbnails per crida separada)
    // ----------------------------------------------------------------
    public static function handle_tso_im_optimize_image() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // Capturar errors fatals PHP i retornar-los com a JSON llegible
        // (sense tocar els buffers de WordPress — ob_get_level() > 0 mata WP)
        register_shutdown_function( function() {
            $e = error_get_last();
            if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
                if ( ! headers_sent() ) {
                    header( 'Content-Type: application/json; charset=utf-8', true, 200 );
                }
                echo wp_json_encode( array(
                    'success' => false,
                    'data'    => 'PHP Fatal [' . $e['type'] . ']: ' . $e['message']
                               . ' a ' . basename( $e['file'] ) . ':' . $e['line'],
                ) );
                exit;
            }
        } );

        $id = self::require_attachment_id( tsoimma_get_ajax_post_int( 'attachment_id' ) );
        $format = tsoimma_get_ajax_post_key( 'format', 'webp' );
        $quality = tsoimma_get_ajax_post_int( 'quality', 82 );
        $replace = tsoimma_get_ajax_post_bool( 'replace' );
        $max_width = tsoimma_get_ajax_post_int( 'max_width' );
        $max_height = tsoimma_get_ajax_post_int( 'max_height' );

        // FASE 1: conversió GD (sense cap operació DB de WordPress)
        try {
            $result = TSOIMMA_Optimizer::optimize( $id, $format, $quality, $replace, $max_width, $max_height );
        } catch ( \Throwable $ex ) {
            wp_send_json_error(
                sprintf(
                    /* translators: %s: exception message */
                    __( 'Phase 1 error: %s', 'tso-image-master' ),
                    $ex->getMessage()
                ) . ' (' . basename( $ex->getFile() ) . ':' . $ex->getLine() . ')'
            );
            return;
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ) );
            return;
        }

        if ( $replace && ! empty( $result['replaced'] ) ) {
            // FASE 2: actualitzar metadata WP
            try {
                TSOIMMA_Optimizer::update_wp_metadata_only( $id, $result, $format );
            } catch ( \Throwable $ex ) {
                TSOIMMA_Optimizer::rollback_optimize_files( $result );
                wp_send_json_error(
                    sprintf(
                        /* translators: 1: exception message, 2: file name, 3: line number */
                        __( 'Phase 2 error: %1$s at %2$s:%3$s (files restored from backup)', 'tso-image-master' ),
                        $ex->getMessage(),
                        basename( $ex->getFile() ),
                        $ex->getLine()
                    )
                );
                return;
            }

            // FASE 3: regenerate/optimize thumbnails in a follow-up AJAX call so the
            // first response returns quickly (avoids a frozen modal on format changes).
            $result['thumbnails_pending'] = true;

            $backup_status = TSOIMMA_Optimizer::get_backup_status( $id, false );
            if ( ! empty( $backup_status['has_backup'] ) ) {
                $result['has_backup']  = true;
                $result['backup_size'] = (int) $backup_status['backup_bytes'];
            }
        }

        // Log historial
        $log_file = isset( $result['new_path'] ) ? $result['new_path'] : get_attached_file( $id );
        try {
            TSOIMMA_History::log( $id, 'optimize', array(
                'filename'      => $log_file ? basename( $log_file ) : '',
                'format'        => $format,
                'quality'       => $quality,
                'max_width'     => $max_width,
                'max_height'    => $max_height,
                'resized'       => isset( $result['resized'] ) ? $result['resized'] : false,
                'original_size' => isset( $result['original_size'] ) ? $result['original_size'] : 0,
                'new_size'      => isset( $result['new_size'] ) ? $result['new_size'] : 0,
                'savings_bytes' => isset( $result['savings_bytes'] ) ? $result['savings_bytes'] : 0,
                'savings_pct'   => isset( $result['savings_pct'] ) ? $result['savings_pct'] : 0,
                'replaced'      => $replace,
            ) );
        } catch ( \Throwable $ex ) {
        }

        $result['thumbnails_done']    = ! empty( $result['thumbnails_done'] );
        $result['thumbnails_pending'] = ! empty( $result['thumbnails_pending'] );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Callback del WP-Cron: thumbnails en background
    // (la metadata WP ja ha estat actualitzada per update_wp_metadata_only)
    // ----------------------------------------------------------------
    public static function process_thumbnails_cron( $attachment_id, $format, $quality ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 ) {
            return;
        }

        $post = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return;
        }

        $format = sanitize_key( (string) $format );
        if ( ! in_array( $format, array( 'webp', 'jpg', 'jpeg', 'avif', 'png', 'original' ), true ) ) {
            return;
        }

        $quality = absint( $quality );
        if ( $quality < 50 || $quality > 100 ) {
            $quality = 82;
        }

        TSOIMMA_Optimizer::process_thumbnails_background( $attachment_id, $format, $quality );
    }

    // ----------------------------------------------------------------
    // Optimitzar thumbnails (endpoint AJAX directe de fallback)
    // ----------------------------------------------------------------
    public static function handle_tso_im_optimize_thumbnails() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit( 300 );
        ignore_user_abort( true );

        $id = self::require_attachment_id( tsoimma_get_ajax_post_int( 'attachment_id' ) );
        $format = tsoimma_get_ajax_post_key( 'format', 'webp' );
        $quality = tsoimma_get_ajax_post_int( 'quality', 82 );

        try {
            TSOIMMA_Optimizer::run_optimize_thumbnails_phase( $id, $format, $quality );
        } catch ( \Throwable $ex ) {
            wp_send_json_error(
                sprintf(
                    /* translators: %s: exception message */
                    __( 'Thumbnail error: %s', 'tso-image-master' ),
                    $ex->getMessage()
                )
            );
        }

        clean_post_cache( $id );
        clean_attachment_cache( $id );

        $backup_status = TSOIMMA_Optimizer::get_backup_status( $id, false );
        wp_send_json_success(
            array(
                'done'         => true,
                'has_backup'   => ! empty( $backup_status['has_backup'] ),
                'backup_size'  => ! empty( $backup_status['backup_size'] ) ? $backup_status['backup_size'] : '',
                'backup_bytes' => ! empty( $backup_status['backup_bytes'] ) ? (int) $backup_status['backup_bytes'] : 0,
            )
        );
    }

    // ----------------------------------------------------------------
    // Optimitzar en massa
    // ----------------------------------------------------------------
    public static function handle_tso_im_optimize_bulk() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $ids = array_slice(
            array_values( array_unique( array_map( 'absint', tsoimma_get_ajax_post_int_array( 'ids' ) ) ) ),
            0,
            self::BULK_OPTIMIZE_MAX
        );
        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'Select at least one image.', 'tso-image-master' ) );
        }

        $format = tsoimma_get_ajax_post_key( 'format', 'webp' );
        $quality = tsoimma_get_ajax_post_int( 'quality', 82 );

        $results = array();
        foreach ( $ids as $id ) {
            $res = TSOIMMA_Optimizer::run_optimize_pipeline( $id, $format, $quality, true, true );
            if ( is_wp_error( $res ) ) {
                $results[] = array( 'id' => $id, 'error' => $res->get_error_message() );
                continue;
            }
            if ( ! empty( $res['thumbnails_pending'] ) ) {
                try {
                    TSOIMMA_Optimizer::run_optimize_thumbnails_phase( $id, $format, $quality, $res );
                } catch ( \Throwable $ex ) {
                    $results[] = array( 'id' => $id, 'error' => 'Thumbnails: ' . $ex->getMessage() );
                    continue;
                }
            }
            $results[] = $res;
        }
        wp_send_json_success( $results );
    }

    // ----------------------------------------------------------------
    // Trobar imatges orfenes
    // ----------------------------------------------------------------
    public static function handle_tso_im_find_orphans() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $limit = tsoimma_get_ajax_post_int( 'limit', 200 );
        $offset = tsoimma_get_ajax_post_int( 'offset' );

        $result = TSOIMMA_Orphan_Finder::find( $limit, $offset );
        $result['total_images'] = TSOIMMA_Orphan_Finder::count_total();
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Eliminar imatges
    // ----------------------------------------------------------------
    public static function handle_tso_im_delete_images() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $ids = tsoimma_get_ajax_post_int_array( 'ids' );
        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'No attachment IDs were specified.', 'tso-image-master' ) );
        }
        $result = TSOIMMA_Image_Manager::delete( $ids );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Reanomenar imatge
    // ----------------------------------------------------------------
    public static function handle_tso_im_rename_image() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $id = tsoimma_get_ajax_post_int( 'attachment_id' );
        // Preservem exactament el text introduït (accents/ç inclosos).
        // La validació de caràcters invàlids es fa a Image_Manager::normalize_rename_name().
        $new_name   = trim( tsoimma_get_ajax_post_text( 'new_name' ) );
        $strict_seo = tsoimma_ajax_post_has( 'strict_seo' ) ? (bool) tsoimma_get_ajax_post_int( 'strict_seo' ) : null;
        try {
            $rename_args = array();
            if ( null !== $strict_seo ) {
                $rename_args['strict_seo'] = $strict_seo;
            }
            $result = TSOIMMA_Image_Manager::rename( $id, $new_name, $rename_args );
        } catch ( \Throwable $ex ) {
            wp_send_json_error(
                sprintf(
                    /* translators: %s: exception message */
                    __( 'Rename failed: %s', 'tso-image-master' ),
                    $ex->getMessage()
                )
            );
            return;
        }
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        TSOIMMA_History::log( $id, 'rename', array(
            'filename'     => $result['new_filename'],
            'old_filename' => $result['old_filename'],
            'new_filename' => $result['new_filename'],
        ) );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Actualitzar camps SEO
    // ----------------------------------------------------------------
    public static function handle_tso_im_update_seo() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $id          = self::require_attachment_id( tsoimma_get_ajax_post_int( 'attachment_id' ) );
        $title       = tsoimma_ajax_post_has( 'title' ) ? tsoimma_get_ajax_post_text( 'title' ) : null;
        $alt         = tsoimma_ajax_post_has( 'alt' ) ? tsoimma_get_ajax_post_text( 'alt' ) : null;
        $description = tsoimma_ajax_post_has( 'description' ) ? wp_kses_post( (string) tsoimma_get_ajax_post_raw( 'description' ) ) : null;
        $caption     = tsoimma_ajax_post_has( 'caption' ) ? tsoimma_get_ajax_post_text( 'caption' ) : null;

        $result = TSOIMMA_Image_Manager::update_seo_fields( $id, $title, $alt, $description, $caption );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $seo_details = array( 'source' => 'manual' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $context = tsoimma_get_ajax_post_key( 'context' );
        if ( 'dashboard_alt' === $context ) {
            $seo_details['source'] = 'dashboard_inline_alt';
        }
        if ( null !== $title && '' !== $title ) {
            $seo_details['seo_title'] = $title;
        }
        if ( null !== $alt && '' !== $alt ) {
            $seo_details['seo_alt'] = $alt;
        }
        if ( null !== $caption && '' !== $caption ) {
            $seo_details['seo_caption'] = $caption;
        }
        if ( null !== $description ) {
            $desc_plain = wp_strip_all_tags( $description );
            if ( '' !== $desc_plain ) {
                $seo_details['seo_description'] = $desc_plain;
            }
        }
        TSOIMMA_History::log( $id, 'seo_update', $seo_details );
        if ( 'dashboard_alt' === $context ) {
            TSOIMMA_Dashboard::flush_fillable_alt_cache();
        }

        $response = is_array( $result ) ? $result : array();
        if ( 'dashboard_alt' === $context ) {
            $response['missing_alt'] = TSOIMMA_Dashboard::count_missing_alt();
        }
        wp_send_json_success( $response );
    }

    // ----------------------------------------------------------------
    // Historial
    // ----------------------------------------------------------------
    public static function handle_tso_im_get_history() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        wp_send_json_success( TSOIMMA_History::get_entries( array(
            'page' => tsoimma_get_ajax_post_int( 'page', 1 ),
            'per_page' => tsoimma_get_ajax_post_int( 'per_page', 50 ),
            'attachment_id' => tsoimma_get_ajax_post_int( 'attachment_id' ),
            'action_type' => tsoimma_get_ajax_post_key( 'action_type' ),
            'search' => tsoimma_get_ajax_post_text( 'search' ),
            'date_from' => tsoimma_get_ajax_post_text( 'date_from' ),
            'date_to' => tsoimma_get_ajax_post_text( 'date_to' ),
        ) ) );
    }

    public static function handle_tso_im_get_history_stats() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        wp_send_json_success( TSOIMMA_History::get_stats() );
    }

    public static function handle_tso_im_clear_history() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        $days = tsoimma_get_ajax_post_int( 'days' );
        $type = tsoimma_get_ajax_post_key( 'type' ); // opcional: filtrar per tipus
        TSOIMMA_History::clear( $days, $type );
        wp_send_json_success( array( 'cleared' => true ) );
    }

    // ----------------------------------------------------------------
    // Auto-optimitzacio
    // ----------------------------------------------------------------
    public static function handle_tso_im_save_auto_settings() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $settings = TSOIMMA_Auto_Optimizer::save_settings( array(
            'enabled' => tsoimma_get_ajax_post_bool( 'enabled' ),
            'format'  => tsoimma_get_ajax_post_key( 'format', 'webp' ),
            'quality' => tsoimma_get_ajax_post_int( 'quality', 82 ),
            'source_formats' => tsoimma_get_ajax_post_array( 'source_formats' ),
            'fill_alt_on_upload' => tsoimma_get_ajax_post_bool( 'fill_alt_on_upload' ),
            'skip_small_kb'      => tsoimma_get_ajax_post_int( 'skip_small_kb' ),
        ) );
        wp_send_json_success( $settings );
    }

    public static function handle_tso_im_get_auto_settings() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        wp_send_json_success( TSOIMMA_Auto_Optimizer::get_settings() );
    }

    // ----------------------------------------------------------------
    // PDFs
    // ----------------------------------------------------------------
    public static function handle_tso_im_get_pdfs() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        wp_send_json_success( TSOIMMA_PDF_Compressor::get_pdfs(
            tsoimma_get_ajax_post_int( 'page', 1 ),
            tsoimma_get_ajax_post_int( 'per_page', 30 ),
            tsoimma_get_ajax_post_text( 'search' )
        ) );
    }

    public static function handle_tso_im_compress_pdf() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $id = tsoimma_get_ajax_post_int( 'attachment_id' );
        $quality = tsoimma_get_ajax_post_int( 'quality', 96 );

        if ( ! $id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'tso-image-master' ) );
            return;
        }

        // Llançar GhostScript en background (exec ... &)
        // PHP retorna immediatament, sense esperar GhostScript.
        // El JS fa polling a imp_pdf_status comprovant si el fitxer temp existeix.
        $result = TSOIMMA_PDF_Compressor::compress_background( $id, $quality );

        if ( is_wp_error( $result ) ) {
            TSOIMMA_PDF_Compressor::mark_non_compressible( $id, $result->get_error_code(), $result->get_error_message() );
            wp_send_json_error( $result->get_error_message() );
            return;
        }

        wp_send_json_success( array( 'status' => 'processing' ) );
    }

    // ----------------------------------------------------------------
    // Polling: comprova si GhostScript ha acabat (fitxer temp existeix)
    // ----------------------------------------------------------------
    public static function handle_tso_im_pdf_status() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $id = tsoimma_get_ajax_post_int( 'attachment_id' );
        $replace = tsoimma_get_ajax_post_bool( 'replace' );

        $result = TSOIMMA_PDF_Compressor::poll_status( $id, $replace );

        // ── Errors d'aplicació (no_gain, corrupt, replace_failed) ────────
        // Enviem com a 'done' amb camp 'error' perquè el JS mostri el missatge
        // sense fer retry infinit. json_error causaria retry i acabaria en idle.
        if ( is_wp_error( $result ) ) {
            TSOIMMA_PDF_Compressor::mark_non_compressible( $id, $result->get_error_code(), $result->get_error_message() );
            wp_send_json_success( array(
                'status' => 'done',
                'error'  => $result->get_error_message(),
                'result' => array(),
            ) );
            return;
        }

        // ── Encara processant ────────────────────────────────────────────
        if ( isset( $result['status'] ) && 'processing' === $result['status'] ) {
            wp_send_json_success( array( 'status' => 'processing' ) );
            return;
        }

        // ── Idle: status no existeix (job acabat o mai iniciat) ───────────
        // Això passa quan poll_status() llegeix un status buit perquè les
        // meta keys ja han estat esborrades en un poll anterior.
        if ( isset( $result['status'] ) && 'idle' === $result['status'] ) {
            wp_send_json_success( array( 'status' => 'idle' ) );
            return;
        }

        // ── Resultat vàlid amb savings_pct ───────────────────────────────
        // Assegurem que savings_pct existeix i és numèric abans de respondre.
        if ( empty( $result['savings_pct'] ) && isset( $result['original_size'], $result['new_size'] )
            && $result['original_size'] > 0 ) {
            $result['savings_pct'] = round(
                ( 1 - $result['new_size'] / $result['original_size'] ) * 100, 1
            );
        }

        // ── Registrar historial ──────────────────────────────────────────
        if ( ! empty( $result['replaced'] ) ) {
            TSOIMMA_PDF_Compressor::clear_non_compressible( $id );
            tsoimma_update_attachment_meta( $id, 'pdf_compressed', time() );
            TSOIMMA_History::log( $id, 'pdf_compress', array(
                'filename'      => basename( get_attached_file( $id ) ),
                'quality' => tsoimma_get_ajax_post_int( 'quality', 96 ),
                'original_size' => $result['original_size'],
                'new_size'      => $result['new_size'],
                'savings_bytes' => $result['savings_bytes'],
                'savings_pct'   => $result['savings_pct'],
                'method'        => isset( $result['method'] ) ? $result['method'] : '',
            ) );
        }

        wp_send_json_success( array(
            'status' => 'done',
            'result' => $result,
        ) );
    }

    public static function handle_tso_im_mark_pdf_non_compressible() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $id = tsoimma_get_ajax_post_int( 'attachment_id' );
        $code = tsoimma_get_ajax_post_key( 'code', 'manual_mark' );
        $message = tsoimma_get_ajax_post_text( 'message', 'PDF marked as not compressible.' );

        if ( ! $id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'tso-image-master' ) );
            return;
        }

        TSOIMMA_PDF_Compressor::mark_non_compressible( $id, $code, $message );
        wp_send_json_success( array( 'marked' => true ) );
    }

    public static function handle_tso_im_get_history_retention() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        wp_send_json_success( array(
            'days'     => (int) get_option( 'tsoimma_history_retention_days', 90 ),
            'interval' => TSOIMMA_History::get_purge_interval(),
        ) );
    }

    public static function handle_tso_im_save_history_retention() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        $days = tsoimma_get_ajax_post_int( 'days', 90 );
        $interval = tsoimma_get_ajax_post_key( 'interval' );
        // Valors permesos: 0 (desactivat) o entre 1 i 3650 dies.
        if ( $days !== 0 && ( $days < 1 || $days > 3650 ) ) {
            wp_send_json_error( __( 'Invalid value. Use 0 to disable, or between 1 and 3650 days.', 'tso-image-master' ) );
            return;
        }
        if ( $interval !== '' && ! in_array( $interval, TSOIMMA_History::PURGE_INTERVALS, true ) ) {
            wp_send_json_error( __( 'Invalid check frequency.', 'tso-image-master' ) );
            return;
        }
        update_option( 'tsoimma_history_retention_days', $days );
        if ( $interval !== '' ) {
            update_option( 'tsoimma_history_purge_interval', $interval );
        }
        TSOIMMA_History::schedule_purge_cron( $interval !== '' ? $interval : null );
        wp_send_json_success( array(
            'days'     => $days,
            'interval' => TSOIMMA_History::get_purge_interval(),
        ) );
    }

    // ----------------------------------------------------------------
    // Llistar imatges
    // ----------------------------------------------------------------
    public static function handle_tso_im_get_images() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $page = tsoimma_get_ajax_post_int( 'page', 1 );
        $per_page = tsoimma_get_ajax_post_int( 'per_page', 35 );
        $allowed  = array( 21, 35, 49, 70, 105 );
        if ( ! in_array( $per_page, $allowed, true ) ) {
            $per_page = 35;
        }
        $search = tsoimma_get_ajax_post_text( 'search' );
        $sort = tsoimma_get_ajax_post_key( 'sort', 'date' );

        $result = TSOIMMA_Image_Manager::get_images_list( $page, $per_page, $search, $sort );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Info d'una imatge
    // ----------------------------------------------------------------
    public static function handle_tso_im_get_image_info() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $id = tsoimma_get_ajax_post_int( 'attachment_id' );
        $file     = get_attached_file( $id );
        $metadata = wp_get_attachment_metadata( $id );

        $real_mime = ( $file && file_exists( $file ) ) ? mime_content_type( $file ) : get_post_mime_type( $id );
        $ext_map   = array(
            'image/jpeg' => 'JPG', 'image/png'  => 'PNG',
            'image/gif'  => 'GIF', 'image/webp' => 'WEBP', 'image/svg+xml' => 'SVG',
        );
        $real_ext = isset( $ext_map[ $real_mime ] ) ? $ext_map[ $real_mime ] : strtoupper( pathinfo( $file ?? '', PATHINFO_EXTENSION ) );

        $backup = TSOIMMA_Optimizer::get_backup_status( $id );

        wp_send_json_success( array(
            'id'          => $id,
            'title'       => get_the_title( $id ),
            'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'caption'     => get_post_field( 'post_excerpt', $id ),
            'description' => get_post_field( 'post_content', $id ),
            'filename'    => $file ? basename( $file ) : '',
            'url'         => wp_get_attachment_url( $id ),
            'thumb'       => wp_get_attachment_image_url( $id, 'medium' ),
            'filesize'    => $file && file_exists( $file ) ? filesize( $file ) : 0,
            'filesize_h'  => $file && file_exists( $file ) ? size_format( filesize( $file ) ) : '—',
            'mime'        => $real_mime,
            'ext'         => $real_ext,
            'width'       => isset( $metadata['width'] )  ? $metadata['width']  : 0,
            'height'      => isset( $metadata['height'] ) ? $metadata['height'] : 0,
            'suggested'   => TSOIMMA_Image_Manager::suggest_filename( $id ),
            'is_orphan'   => TSOIMMA_Orphan_Finder::is_orphan( $id ),
            'used_in'     => TSOIMMA_Image_Manager::get_used_in_posts( $id ),
            'has_backup'  => ! empty( $backup['has_backup'] ),
            'backup_size' => ! empty( $backup['backup_size'] ) ? $backup['backup_size'] : '',
        ) );
    }

    // ----------------------------------------------------------------
    // Revertir imatge al backup original
    // ----------------------------------------------------------------
    public static function handle_tso_im_revert_image() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $id = tsoimma_get_ajax_post_int( 'attachment_id' );
        $result = TSOIMMA_Optimizer::revert( $id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        TSOIMMA_History::log( $id, 'revert', array(
            'restored_ext'  => $result['restored_ext'],
            'restored_size' => $result['restored_size'],
        ) );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Eliminar copia de seguretat
    // ----------------------------------------------------------------
    public static function handle_tso_im_delete_backup() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        $id = tsoimma_get_ajax_post_int( 'attachment_id' );
        $backup_path = tsoimma_get_attachment_meta( $id, 'backup_file' );

        if ( ! $backup_path ) {
            wp_send_json_error( __( 'No backup found to delete.', 'tso-image-master' ) );
        }

        $safe_backup = TSOIMMA_Optimizer::resolve_backup_path( $backup_path, false );
        if ( false === $safe_backup ) {
            TSOIMMA_Optimizer::clear_backup_meta( $id );
            wp_send_json_success( array( 'deleted' => true ) );
        }

        if ( file_exists( $safe_backup ) ) {
            wp_delete_file( $safe_backup );
        }
        TSOIMMA_Optimizer::prune_empty_backup_dirs( $safe_backup );
        TSOIMMA_Optimizer::clear_backup_meta( $id );

        wp_send_json_success( array( 'deleted' => true ) );
    }

    // ----------------------------------------------------------------
    // Escanejar fitxers fisics sense attachment (rogue files)
    // ----------------------------------------------------------------
    public static function handle_tso_im_scan_rogue_files() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        $result = TSOIMMA_Rogue_Scanner::scan();
        TSOIMMA_Rogue_Scanner::store_delete_allowlist_from_scan( $result );
        $allowlist = TSOIMMA_Rogue_Scanner::get_delete_allowlist_info();
        $result['delete_allowlist_expires'] = $allowlist['expires'];
        $result['delete_allowlist_hours']   = (int) ( TSOIMMA_Rogue_Scanner::DELETE_ALLOWLIST_TTL / HOUR_IN_SECONDS );
        wp_send_json_success( $result );
    }

    public static function handle_tso_im_delete_rogue_files() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // El JS envia path_b64: path absolut en base64 (evita corrupció d'encoding UTF-8/latin1)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- base64 no té slashes, unslash és innecessari però el fem per complir
        $raw_b64s = array_map( 'sanitize_text_field', tsoimma_get_ajax_post_array( 'paths_b64' ) );
        $deleted = 0;
        $errors  = array();
        $rescan_required = false;

        foreach ( $raw_b64s as $b64 ) {
            // Decodificar el path absolut original (preserva encoding original del filesystem)
            $abs_path = base64_decode( sanitize_text_field( $b64 ), true );
            if ( $abs_path === false ) {
                $errors[] = '(base64 invàlid)';
                continue;
            }

            // Seguretat: realpath() dins de uploads (evita ../ i enllaços simbòlics).
            $safe_path = self::resolve_uploads_file_path( $abs_path );
            if ( false === $safe_path ) {
                $errors[] = basename( $abs_path ) . ' (path invalid)';
                continue;
            }

            if ( ! TSOIMMA_Rogue_Scanner::is_resolved_path_in_delete_allowlist( $safe_path ) ) {
                $rescan_required = true;
                $errors[] = basename( $safe_path ) . ' (no al darrer escaneig; torna a escanejar)';
                continue;
            }

            wp_delete_file( $safe_path );
            if ( ! file_exists( $safe_path ) ) {
                $deleted++;
                TSOIMMA_Optimizer::prune_empty_backup_dirs( $safe_path );
            } else {
                $errors[] = basename( $safe_path ) . ' (no es pot eliminar)';
            }
        }
        wp_send_json_success(
            array(
                'deleted'         => $deleted,
                'errors'          => $errors,
                'rescan_required' => $rescan_required,
            )
        );
    }

    // ----------------------------------------------------------------
    // Detectar i reparar adjunts amb mime type incorrecte
    // (fitxer .webp però post_mime_type = image/jpeg, o 0x0px dimensions)
    // ----------------------------------------------------------------
    public static function handle_tso_im_fix_mime_mismatch() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit( 300 );

        global $wpdb;
        $fixed    = 0;
        $scanned  = 0;
        $errors   = array();
        $last_id  = PHP_INT_MAX;
        $batch    = 500;

        $upload_dir    = wp_upload_dir();
        $base_dir_norm = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $auto_optimizer_active = class_exists( 'TSOIMMA_Auto_Optimizer' )
            && has_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ) );

        if ( $auto_optimizer_active ) {
            remove_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ), 20 );
        }

        $tsoimma_like_image_mime = $wpdb->esc_like( 'image/' ) . '%';
        $tsoimma_like_webp_path  = '%' . $wpdb->esc_like( '.webp' );

        while ( true ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, p.post_mime_type, pm_file.meta_value AS attached_file, pm_meta.meta_value AS attachment_metadata
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = '_wp_attached_file'
                     LEFT JOIN {$wpdb->postmeta} pm_meta ON pm_meta.post_id = p.ID AND pm_meta.meta_key = '_wp_attachment_metadata'
                     WHERE p.post_type = 'attachment'
                     AND pm_file.meta_value != ''
                     AND (
                         p.post_mime_type LIKE %s
                         OR p.post_mime_type IN ('image/jpeg','image/png','image/gif','image/jpg','')
                         OR p.post_mime_type IS NULL
                         OR pm_file.meta_value LIKE %s
                     )
                     AND p.ID < %d
                     ORDER BY p.ID DESC
                     LIMIT %d",
                    $tsoimma_like_image_mime,
                    $tsoimma_like_webp_path,
                    $last_id,
                    $batch
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( (array) $rows as $row ) {
                $id = (int) $row->ID;
                if ( $id > 0 && $id < $last_id ) {
                    $last_id = $id;
                }
                ++$scanned;

                $attached_file = (string) $row->attached_file;
                $post_mime     = (string) $row->post_mime_type;
                $meta_raw      = (string) $row->attachment_metadata;
                $has_zero_dim  = ( '' !== $meta_raw && false !== strpos( $meta_raw, 's:5:"width";i:0' ) );

                $is_candidate = false;
                $needs_regen  = false;

                if ( preg_match( '/\.webp$/i', $attached_file ) && 'image/webp' !== $post_mime ) {
                    $is_candidate = true;
                } elseif ( $has_zero_dim && preg_match( '/^image\//', $post_mime ) ) {
                    $is_candidate = true;
                    $needs_regen  = true;
                } elseif (
                    ( in_array( $post_mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/jpg' ), true ) || '' === $post_mime )
                    && ! preg_match( '/\.webp$/i', $attached_file )
                ) {
                    $is_candidate = true;
                }

                if ( ! $is_candidate ) {
                    continue;
                }

                $rel_path     = ltrim( wp_normalize_path( $attached_file ), '/' );
                $abs_path     = $base_dir_norm . $rel_path;
                $path_changed = false;
                $mime_changed = false;

                if ( ! file_exists( $abs_path ) ) {
                    $pi        = pathinfo( $abs_path );
                    $webp_path = $pi['dirname'] . '/' . $pi['filename'] . '.webp';
                    if ( file_exists( $webp_path ) ) {
                        $abs_path     = $webp_path;
                        $rel_path     = ltrim( str_replace( $base_dir_norm, '', wp_normalize_path( $webp_path ) ), '/' );
                        update_post_meta( $id, '_wp_attached_file', $rel_path );
                        $path_changed = true;
                    } else {
                        $errors[] = 'ID ' . $id . ': fitxer no trobat (' . basename( $abs_path ) . ')';
                        continue;
                    }
                }

                $real_ext  = strtolower( pathinfo( $abs_path, PATHINFO_EXTENSION ) );
                $mime_map  = array(
                    'webp' => 'image/webp',
                    'avif' => 'image/avif',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                );
                $real_mime = isset( $mime_map[ $real_ext ] ) ? $mime_map[ $real_ext ] : mime_content_type( $abs_path );

                if ( $post_mime !== $real_mime ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update(
                        $wpdb->posts,
                        array( 'post_mime_type' => $real_mime ),
                        array( 'ID' => $id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    $mime_changed = true;
                }

                $needs_regen = $path_changed || $needs_regen;

                if ( $needs_regen ) {
                    $new_meta = wp_generate_attachment_metadata( $id, $abs_path );
                    if ( $new_meta && ! is_wp_error( $new_meta ) ) {
                        wp_update_attachment_metadata( $id, $new_meta );
                    }
                    ++$fixed;
                } elseif ( $mime_changed ) {
                    $meta = wp_get_attachment_metadata( $id );
                    if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
                        foreach ( $meta['sizes'] as $sz => $sz_data ) {
                            $meta['sizes'][ $sz ]['mime-type'] = $real_mime;
                        }
                        wp_update_attachment_metadata( $id, $meta );
                    }
                    ++$fixed;
                }

                clean_attachment_cache( $id );
            }

            if ( count( $rows ) < $batch ) {
                break;
            }
        }

        if ( $auto_optimizer_active ) {
            add_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ), 20, 2 );
        }

        wp_send_json_success(
            array(
                'fixed'   => $fixed,
                'scanned' => $scanned,
                'errors'  => $errors,
                'message' => $fixed > 0
                    ? $fixed . ' adjunts reparats (de ' . $scanned . ' escanejats).'
                    : 'Cap discrepancia trobada. Els ' . $scanned . ' adjunts escanejats ja estan correctes.',
            )
        );
    }

    // ----------------------------------------------------------------
    // Escanejar i corregir URLs inconsistents al contingut
    // ----------------------------------------------------------------
    public static function handle_tso_im_scan_url_issues() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit( 120 );
        $result = TSOIMMA_URL_Fixer::scan();
        wp_send_json_success( $result );
    }

    public static function handle_tso_im_fix_url_issues() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per camp dins el foreach
        $raw_fixes = tsoimma_get_ajax_post_array( 'fixes' );
        if ( empty( $raw_fixes ) ) {
            wp_send_json_error( __( 'No URL fixes were submitted.', 'tso-image-master' ) );
        }

        $clean_fixes = array();
        foreach ( $raw_fixes as $fix ) {
            if ( ! empty( $fix['old_url'] ) && ! empty( $fix['new_url'] ) ) {
                $clean_fixes[] = array(
                    'old_url' => esc_url_raw( $fix['old_url'] ),
                    'new_url' => esc_url_raw( $fix['new_url'] ),
                );
            }
        }
        $result = TSOIMMA_URL_Fixer::fix( $clean_fixes );
        wp_send_json_success( $result );
    }

    public static function handle_tso_im_remove_url_issues() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per URL below
        $raw_urls = tsoimma_get_ajax_post_array( 'urls' );
        if ( empty( $raw_urls ) ) {
            wp_send_json_error( __( 'No URLs were submitted for removal.', 'tso-image-master' ) );
        }

        $clean_urls = array();
        foreach ( $raw_urls as $url ) {
            $url = esc_url_raw( (string) $url );
            if ( $url ) {
                $clean_urls[] = $url;
            }
        }

        if ( empty( $clean_urls ) ) {
            wp_send_json_error( __( 'No valid URLs were submitted.', 'tso-image-master' ) );
        }

        $result = TSOIMMA_URL_Fixer::remove_urls( $clean_urls );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Helpers privats
    // ----------------------------------------------------------------
    private static function require_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'tso-image-master' ), 403 );
            wp_die();
        }
    }

    /**
     * Validate attachment ID or send JSON error.
     *
     * @param int $attachment_id Raw attachment ID.
     * @return int
     */
    private static function require_attachment_id( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'tso-image-master' ) );
        }

        return $attachment_id;
    }

    /**
     * Verify an absolute path resolves to a regular file inside wp-content/uploads.
     *
     * @param string $abs_path Candidate absolute path.
     * @return string|false Resolved real path, or false when not allowed.
     */
    private static function resolve_uploads_file_path( $abs_path ) {
        $norm = TSOIMMA_Rogue_Scanner::normalize_uploads_file_path( $abs_path );
        return '' !== $norm ? $norm : false;
    }

    /**
     * Detect whether a filesystem path contains readable image content.
     *
     * @param string $abs_path Absolute file path.
     * @return bool
     */
    private static function path_contains_image_content( $abs_path ) {
        $abs_path = (string) $abs_path;
        if ( '' === $abs_path || ! is_file( $abs_path ) ) {
            return false;
        }

        if ( filesize( $abs_path ) <= 0 ) {
            return false;
        }

        $checked = wp_check_filetype( basename( $abs_path ), null );
        if ( ! empty( $checked['type'] ) && 0 === strpos( $checked['type'], 'image/' ) ) {
            return true;
        }

        if ( function_exists( 'mime_content_type' ) ) {
            $mime = mime_content_type( $abs_path );
            if ( is_string( $mime ) && 0 === strpos( $mime, 'image/' ) ) {
                return true;
            }
        }

        if ( function_exists( 'getimagesize' ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
            $info = @getimagesize( $abs_path );
            return is_array( $info ) && ! empty( $info[0] );
        }

        return false;
    }

    /**
     * @deprecated Mantingut per compatibilitat — usar check_ajax_referer() directament.
     */
    private static function check_nonce() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
    }
    // ----------------------------------------------------------------
    // Reparar meta de fitxers auto-optimitzats sense update_wp_metadata
    // ----------------------------------------------------------------
    public static function handle_tso_im_fix_orphan_meta() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        global $wpdb;
        $fixed   = 0;
        $scanned = 0;
        $last_id = PHP_INT_MAX;
        $batch   = 500;

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $upload_dir    = wp_upload_dir();
        $base_dir_norm = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );

        while ( true ) {
            // Cercar attachments on el path DB no existeix físicament (per lots).
            $rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = '_wp_attached_file' AND post_id < %d
                     ORDER BY post_id DESC
                     LIMIT %d",
                    $last_id,
                    $batch
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( (array) $rows as $row ) {
                $post_id = (int) $row->post_id;
                if ( $post_id > 0 && $post_id < $last_id ) {
                    $last_id = $post_id;
                }
                ++$scanned;

                $old_rel  = ltrim( wp_normalize_path( (string) $row->meta_value ), '/' );
                $old_path = $base_dir_norm . $old_rel;

                if ( file_exists( $old_path ) ) {
                    continue;
                }

                $pi       = pathinfo( $old_path );
                $webp     = $pi['dirname'] . '/' . $pi['filename'] . '.webp';
                $avif     = $pi['dirname'] . '/' . $pi['filename'] . '.avif';

                $replacement = '';
                $new_mime    = '';
                if ( file_exists( $webp ) ) {
                    $replacement = $webp;
                    $new_mime    = 'image/webp';
                } elseif ( file_exists( $avif ) ) {
                    $replacement = $avif;
                    $new_mime    = 'image/avif';
                }

                if ( '' === $replacement ) {
                    continue;
                }

                $new_rel = ltrim( str_replace( $base_dir_norm, '', wp_normalize_path( $replacement ) ), '/' );
                update_post_meta( $post_id, '_wp_attached_file', $new_rel );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $wpdb->posts,
                    array( 'post_mime_type' => $new_mime ),
                    array( 'ID' => $post_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                $new_meta = wp_generate_attachment_metadata( $post_id, $replacement );
                if ( $new_meta && ! is_wp_error( $new_meta ) ) {
                    wp_update_attachment_metadata( $post_id, $new_meta );
                }

                $new_url = wp_get_attachment_url( $post_id );
                if ( $new_url ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update(
                        $wpdb->posts,
                        array( 'guid' => $new_url ),
                        array( 'ID' => $post_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                }

                clean_attachment_cache( $post_id );
                ++$fixed;
            }

            if ( count( $rows ) < $batch ) {
                break;
            }
        }

        wp_send_json_success(
            array(
                'fixed'   => $fixed,
                'scanned' => $scanned,
            )
        );
    }

    // ----------------------------------------------------------------
    // Trobar adjunts fantasma (registre BD existeix però fitxer físic NO)
    // SEGUR: mai crida wp_generate_attachment_metadata() ni toca fitxers
    // ----------------------------------------------------------------
    public static function handle_tso_im_find_ghost_attachments() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit( 300 );

        global $wpdb;
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );

        $ghosts  = array();
        $scanned = 0;
        $last_id = PHP_INT_MAX;
        $batch   = 500;

        $tsoimma_like_image_mime = $wpdb->esc_like( 'image/' ) . '%';

        while ( true ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, p.post_title, p.post_mime_type, pm.meta_value as attached_file
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
                     WHERE p.post_type = 'attachment'
                     AND ( p.post_mime_type LIKE %s
                           OR LOWER(p.post_mime_type) = 'application/x-empty'
                           OR p.post_mime_type = ''
                           OR p.post_mime_type IS NULL )
                     AND pm.meta_value != ''
                     AND p.ID < %d
                     ORDER BY p.ID DESC
                     LIMIT %d",
                    $tsoimma_like_image_mime,
                    $last_id,
                    $batch
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( (array) $rows as $row ) {
                $post_id = (int) $row->ID;
                if ( $post_id > 0 && $post_id < $last_id ) {
                    $last_id = $post_id;
                }
                ++$scanned;

                $abs_path = $base_dir . ltrim( $row->attached_file, '/' );
                $reason   = '';

                if ( ! file_exists( $abs_path ) ) {
                    $reason = 'Fitxer no existeix al disc';
                } elseif ( filesize( $abs_path ) === 0 ) {
                    $reason = 'Fitxer buit (0 bytes)';
                } elseif ( strtolower( $row->post_mime_type ) === 'application/x-empty'
                    && ! self::path_contains_image_content( $abs_path ) ) {
                    $reason = 'Mime type application/x-empty';
                }

                if ( $reason ) {
                    $size     = file_exists( $abs_path ) ? filesize( $abs_path ) : 0;
                    $ghosts[] = array(
                        'id'            => $post_id,
                        'title'         => $row->post_title,
                        'filename'      => basename( $row->attached_file ),
                        'mime'          => $row->post_mime_type,
                        'meta_path'     => $row->attached_file,
                        'reason'        => $reason,
                        'size'          => $size,
                        'file_exists'   => file_exists( $abs_path ),
                    );
                }
            }

            if ( count( $rows ) < $batch ) {
                break;
            }
        }

        wp_send_json_success(
            array(
                'ghosts'  => $ghosts,
                'total'   => count( $ghosts ),
                'scanned' => $scanned,
            )
        );
    }

    // ----------------------------------------------------------------
    // Eliminar adjunts fantasma:
    //   - Fitxer inexistent: elimina registre BD
    //   - Fitxer 0 bytes o application/x-empty: elimina fitxer físic + registre BD
    // NO crida wp_generate_attachment_metadata() → cap risc de bucles
    // ----------------------------------------------------------------
    public static function handle_tso_im_delete_ghost_attachments() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ids = tsoimma_get_ajax_post_int_array( 'ids' );
        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'No attachment IDs were specified.', 'tso-image-master' ) );
            return;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $deleted    = 0;
        $errors     = array();

        foreach ( $ids as $id ) {
            // Verificar que és un attachment (no un post normal)
            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                $errors[] = 'ID ' . $id . ': no es un attachment valid.';
                continue;
            }

            $attached_file = get_post_meta( $id, '_wp_attached_file', true );
            $abs_path      = $attached_file ? ( $base_dir . ltrim( $attached_file, '/' ) ) : '';
            $file_exists   = $abs_path && file_exists( $abs_path );
            $file_size     = $file_exists ? filesize( $abs_path ) : 0;
            $mime_db       = strtolower( $post->post_mime_type );
            $has_image     = $file_exists && $file_size > 0 && self::path_contains_image_content( $abs_path );

            // Rebutjar adjunts amb imatge real al disc (mime erroni o buit a la BD).
            if ( $has_image ) {
                $errors[] = 'ID ' . $id . ' (' . basename( $abs_path ) . '): fitxer real amb contingut, no eliminat per seguretat.';
                continue;
            }

            // Si el fitxer existeix però és buit o no és imatge amb mime x-empty: eliminar-lo físicament
            if ( $file_exists && ( $file_size === 0 || $mime_db === 'application/x-empty' ) ) {
                wp_delete_file( $abs_path );
            }

            // Eliminar el registre BD complet (post + totes les postmeta)
            // wp_delete_attachment( $id, true ) = force delete, salta la paperera
            $result = wp_delete_attachment( $id, true );
            if ( false !== $result ) {
                $deleted++;
            } else {
                $errors[] = 'ID ' . $id . ': wp_delete_attachment ha retornat false.';
            }
        }

        wp_send_json_success( array(
            'deleted' => $deleted,
            'errors'  => $errors,
            'message' => $deleted > 0
                ? $deleted . ' adjunts fantasma eliminats correctament.'
                : 'Cap adjunt eliminat.',
        ) );
    }

    // ----------------------------------------------------------------
    // Dashboard overview
    // ----------------------------------------------------------------
    public static function handle_tso_im_get_dashboard_overview() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        wp_send_json_success( TSOIMMA_Dashboard::get_overview() );
    }

    public static function handle_tso_im_get_missing_alt() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        wp_send_json_success(
            TSOIMMA_Dashboard::get_missing_alt_list(
                tsoimma_get_ajax_post_int( 'page', 1 ),
                tsoimma_get_ajax_post_int( 'per_page', 35 ),
                tsoimma_get_ajax_post_bool( 'used_only' )
            )
        );
    }

    public static function handle_tso_im_bulk_fill_alt() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ids = tsoimma_get_ajax_post_int_array( 'ids' );
        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'Select at least one image.', 'tso-image-master' ) );
        }

        $source = tsoimma_get_ajax_post_key( 'source', 'suggested' );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- map sanitized in helper.
        $raw_alts    = tsoimma_get_ajax_post_array( 'alts' );
        $custom_alts = TSOIMMA_Dashboard::sanitize_custom_alt_map( is_array( $raw_alts ) ? $raw_alts : array() );
        $recount = tsoimma_get_ajax_post_bool( 'recount' );
        $result      = TSOIMMA_Dashboard::bulk_fill_alt( $ids, $source, $custom_alts, $recount );
        wp_send_json_success( $result );
    }

    public static function handle_tso_im_enqueue_optimize_queue() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ids = array_slice(
            array_values( array_unique( array_map( 'absint', tsoimma_get_ajax_post_int_array( 'ids' ) ) ) ),
            0,
            self::QUEUE_ENQUEUE_MAX
        );
        $format = tsoimma_get_ajax_post_key( 'format', 'webp' );
        $quality = tsoimma_get_ajax_post_int( 'quality', 82 );
        $replace = tsoimma_get_ajax_post_bool( 'replace' );

        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'Select at least one image.', 'tso-image-master' ) );
        }

        wp_send_json_success(
            TSOIMMA_Queue::enqueue_optimize( $ids, $format, $quality, $replace )
        );
    }

    public static function handle_tso_im_get_queue_status() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        wp_send_json_success( TSOIMMA_Queue::get_status() );
    }

    public static function handle_tso_im_cancel_queue() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        TSOIMMA_Queue::cancel_pending();
        wp_send_json_success( TSOIMMA_Queue::get_status() );
    }

    public static function handle_tso_im_get_backup_retention() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        wp_send_json_success( TSOIMMA_Backup_Manager::get_settings() );
    }

    public static function handle_tso_im_save_backup_retention() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        wp_send_json_success(
            TSOIMMA_Backup_Manager::save_settings(
                array(
                    'days' => tsoimma_get_ajax_post_int( 'days' ),
                    'max_mb' => tsoimma_get_ajax_post_int( 'max_mb' ),
                )
            )
        );
    }

    public static function handle_tso_im_purge_backups_now() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();
        $result = TSOIMMA_Backup_Manager::purge_old_backups();
        TSOIMMA_Dashboard::flush_backup_stats_cache();
        wp_send_json_success( $result );
    }

    public static function handle_tso_im_scan_duplicates() {
        tsoimma_verify_ajax_nonce();
        self::require_admin();

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit( 120 );

        $after_id = tsoimma_get_ajax_post_int( 'after_id' );
        $batch = tsoimma_get_ajax_post_int( 'batch', TSOIMMA_Duplicate_Finder::SCAN_BATCH_SIZE );
        $reset = tsoimma_get_ajax_post_bool( 'reset' );

        if ( tsoimma_ajax_post_has( 'offset' ) && ! tsoimma_ajax_post_has( 'after_id' ) ) {
            $after_id = tsoimma_get_ajax_post_int( 'offset' );
        }

        // Batched scan (default for admin UI).
        if ( tsoimma_ajax_post_has( 'batch' ) || tsoimma_ajax_post_has( 'reset' ) || tsoimma_ajax_post_has( 'after_id' ) ) {
            wp_send_json_success( TSOIMMA_Duplicate_Finder::scan_batch( $after_id, $batch, $reset ) );
            return;
        }

        $limit = tsoimma_get_ajax_post_int( 'limit' );
        wp_send_json_success( TSOIMMA_Duplicate_Finder::scan( $limit ) );
    }


}
