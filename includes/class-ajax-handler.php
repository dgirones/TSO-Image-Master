<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_Ajax_Handler {

    public static function init() {
        $actions = array(
            'tso_im_optimize_image',
            'tso_im_optimize_thumbnails',
            'tso_im_optimize_bulk',
            'tso_im_find_orphans',
            'tso_im_delete_images',
            'tso_im_rename_image',
            'tso_im_update_seo',
            'tso_im_get_images',
            'tso_im_get_image_info',
            'tso_im_get_history',
            'tso_im_clear_history',
            'tso_im_get_history_retention',
            'tso_im_save_history_retention',
            'tso_im_get_history_stats',
            'tso_im_save_auto_settings',
            'tso_im_get_auto_settings',
            'tso_im_compress_pdf',
            'tso_im_pdf_status',
            'tso_im_mark_pdf_non_compressible',
            'tso_im_get_pdfs',
            'tso_im_revert_image',
            'tso_im_delete_backup',
            'tso_im_scan_rogue_files',
            'tso_im_delete_rogue_files',
            'tso_im_scan_url_issues',
            'tso_im_fix_orphan_meta',
            'tso_im_fix_mime_mismatch',
            'tso_im_fix_url_issues',
            'tso_im_remove_url_issues',
            'tso_im_find_ghost_attachments',
            'tso_im_delete_ghost_attachments',
            'tso_im_get_dashboard_overview',
            'tso_im_get_missing_alt',
            'tso_im_bulk_fill_alt',
        );
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, array( __CLASS__, 'handle_' . $action ) );
        }
    }

    // ----------------------------------------------------------------
    // Optimitzar una imatge (retorna RAPIDAMENT — thumbnails per crida separada)
    // ----------------------------------------------------------------
    public static function handle_tso_im_optimize_image() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
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

        $id         = absint( $_POST['attachment_id'] ?? 0 );
        $format     = sanitize_key( $_POST['format'] ?? 'webp' );
        $quality    = absint( $_POST['quality'] ?? 82 );
        $replace    = ! empty( $_POST['replace'] );
        $max_width  = absint( $_POST['max_width']  ?? 0 );
        $max_height = absint( $_POST['max_height'] ?? 0 );

        // FASE 1: conversió GD (sense cap operació DB de WordPress)
        try {
            $result = TSOIMMA_Optimizer::optimize( $id, $format, $quality, $replace, $max_width, $max_height );
        } catch ( \Throwable $ex ) {
            wp_send_json_error( 'FASE 1: ' . $ex->getMessage()
                . ' a ' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
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
                wp_send_json_error( 'FASE 2: ' . $ex->getMessage()
                    . ' a ' . basename( $ex->getFile() ) . ':' . $ex->getLine()
                    . ' (fitxers restaurats des del backup)' );
                return;
            }

            $ext_changed = ! empty( $result['old_ext'] ) && ! empty( $result['new_ext'] )
                && ! TSOIMMA_Optimizer::extensions_match( $result['old_ext'], $result['new_ext'] );

            // FASE 3: when the extension changes, regenerate thumbnails synchronously so
            // gallery/content URLs (e.g. -1024x608.jpg) exist before the editor reloads.
            if ( $ext_changed ) {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
                @set_time_limit( 300 );
                TSOIMMA_Optimizer::process_thumbnails_background( $id, $format, $quality );
            } else {
                // Same format: keep async WP-Cron to avoid blocking the optimize request.
                $cron_args = array( $id, $format, $quality );
                if ( ! wp_next_scheduled( 'tsoimma_process_thumbnails', $cron_args ) ) {
                    wp_schedule_single_event( time() - 1, 'tsoimma_process_thumbnails', $cron_args );
                }
                if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
                    wp_remote_post(
                        site_url( 'wp-cron.php' ),
                        array(
                            'timeout'   => 0.01,
                            'blocking'  => false,
                            'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, not defined by this plugin
                            'cookies'   => array(),
                        )
                    );
                }
                if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
                    TSOIMMA_Optimizer::process_thumbnails_background( $id, $format, $quality );
                }
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

        $result['needs_thumbnails'] = false;
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Callback del WP-Cron: thumbnails en background
    // (la metadata WP ja ha estat actualitzada per update_wp_metadata_only)
    // ----------------------------------------------------------------
    public static function process_thumbnails_cron( $attachment_id, $format, $quality ) {
        TSOIMMA_Optimizer::process_thumbnails_background( $attachment_id, $format, $quality );
    }

    // ----------------------------------------------------------------
    // Optimitzar thumbnails (endpoint AJAX directe de fallback)
    // ----------------------------------------------------------------
    public static function handle_tso_im_optimize_thumbnails() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit( 300 );
        ignore_user_abort( true );

        $id      = absint( $_POST['attachment_id'] ?? 0 );
        $format  = sanitize_key( $_POST['format'] ?? 'webp' );
        $quality = absint( $_POST['quality'] ?? 82 );

        TSOIMMA_Optimizer::optimize_thumbnails( $id, $format, $quality );
        clean_post_cache( $id );
        clean_attachment_cache( $id );
        if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
        do_action( 'litespeed_purge_post', $id );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.

        wp_send_json_success( array( 'done' => true ) );
    }

    // ----------------------------------------------------------------
    // Optimitzar en massa
    // ----------------------------------------------------------------
    public static function handle_tso_im_optimize_bulk() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $ids     = array_map( 'absint', $_POST['ids'] ?? array() );
        $format  = sanitize_key( $_POST['format'] ?? 'webp' );
        $quality = absint( $_POST['quality'] ?? 82 );

        $results = array();
        foreach ( $ids as $id ) {
            $res = TSOIMMA_Optimizer::optimize( $id, $format, $quality, true );
            if ( is_wp_error( $res ) ) {
                $results[] = array( 'id' => $id, 'error' => $res->get_error_message() );
                continue;
            }

            if ( ! empty( $res['replaced'] ) ) {
                try {
                    TSOIMMA_Optimizer::update_wp_metadata_only( $id, $res, $format );
                } catch ( \Throwable $ex ) {
                    TSOIMMA_Optimizer::rollback_optimize_files( $res );
                    $results[] = array(
                        'id'    => $id,
                        'error' => 'FASE 2: ' . $ex->getMessage() . ' (fitxers restaurats des del backup)',
                    );
                    continue;
                }

                $ext_changed = ! empty( $res['old_ext'] ) && ! empty( $res['new_ext'] )
                    && ! TSOIMMA_Optimizer::extensions_match( $res['old_ext'], $res['new_ext'] );

                if ( $ext_changed ) {
                    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
                    @set_time_limit( 300 );
                    TSOIMMA_Optimizer::process_thumbnails_background( $id, $format, $quality );
                } else {
                    TSOIMMA_Optimizer::optimize_thumbnails( $id, $format, $quality );
                    TSOIMMA_Optimizer::repair_content_urls_for_attachment( $id );
                }
            }

            $bulk_file = get_attached_file( $id );
            TSOIMMA_History::log( $id, 'optimize', array(
                'filename'      => $bulk_file ? basename( $bulk_file ) : '',
                'format'        => $format,
                'quality'       => $quality,
                'savings_bytes' => $res['savings_bytes'] ?? 0,
                'savings_pct'   => $res['savings_pct'] ?? 0,
            ) );
            $results[] = $res;
        }
        wp_send_json_success( $results );
    }

    // ----------------------------------------------------------------
    // Trobar imatges orfenes
    // ----------------------------------------------------------------
    public static function handle_tso_im_find_orphans() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $limit  = absint( $_POST['limit']  ?? 200 );
        $offset = absint( $_POST['offset'] ?? 0 );

        $result = TSOIMMA_Orphan_Finder::find( $limit, $offset );
        $result['total_images'] = TSOIMMA_Orphan_Finder::count_total();
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Eliminar imatges
    // ----------------------------------------------------------------
    public static function handle_tso_im_delete_images() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $ids = array_map( 'absint', $_POST['ids'] ?? array() );
        if ( empty( $ids ) ) {
            wp_send_json_error( 'No s\'han especificat IDs.' );
        }
        $result = TSOIMMA_Image_Manager::delete( $ids );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Reanomenar imatge
    // ----------------------------------------------------------------
    public static function handle_tso_im_rename_image() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $id       = absint( $_POST['attachment_id'] ?? 0 );
        // Preservem exactament el text introduït (accents/ç inclosos).
        // La validació de caràcters invàlids es fa a Image_Manager::normalize_rename_name().
        $new_name = trim( sanitize_text_field( wp_unslash( $_POST['new_name'] ?? '' ) ) );
        $strict_seo = isset( $_POST['strict_seo'] ) ? (bool) absint( $_POST['strict_seo'] ) : null;
        try {
            $rename_args = array();
            if ( $strict_seo !== null ) {
                $rename_args['strict_seo'] = $strict_seo;
            }
            $result = TSOIMMA_Image_Manager::rename( $id, $new_name, $rename_args );
        } catch ( \Throwable $ex ) {
            wp_send_json_error( 'Rename failed: ' . $ex->getMessage() );
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $id          = absint( $_POST['attachment_id'] ?? 0 );
        $title       = isset( $_POST['title'] )       ? sanitize_text_field( wp_unslash( $_POST['title'] ) )       : null;
        $alt         = isset( $_POST['alt'] )          ? sanitize_text_field( wp_unslash( $_POST['alt'] ) )          : null;
        $description = isset( $_POST['description'] )  ? wp_kses_post( wp_unslash( $_POST['description'] ) )         : null;
        $caption     = isset( $_POST['caption'] )      ? sanitize_text_field( wp_unslash( $_POST['caption'] ) )      : null;

        $result = TSOIMMA_Image_Manager::update_seo_fields( $id, $title, $alt, $description, $caption );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        TSOIMMA_History::log( $id, 'seo_update', array( 'title' => $title, 'alt' => $alt ) );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Historial
    // ----------------------------------------------------------------
    public static function handle_tso_im_get_history() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        wp_send_json_success( TSOIMMA_History::get_entries( array(
            'page'          => absint( $_POST['page'] ?? 1 ),
            'per_page'      => absint( $_POST['per_page'] ?? 50 ),
            'attachment_id' => absint( $_POST['attachment_id'] ?? 0 ),
            'action_type'   => sanitize_key( $_POST['action_type'] ?? '' ),
            'search'        => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
            'date_from'     => sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ),
            'date_to'       => sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) ),
        ) ) );
    }

    public static function handle_tso_im_get_history_stats() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
        wp_send_json_success( TSOIMMA_History::get_stats() );
    }

    public static function handle_tso_im_clear_history() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
        $days = absint( $_POST['days'] ?? 0 );
        $type = sanitize_key( $_POST['type'] ?? '' ); // opcional: filtrar per tipus
        TSOIMMA_History::clear( $days, $type );
        wp_send_json_success( array( 'cleared' => true ) );
    }

    // ----------------------------------------------------------------
    // Auto-optimitzacio
    // ----------------------------------------------------------------
    public static function handle_tso_im_save_auto_settings() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $settings = TSOIMMA_Auto_Optimizer::save_settings( array(
            'enabled' => ! empty( $_POST['enabled'] ),
            'format'  => sanitize_key( $_POST['format'] ?? 'webp' ),
            'quality' => absint( $_POST['quality'] ?? 82 ),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save_settings()
            'source_formats' => isset( $_POST['source_formats'] ) ? (array) wp_unslash( $_POST['source_formats'] ) : array(),
        ) );
        wp_send_json_success( $settings );
    }

    public static function handle_tso_im_get_auto_settings() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
        wp_send_json_success( TSOIMMA_Auto_Optimizer::get_settings() );
    }

    // ----------------------------------------------------------------
    // PDFs
    // ----------------------------------------------------------------
    public static function handle_tso_im_get_pdfs() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        wp_send_json_success( TSOIMMA_PDF_Compressor::get_pdfs(
            absint( $_POST['page'] ?? 1 ),
            absint( $_POST['per_page'] ?? 30 ),
            sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) )
        ) );
    }

    public static function handle_tso_im_compress_pdf() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $id      = absint( $_POST['attachment_id'] ?? 0 );
        $quality = absint( $_POST['quality'] ?? 96 );

        if ( ! $id ) {
            wp_send_json_error( 'ID invàlid.' );
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $id      = absint( $_POST['attachment_id'] ?? 0 );
        $replace = ! empty( $_POST['replace'] );

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
            update_post_meta( $id, '_tso_im_pdf_compressed', time() );
            TSOIMMA_History::log( $id, 'pdf_compress', array(
                'filename'      => basename( get_attached_file( $id ) ),
                'quality'       => absint( $_POST['quality'] ?? 96 ),
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $id      = absint( $_POST['attachment_id'] ?? 0 );
        $code    = sanitize_key( $_POST['code'] ?? 'manual_mark' );
        $message = sanitize_text_field( wp_unslash( $_POST['message'] ?? 'PDF marked as not compressible.' ) );

        if ( ! $id ) {
            wp_send_json_error( 'ID invàlid.' );
            return;
        }

        TSOIMMA_PDF_Compressor::mark_non_compressible( $id, $code, $message );
        wp_send_json_success( array( 'marked' => true ) );
    }

    // Mantingut per compatibilitat amb el hook de cron registrat
    public static function compress_pdf_cron( $id, $quality, $replace ) {}

    public static function handle_tso_im_get_history_retention() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
        wp_send_json_success( array(
            'days'     => (int) get_option( 'tsoimma_history_retention_days', 90 ),
            'interval' => TSOIMMA_History::get_purge_interval(),
        ) );
    }

    public static function handle_tso_im_save_history_retention() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
        $days     = absint( $_POST['days'] ?? 90 );
        $interval = sanitize_key( $_POST['interval'] ?? '' );
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $page     = absint( $_POST['page'] ?? 1 );
        $per_page = absint( $_POST['per_page'] ?? 30 );
        $search   = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
        $sort     = sanitize_key( $_POST['sort'] ?? 'date' );

        $result = TSOIMMA_Image_Manager::get_images_list( $page, $per_page, $search, $sort );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Info d'una imatge
    // ----------------------------------------------------------------
    public static function handle_tso_im_get_image_info() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $id       = absint( $_POST['attachment_id'] ?? 0 );
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $id = absint( $_POST['attachment_id'] ?? 0 );
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        $id = absint( $_POST['attachment_id'] ?? 0 );
        $backup_path = get_post_meta( $id, '_tso_im_backup_file', true );

        if ( ! $backup_path ) {
            wp_send_json_error( 'No hi ha backup per eliminar.' );
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
        $result = TSOIMMA_Rogue_Scanner::scan();
        wp_send_json_success( $result );
    }

    public static function handle_tso_im_delete_rogue_files() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        // El JS envia path_b64: path absolut en base64 (evita corrupció d'encoding UTF-8/latin1)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- base64 no té slashes, unslash és innecessari però el fem per complir
        $raw_b64s = isset( $_POST['paths_b64'] )
            ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['paths_b64'] ) )
            : array();
        $deleted = 0;
        $errors  = array();

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

            wp_delete_file( $safe_path );
            if ( ! file_exists( $safe_path ) ) {
                $deleted++;
                TSOIMMA_Optimizer::prune_empty_backup_dirs( $safe_path );
            } else {
                $errors[] = basename( $safe_path ) . ' (no es pot eliminar)';
            }
        }
        wp_send_json_success( array( 'deleted' => $deleted, 'errors' => $errors ) );
    }

    // ----------------------------------------------------------------
    // Detectar i reparar adjunts amb mime type incorrecte
    // (fitxer .webp però post_mime_type = image/jpeg, o 0x0px dimensions)
    // ----------------------------------------------------------------
    public static function handle_tso_im_fix_mime_mismatch() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        global $wpdb;
        $fixed  = 0;
        $errors = array();

        $upload_dir    = wp_upload_dir();
        $base_dir_norm = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );

        // CAS 1: _wp_attached_file acaba en .webp pero post_mime_type no es image/webp.
        // Cobreix: imatge convertida a WebP pero mime_type es image/jpeg a la BD.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows_a = $wpdb->get_results(
            "SELECT p.ID, p.post_mime_type, pm.meta_value as attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             AND pm.meta_value LIKE '%.webp'
             AND p.post_mime_type != 'image/webp'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        // CAS 2: post_mime_type es jpeg/png/gif O buit/null, fitxer no es .webp.
        // Cobreix: mime buit/corrupte, i el cas on el fitxer .jpg no existeix
        // pero si existeix el .webp equivalent (la logica PHP ho detecta al bucle).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows_b = $wpdb->get_results(
            "SELECT p.ID, p.post_mime_type, pm.meta_value as attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             AND ( p.post_mime_type IN ('image/jpeg','image/png','image/gif','image/jpg')
                   OR p.post_mime_type = ''
                   OR p.post_mime_type IS NULL )
             AND pm.meta_value NOT LIKE '%.webp'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        // CAS 3: dimensions 0x0 (metadata corrupta) qualsevol adjunt imatge.
        // Serialized WP format: s:5:"width";i:0 — the double-quotes are literal characters.
        // wpdb->esc_like() is the correct WP API to build safe LIKE patterns.
        $tsoimma_like_c = '%' . $wpdb->esc_like( 's:5:"width";i:0' ) . '%';
        $tsoimma_like_mime = 'image/' . $wpdb->esc_like( '' ) . '%'; // LIKE parameter — avoids literal %% in SQL (WP.org guideline)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $rows_c = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_mime_type, pm_file.meta_value as attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = '_wp_attached_file'
             INNER JOIN {$wpdb->postmeta} pm_meta ON pm_meta.post_id = p.ID AND pm_meta.meta_key = '_wp_attachment_metadata'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE %s
             AND pm_meta.meta_value LIKE %s",
            $tsoimma_like_mime,
            $tsoimma_like_c
        ) );

        // NOTA: rows_d ELIMINADA. Era: image/% AND NOT %.webp = TOTES les imatges.
        // rows_d cridava wp_generate_attachment_metadata() sobre tota la biblioteca:
        //   1. Regenerava thumbnails JPG sobreescrivint els WebP existents.
        //   2. Si auto-optimizer actiu: re-optimitzava tot en bucle infinit.
        // El cas que rows_d cobria (.jpg inexistent pero .webp existent) ja el cobreix
        // rows_b + la comprovacio file_exists() dins el bucle de processament.

        // Guardar IDs de rows_c: dimensions 0x0 -> cal regenerar metadata sempre.
        $ids_rows_c = array();
        foreach ( (array) $rows_c as $row_c ) {
            $ids_rows_c[ (int) $row_c->ID ] = true;
        }

        // Unificar i desduplicar per ID (sense rows_d)
        $all_rows = array();
        foreach ( array_merge( (array) $rows_a, (array) $rows_b, (array) $rows_c ) as $row ) {
            $all_rows[ (int) $row->ID ] = $row;
        }

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Capa de seguretat addicional: desactivar el hook de l'auto-optimizer
        // durant la reparació. Amb el nou sistema de transients, l'auto-optimizer
        // ja no hauria de disparar-se (no hi ha transient de "pujada nova" per a
        // aquestes imatges). Però mantenim el remove/add com a segon nivell de protecció.
        $auto_optimizer_active = class_exists( 'TSOIMMA_Auto_Optimizer' )
            && has_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ) );

        if ( $auto_optimizer_active ) {
            remove_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ), 20 );
        }

        foreach ( $all_rows as $id => $row ) {
            $rel_path     = ltrim( wp_normalize_path( (string) $row->attached_file ), '/' );
            $abs_path     = $base_dir_norm . $rel_path;
            $path_changed = false;
            $mime_changed = false;

            // Si el fitxer al meta no existeix, buscar equivalent .webp
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

            // Detectar mime type real del fitxer
            $real_ext  = strtolower( pathinfo( $abs_path, PATHINFO_EXTENSION ) );
            $mime_map  = array( 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif' );
            $real_mime = isset( $mime_map[ $real_ext ] ) ? $mime_map[ $real_ext ] : mime_content_type( $abs_path );

            // Actualitzar mime type si cal
            if ( $row->post_mime_type !== $real_mime ) {
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

            // Regenerar metadata NOMES quan cal:
            //  - El path ha canviat (fitxer .jpg -> .webp trobat)
            //  - L'adjunt ve de rows_c (dimensions 0x0)
            // Si nomes el mime era incorrecte, sincronitzem mime-type als sizes sense regenerar.
            $needs_regen = $path_changed || isset( $ids_rows_c[ $id ] );

            if ( $needs_regen ) {
                $new_meta = wp_generate_attachment_metadata( $id, $abs_path );
                if ( $new_meta && ! is_wp_error( $new_meta ) ) {
                    wp_update_attachment_metadata( $id, $new_meta );
                }
                $fixed++;
            } elseif ( $mime_changed ) {
                $meta = wp_get_attachment_metadata( $id );
                if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
                    foreach ( $meta['sizes'] as $sz => $sz_data ) {
                        $meta['sizes'][ $sz ]['mime-type'] = $real_mime;
                    }
                    wp_update_attachment_metadata( $id, $meta );
                }
                $fixed++;
            }

            clean_attachment_cache( $id );
        }

        // Restaurar l'auto-optimizer
        if ( $auto_optimizer_active ) {
            add_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ), 20, 2 );
        }

        $scanned = count( $all_rows );
        wp_send_json_success( array(
            'fixed'   => $fixed,
            'errors'  => $errors,
            'message' => $fixed > 0
                ? $fixed . ' adjunts reparats (de ' . $scanned . ' escanejats).'
                : 'Cap discrepancia trobada. Els ' . $scanned . ' adjunts escanejats ja estan correctes.',
        ) );
    }

    // ----------------------------------------------------------------
    // Escanejar i corregir URLs inconsistents al contingut
    // ----------------------------------------------------------------
    public static function handle_tso_im_scan_url_issues() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit( 120 );
        $result = TSOIMMA_URL_Fixer::scan();
        wp_send_json_success( $result );
    }

    public static function handle_tso_im_fix_url_issues() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per camp dins el foreach
        $raw_fixes = isset( $_POST['fixes'] ) ? wp_unslash( (array) $_POST['fixes'] ) : array();
        if ( empty( $raw_fixes ) ) {
            wp_send_json_error( 'No s\'han rebut correccions.' );
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per URL below
        $raw_urls = isset( $_POST['urls'] ) ? wp_unslash( (array) $_POST['urls'] ) : array();
        if ( empty( $raw_urls ) ) {
            wp_send_json_error( 'No s\'han rebut URLs per eliminar.' );
        }

        $clean_urls = array();
        foreach ( $raw_urls as $url ) {
            $url = esc_url_raw( (string) $url );
            if ( $url ) {
                $clean_urls[] = $url;
            }
        }

        if ( empty( $clean_urls ) ) {
            wp_send_json_error( 'No s\'han rebut URLs vàlides.' );
        }

        $result = TSOIMMA_URL_Fixer::remove_urls( $clean_urls );
        wp_send_json_success( $result );
    }

    // ----------------------------------------------------------------
    // Helpers privats
    // ----------------------------------------------------------------
    private static function require_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Sense permisos.', 403 );
            wp_die();
        }
    }

    /**
     * Verify an absolute path resolves to a regular file inside wp-content/uploads.
     *
     * @param string $abs_path Candidate absolute path.
     * @return string|false Resolved real path, or false when not allowed.
     */
    private static function resolve_uploads_file_path( $abs_path ) {
        $abs_path = (string) $abs_path;
        if ( '' === $abs_path ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_path  = trailingslashit( $upload_dir['basedir'] );
        $real_base  = realpath( $base_path );
        if ( false === $real_base ) {
            return false;
        }

        $real_path = realpath( $abs_path );
        if ( false === $real_path || ! is_file( $real_path ) ) {
            return false;
        }

        $norm_base = wp_normalize_path( $real_base ) . '/';
        $norm_file = wp_normalize_path( $real_path );
        if ( 0 !== strpos( $norm_file, $norm_base ) ) {
            return false;
        }

        return $real_path;
    }

    /**
     * @deprecated Mantingut per compatibilitat — usar check_ajax_referer() directament.
     */
    private static function check_nonce() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
    }
    // ----------------------------------------------------------------
    // Reparar meta de fitxers auto-optimitzats sense update_wp_metadata
    // ----------------------------------------------------------------
    public static function handle_tso_im_fix_orphan_meta() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        global $wpdb;
        $fixed = 0;

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $upload_dir    = wp_upload_dir();
        $base_dir_norm = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );

        // Cercar attachments on el path DB no existeix físicament
        $rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             ORDER BY post_id DESC
             LIMIT 500"
        );

        foreach ( (array) $rows as $row ) {
            $old_rel  = ltrim( wp_normalize_path( (string) $row->meta_value ), '/' );
            $old_path = $base_dir_norm . $old_rel;

            if ( file_exists( $old_path ) ) {
                continue;
            }

            $pi       = pathinfo( $old_path );
            $webp     = $pi['dirname'] . '/' . $pi['filename'] . '.webp';

            if ( ! file_exists( $webp ) ) {
                continue;
            }

            $post_id = (int) $row->post_id;
            $new_rel = ltrim( str_replace( $base_dir_norm, '', wp_normalize_path( $webp ) ), '/' );
            update_post_meta( $post_id, '_wp_attached_file', $new_rel );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->posts,
                array( 'post_mime_type' => 'image/webp' ),
                array( 'ID' => $post_id ),
                array( '%s' ),
                array( '%d' )
            );

            $new_meta = wp_generate_attachment_metadata( $post_id, $webp );
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
            $fixed++;
        }

        wp_send_json_success( array( 'fixed' => $fixed ) );
    }

    // ----------------------------------------------------------------
    // Trobar adjunts fantasma (registre BD existeix però fitxer físic NO)
    // SEGUR: mai crida wp_generate_attachment_metadata() ni toca fitxers
    // ----------------------------------------------------------------
    public static function handle_tso_im_find_ghost_attachments() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        global $wpdb;
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );

        // Obtenir adjunts: imatges + application/x-empty (fitxers buits registrats com a adjunts)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_mime_type, pm.meta_value as attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             AND ( p.post_mime_type LIKE 'image/%'
                   OR LOWER(p.post_mime_type) = 'application/x-empty'
                   OR p.post_mime_type = '' )
             AND pm.meta_value != ''"
        );

        $ghosts = array();
        foreach ( (array) $rows as $row ) {
            $abs_path = $base_dir . ltrim( $row->attached_file, '/' );
            $reason   = '';

            if ( ! file_exists( $abs_path ) ) {
                // CAS 1: fitxer físic no existeix al disc
                $reason = 'Fitxer no existeix al disc';
            } elseif ( filesize( $abs_path ) === 0 ) {
                // CAS 2: fitxer existeix però és 0 bytes (corrupte/buit)
                $reason = 'Fitxer buit (0 bytes)';
            } elseif ( strtolower( $row->post_mime_type ) === 'application/x-empty' ) {
                // CAS 3: mime type indica fitxer buit, fins i tot si té mida
                // (pot passar si el fitxer va ser creat amb errors)
                $reason = 'Mime type application/x-empty';
            }

            if ( $reason ) {
                $size = file_exists( $abs_path ) ? filesize( $abs_path ) : 0;
                $ghosts[] = array(
                    'id'        => (int) $row->ID,
                    'title'     => $row->post_title,
                    'filename'  => basename( $row->attached_file ),
                    'mime'      => $row->post_mime_type,
                    'meta_path' => $row->attached_file,
                    'reason'    => $reason,
                    'size'      => $size,
                    'file_exists' => file_exists( $abs_path ),
                );
            }
        }

        wp_send_json_success( array(
            'ghosts' => $ghosts,
            'total'  => count( $ghosts ),
        ) );
    }

    // ----------------------------------------------------------------
    // Eliminar adjunts fantasma:
    //   - Fitxer inexistent: elimina registre BD
    //   - Fitxer 0 bytes o application/x-empty: elimina fitxer físic + registre BD
    // NO crida wp_generate_attachment_metadata() → cap risc de bucles
    // ----------------------------------------------------------------
    public static function handle_tso_im_delete_ghost_attachments() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ids = array_map( 'absint', isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array() );
        if ( empty( $ids ) ) {
            wp_send_json_error( 'No s\'han especificat IDs.' );
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

            // Comprovació de seguretat: rebutjar si el fitxer existeix, té mida > 0
            // i el mime type és una imatge vàlida (fitxer real i útil)
            $is_valid_image = $file_exists
                && $file_size > 0
                && strpos( $mime_db, 'image/' ) === 0
                && $mime_db !== 'application/x-empty';

            if ( $is_valid_image ) {
                $errors[] = 'ID ' . $id . ' (' . basename( $abs_path ) . '): fitxer real amb contingut, no eliminat per seguretat.';
                continue;
            }

            // Si el fitxer existeix però és buit o té mime x-empty: eliminar-lo físicament
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
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();
        wp_send_json_success( TSOIMMA_Dashboard::get_overview() );
    }

    public static function handle_tso_im_get_missing_alt() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        wp_send_json_success(
            TSOIMMA_Dashboard::get_missing_alt_list(
                absint( $_POST['page'] ?? 1 ),
                absint( $_POST['per_page'] ?? 35 ),
                ! empty( $_POST['used_only'] )
            )
        );
    }

    public static function handle_tso_im_bulk_fill_alt() {
        check_ajax_referer( 'tso_im_nonce', 'nonce' );
        self::require_admin();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ids = array_map( 'absint', isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array() );
        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'Select at least one image.', 'tso-image-master' ) );
        }

        $source = sanitize_key( wp_unslash( $_POST['source'] ?? 'suggested' ) );
        $result = TSOIMMA_Dashboard::bulk_fill_alt( $ids, $source );
        wp_send_json_success( $result );
    }


}
