<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_Auto_Optimizer {

    /**
     * Clau del transient per marcar una pujada nova real.
     */
    private static function upload_transient_key( $attachment_id ) {
        return 'tsoimma_new_upload_' . absint( $attachment_id );
    }

    public static function init() {
        // add_attachment: s'executa UNICAMENT quan WordPress insereix un attachment
        // nou a la BD (pujada real de l'usuari). MAI es dispara durant regeneració
        // de thumbnails, fix_mime_mismatch, process_thumbnails_background, revert
        // ni cap altre procés intern o de plugins externs.
        add_action( 'add_attachment', array( __CLASS__, 'mark_new_upload' ) );

        // wp_generate_attachment_metadata: s'executa tant en pujades noves COM en
        // regeneracions. La comprovació del transient garanteix que NOMÉS processa
        // pujades reals de l'usuari.
        add_action( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );

        // Per-upload checkbox under the media uploader (Media > Add New + media modal).
        add_action( 'post-plupload-upload-ui', array( __CLASS__, 'render_upload_toggle' ) );
        add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_upload_toggle_media' ) );
        add_action( 'wp_ajax_tsoimma_save_upload_auto_pref', array( __CLASS__, 'ajax_save_upload_toggle' ) );
    }

    /**
     * AJAX: remember the upload checkbox state as soon as the user changes it.
     *
     * @return void
     */
    public static function ajax_save_upload_toggle() {
        tsoimma_verify_ajax_nonce();
        if ( ! self::user_can_toggle_upload() ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'tso-image-master' ), 403 );
        }
        $choice = tsoimma_get_ajax_post_bool( 'enabled' ) ? 'on' : 'off';
        tsoimma_set_upload_auto_pref( $choice );
        wp_send_json_success( array( 'enabled' => 'on' === $choice ) );
    }

    /**
     * Whether the current user sees (and may use) the upload checkbox.
     *
     * @return bool
     */
    private static function user_can_toggle_upload() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Default state of the upload checkbox: last choice of the user, else the global setting.
     *
     * @return bool
     */
    private static function upload_toggle_default() {
        $pref = tsoimma_get_upload_auto_pref();
        if ( '' !== $pref ) {
            return 'on' === $pref;
        }
        $settings = self::get_settings();
        return ! empty( $settings['enabled'] );
    }

    /**
     * Print the "optimize on upload" checkbox below the drop zone.
     * No name attribute: the value travels only through the uploader JS (multipart_params).
     *
     * @return void
     */
    public static function render_upload_toggle() {
        if ( ! self::user_can_toggle_upload() ) {
            return;
        }
        $settings = self::get_settings();
        $format   = isset( $settings['format'] ) ? (string) $settings['format'] : 'webp';
        $label    = 'original' === $format ? __( 'original format', 'tso-image-master' ) : strtoupper( $format );
        $quality  = tsoimma_clamp_image_quality( isset( $settings['quality'] ) ? $settings['quality'] : 82 );
        ?>
        <p class="tsoimma-upload-auto hide-if-no-js">
            <label>
                <input type="checkbox" class="tsoimma-upload-auto-cb" value="1" <?php checked( self::upload_toggle_default() ); ?> />
                <?php esc_html_e( 'Optimize images automatically on upload', 'tso-image-master' ); ?>
            </label>
            <span class="description">
                <?php
                printf(
                    /* translators: 1: output format (e.g. WEBP), 2: quality percentage */
                    esc_html__( '(TSO Image Master: %1$s, quality %2$d%%)', 'tso-image-master' ),
                    esc_html( $label ),
                    absint( $quality )
                );
                ?>
            </span>
        </p>
        <?php
    }

    /**
     * Enqueue the uploader checkbox JS on Media > Add New (called from the admin enqueue callback).
     *
     * @param string $hook Admin page hook.
     * @return void
     */
    public static function maybe_enqueue_upload_toggle( $hook ) {
        if ( 'media-new.php' !== $hook ) {
            return;
        }
        self::enqueue_upload_toggle( array( 'jquery', 'plupload-handlers' ) );
    }

    /**
     * Enqueue the uploader checkbox JS wherever the media modal is loaded.
     *
     * @return void
     */
    public static function enqueue_upload_toggle_media() {
        self::enqueue_upload_toggle( array( 'jquery', 'wp-plupload', 'media-views' ) );
    }

    /**
     * Enqueue and localize the uploader checkbox script once.
     *
     * @param string[] $deps Script dependencies.
     * @return void
     */
    private static function enqueue_upload_toggle( $deps ) {
        if ( ! self::user_can_toggle_upload() || wp_script_is( 'tsoimma-upload-toggle', 'enqueued' ) ) {
            return;
        }
        $js_file = TSOIMMA_PATH . 'admin/js/upload-toggle.js';
        $js_ver  = TSOIMMA_VERSION . '.' . ( file_exists( $js_file ) ? (string) filemtime( $js_file ) : '0' );
        wp_enqueue_script( 'tsoimma-upload-toggle', TSOIMMA_URL . 'admin/js/upload-toggle.js', $deps, $js_ver, true );
        wp_localize_script(
            'tsoimma-upload-toggle',
            'tsoimmaUploadConfig',
            array(
                'enabled'  => self::upload_toggle_default() ? '1' : '0',
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( TSOIMMA_NONCE_AJAX ),
            )
        );
    }

    /**
     * Marca un attachment com a "pujada nova real".
     * Cridat des de add_attachment, que WordPress dispara UNA SOLA vegada
     * quan l'usuari puja un fitxer nou. Mai es crida en regeneracions.
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public static function mark_new_upload( $attachment_id ) {
        // Valor del transient: '1' = seguir l'ajust global; 'on' / 'off' = decisió
        // de la casella del carregador de mitjans per a aquesta pujada.
        $flag   = '1';
        $choice = tsoimma_get_upload_auto_choice();
        if ( '' !== $choice && self::user_can_toggle_upload() ) {
            $flag = $choice;
            tsoimma_set_upload_auto_pref( $choice );
        } elseif ( 'off' === $choice ) {
            $flag = 'off';
        }

        // TTL 5 minuts: suficient per cobrir la generació de metadata posterior.
        set_transient( self::upload_transient_key( $attachment_id ), $flag, 300 );
    }

    /**
     * S'executa quan WordPress genera la metadata d'un attachment.
     * Gracies al transient, NOMES optimitza si és una pujada nova real.
     * Qualsevol regeneració interna o externa és ignorada completament.
     *
     * @param array $metadata      Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array
     */
    public static function on_upload( $metadata, $attachment_id ) {
        // FILTRE PRINCIPAL: és una pujada nova real?
        // Si no existeix el transient, no és una pujada nova de l'usuari,
        // sino una regeneració interna/externa. Retornar sense fer res.
        // Cobreix: regeneració de thumbnails, fix_mime_mismatch,
        // process_thumbnails_background, revert, plugins externs, etc.
        $upload_flag = get_transient( self::upload_transient_key( $attachment_id ) );
        if ( ! $upload_flag ) {
            return $metadata;
        }

        // Consumir el transient immediatament: una sola passada per upload.
        delete_transient( self::upload_transient_key( $attachment_id ) );

        $settings = self::get_settings();

        // La casella del carregador mana sobre l'ajust global per a aquesta pujada.
        if ( 'on' === $upload_flag ) {
            $settings['enabled'] = true;
        } elseif ( 'off' === $upload_flag ) {
            $settings['enabled'] = false;
        }

        // Fill-alt can run even when auto-optimize is disabled.
        if ( empty( $settings['enabled'] ) ) {
            self::maybe_fill_alt_on_upload( $attachment_id, $settings );
            return $metadata;
        }

        $skip_kb = isset( $settings['skip_small_kb'] ) ? absint( $settings['skip_small_kb'] ) : 0;
        if ( TSOIMMA_Optimizer::should_skip_auto_optimize( $attachment_id, $skip_kb ) ) {
            self::maybe_fill_alt_on_upload( $attachment_id, $settings );
            return $metadata;
        }

        // Comprovar que és una imatge
        $mime = get_post_mime_type( $attachment_id );
        if ( false === strpos( $mime, 'image/' ) ) {
            self::maybe_fill_alt_on_upload( $attachment_id, $settings );
            return $metadata;
        }

        $source_key = self::mime_to_source_key( $mime );
        if ( ! $source_key ) {
            self::maybe_fill_alt_on_upload( $attachment_id, $settings );
            return $metadata;
        }

        $allowed_sources = array_map( 'sanitize_key', (array) ( $settings['source_formats'] ?? array() ) );
        if ( empty( $allowed_sources ) ) {
            $allowed_sources = array( 'jpg', 'png', 'webp' );
        }

        if ( ! in_array( $source_key, $allowed_sources, true ) ) {
            self::maybe_fill_alt_on_upload( $attachment_id, $settings );
            return $metadata;
        }

        // Processar GIF només si és estàtic (no animat).
        if ( 'gif' === $source_key ) {
            $file_path = get_attached_file( $attachment_id );
            if ( ! $file_path || self::is_animated_gif( $file_path ) ) {
                self::maybe_fill_alt_on_upload( $attachment_id, $settings );
                return $metadata;
            }
        }

        $format  = isset( $settings['format'] )  ? $settings['format']  : 'webp';
        $quality = tsoimma_clamp_image_quality( isset( $settings['quality'] ) ? $settings['quality'] : 82 );

        // "Original" no és aplicable a BMP/TIFF amb el pipeline actual de GD.
        // Comportament robust: no convertir per evitar un "fallback" inesperat a JPG.
        if ( 'original' === $format && in_array( $source_key, array( 'bmp', 'tiff' ), true ) ) {
            self::maybe_fill_alt_on_upload( $attachment_id, $settings );
            return $metadata;
        }

        // Verificar suport WebP/AVIF si cal
        if ( 'webp' === $format && ! TSOIMMA_Optimizer::webp_supported() ) {
            $format = 'jpg';
        }
        if ( 'avif' === $format && ! TSOIMMA_Optimizer::avif_supported() ) {
            $format = TSOIMMA_Optimizer::webp_supported() ? 'webp' : 'jpg';
        }

        $lock_token = TSOIMMA_Queue::acquire_attachment_lock( $attachment_id );
        if ( '' === $lock_token ) {
            // Another optimize owns this file — defer auto-optimize to the background queue.
            TSOIMMA_Queue::enqueue_optimize( array( $attachment_id ), $format, $quality, true );
            self::maybe_fill_alt_on_upload( $attachment_id, $settings );
            return $metadata;
        }

        try {
        // Optimitzar imatge principal.
        // Temporary backup always: needed for safe rollback if FASE 2/3 fails.
        // On success the backup is removed (new upload — user still has the local original).
        $result = TSOIMMA_Optimizer::optimize( $attachment_id, $format, $quality, true, 0, 0, true );

        if ( ! is_wp_error( $result ) && ! empty( $result['replaced'] ) ) {
            $snapshot = TSOIMMA_Optimizer::snapshot_attachment_state( $attachment_id );

            try {
                // ── FASE 2: Actualitzar metadata principal a la BD ────────────
                // Ho fem ABANS de generar thumbnails perquè update_wp_metadata_only
                // escriu el nou path .webp i el nou mime type.
                TSOIMMA_Optimizer::update_wp_metadata_only( $attachment_id, $result, $format );

                // History as soon as convert + metadata succeed (do not wait for thumbs).
                $log_file = isset( $result['new_path'] ) ? $result['new_path'] : get_attached_file( $attachment_id );
                try {
                    TSOIMMA_History::log(
                        $attachment_id,
                        'auto_optimize',
                        array(
                            'filename'      => $log_file ? basename( $log_file ) : '',
                            'format'        => $format,
                            'quality'       => $quality,
                            'original_size' => isset( $result['original_size'] ) ? $result['original_size'] : 0,
                            'new_size'      => isset( $result['new_size'] ) ? $result['new_size'] : 0,
                            'savings_bytes' => isset( $result['savings_bytes'] ) ? $result['savings_bytes'] : 0,
                            'savings_pct'   => isset( $result['savings_pct'] ) ? $result['savings_pct'] : 0,
                        )
                    );
                    TSOIMMA_History::clear_pending( $attachment_id );
                    tsoimma_update_attachment_meta( $attachment_id, 'auto_optimized', time() );
                } catch ( \Throwable $log_ex ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- History/meta must not roll back a successful convert.
                }

                // ── FASE 3: Eliminar thumbnails originals (PNG/JPG) ───────────
                // CRÍTIC: eliminar ABANS de wp_generate_attachment_metadata.
                // Si els thumbnails WebP ja existeixen quan WP els vol crear,
                // wp_unique_filename() genera noms amb sufix "-1.webp", "-2.webp"...
                // que trenquen les URLs i fan que la biblioteca no mostri previsualtizació.
                //
                // IMPORTANT: en una pujada nova, WordPress encara NO ha desat la
                // metadata a la BD en aquest punt — wp_update_attachment_metadata()
                // s'executa DESPRÉS que aquest filtre (wp_generate_attachment_metadata)
                // retorni. Per tant wp_get_attachment_metadata() aquí sempre tornava
                // buit, aquest bloc mai trobava els thumbnails originals per esborrar,
                // i quedaven com a fitxers orfes (mai referenciats, mai eliminats) a
                // uploads/ per a cada pujada auto-optimitzada. El paràmetre $metadata
                // rebut per aquest mètode SÍ conté els "sizes" que WP acaba de generar
                // per al fitxer original, així que és la font correcta aquí.
                $current_meta = ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) )
                    ? $metadata
                    : wp_get_attachment_metadata( $attachment_id );
                if ( ! empty( $current_meta['sizes'] ) ) {
                    $thumb_dir = trailingslashit( dirname( get_attached_file( $attachment_id ) ) );
                    foreach ( $current_meta['sizes'] as $size_data ) {
                        if ( empty( $size_data['file'] ) ) {
                            continue;
                        }
                        // Eliminar qualsevol variant d'extensió (jpg, png, webp...) per nom base
                        $pi = pathinfo( $size_data['file'] );
                        foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ) as $ext ) {
                            $candidate = $thumb_dir . $pi['filename'] . '.' . $ext;
                            if ( file_exists( $candidate ) ) {
                                wp_delete_file( $candidate );
                            }
                        }
                    }
                }

                // ── FASE 4: Regenerar thumbnails des del fitxer WebP ─────────
                // Ara que no hi ha thumbnails al disc, WP els crea amb noms nets.
                // WP 6.1+ genera thumbnails en el mateix format que la font (WebP→WebP).
                // WP < 6.1 genera JPEG; els convertim a WebP a la Fase 5.
                $new_file = get_attached_file( $attachment_id );
                if ( $new_file && file_exists( $new_file ) ) {
                    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                    }
                    $new_meta = wp_generate_attachment_metadata( $attachment_id, $new_file );
                    if ( $new_meta && ! is_wp_error( $new_meta ) ) {
                        wp_update_attachment_metadata( $attachment_id, $new_meta );
                    }
                }

                // ── FASE 5: Convertir thumbnails al format triat ──────────────
                // Necessari per a WP < 6.1 (genera JPEG) o si el format triat no és WebP.
                // En WP 6.1+ amb font WebP és un no-op (thumbnails ja són WebP).
                TSOIMMA_Optimizer::optimize_thumbnails( $attachment_id, $format, $quality );
                TSOIMMA_Optimizer::repair_content_urls_for_attachment( $attachment_id, $current_meta );

                // Drop temporary backup after a successful new-upload conversion.
                // Isolated: cleanup failures must not roll back a completed conversion.
                try {
                    if ( ! empty( $result['backup_path'] ) ) {
                        TSOIMMA_Optimizer::delete_backup_file( $result['backup_path'] );
                    }
                    TSOIMMA_Optimizer::clear_backup_meta( $attachment_id );
                } catch ( \Throwable $cleanup_ex ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Backup cleanup is best-effort.
                }
            } catch ( \Throwable $ex ) {
                TSOIMMA_Optimizer::rollback_optimize_state( $attachment_id, $result, $snapshot );
                // Convert was reverted — drop the early auto_optimize history row + flag.
                TSOIMMA_History::delete_latest_for_attachment( $attachment_id, 'auto_optimize' );
                TSOIMMA_History::clear_pending( $attachment_id );
                tsoimma_delete_attachment_meta( $attachment_id, 'auto_optimized' );
            }
        }

        self::maybe_fill_alt_on_upload( $attachment_id, $settings );

        $saved = wp_get_attachment_metadata( $attachment_id );
        return ( $saved && is_array( $saved ) ) ? $saved : $metadata;
        } finally {
            TSOIMMA_Queue::release_attachment_lock( $attachment_id, $lock_token );
        }
    }

    /**
     * Retorna la configuració d'auto-optimització.
     */
    public static function get_settings() {
        $defaults = array(
            'enabled' => false,
            'format'  => 'webp',
            'quality' => 82,
            'source_formats' => array( 'jpg', 'png', 'webp', 'gif', 'bmp', 'tiff' ),
            'fill_alt_on_upload' => false,
            'skip_small_kb'      => 0,
        );
        $saved = get_option( 'tsoimma_auto_optimize_settings', array() );
        if ( empty( $saved['source_formats'] ) || ! is_array( $saved['source_formats'] ) ) {
            $saved['source_formats'] = $defaults['source_formats'];
        }
        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Guarda la configuració d'auto-optimització.
     */
    public static function save_settings( $settings ) {
        $format_raw = isset( $settings['format'] ) ? $settings['format'] : '';
        $source_raw = isset( $settings['source_formats'] ) ? (array) $settings['source_formats'] : array();
        $allowed    = array( 'jpg', 'png', 'webp', 'gif', 'bmp', 'tiff' );
        $source_clean = array_values( array_intersect( array_map( 'sanitize_key', $source_raw ), $allowed ) );
        if ( empty( $source_clean ) ) {
            $source_clean = array( 'jpg', 'png', 'webp' );
        }
        $clean = array(
            'enabled' => ! empty( $settings['enabled'] ),
            'format'  => in_array( $format_raw, array( 'webp', 'jpg', 'avif', 'png', 'original' ), true )
                            ? $format_raw : 'webp',
            'quality' => tsoimma_clamp_image_quality( isset( $settings['quality'] ) ? $settings['quality'] : 82 ),
            'source_formats' => $source_clean,
            'fill_alt_on_upload' => ! empty( $settings['fill_alt_on_upload'] ),
            'skip_small_kb'      => min( 5120, max( 0, absint( $settings['skip_small_kb'] ?? 0 ) ) ),
        );
        update_option( 'tsoimma_auto_optimize_settings', $clean );
        return $clean;
    }

    /**
     * Map post mime type to source format key used in settings.
     */
    private static function mime_to_source_key( $mime ) {
        $mime = strtolower( (string) $mime );
        if ( in_array( $mime, array( 'image/jpeg', 'image/jpg', 'image/pjpeg' ), true ) ) return 'jpg';
        if ( 'image/png' === $mime ) return 'png';
        if ( 'image/webp' === $mime ) return 'webp';
        if ( 'image/gif' === $mime ) return 'gif';
        if ( in_array( $mime, array( 'image/bmp', 'image/x-ms-bmp', 'image/x-bmp' ), true ) ) return 'bmp';
        if ( in_array( $mime, array( 'image/tif', 'image/tiff', 'image/x-tiff' ), true ) ) return 'tiff';
        return '';
    }

    /**
     * Returns true when GIF contains more than one frame.
     */
    private static function is_animated_gif( $file_path ) {
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;
        WP_Filesystem();

        if ( ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'get_contents' ) ) {
            // Fail-safe: si no podem llegir, tractem-lo com animat i NO el convertim.
            return true;
        }

        $bytes = $wp_filesystem->get_contents( $file_path );
        if ( ! is_string( $bytes ) || '' === $bytes ) {
            // Fail-safe: si no podem verificar els frames, no assumim que és estàtic.
            return true;
        }

        return preg_match_all( '#\x00\x21\xF9\x04.{4}\x00\x2C#s', $bytes ) > 1;
    }

    /**
     * Fill missing alt text after upload when enabled in settings.
     *
     * @param int                  $attachment_id Attachment ID.
     * @param array<string, mixed> $settings      Auto settings.
     * @return void
     */
    private static function maybe_fill_alt_on_upload( $attachment_id, $settings ) {
        if ( empty( $settings['fill_alt_on_upload'] ) ) {
            return;
        }

        $current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
        if ( '' !== $current_alt && ! TSOIMMA_Dashboard::is_weak_alt( $current_alt, $attachment_id ) ) {
            return;
        }

        $suggested = TSOIMMA_Dashboard::pick_alt_fill_value( $attachment_id, 'suggested' );
        if ( '' === $suggested ) {
            return;
        }

        TSOIMMA_Image_Manager::update_seo_fields( $attachment_id, null, $suggested, null, null );
        TSOIMMA_History::log(
            $attachment_id,
            'seo_update',
            array(
                'seo_alt' => $suggested,
                'source'  => 'auto_upload_alt',
            )
        );
    }
}
