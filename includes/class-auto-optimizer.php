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
    }

    /**
     * Marca un attachment com a "pujada nova real".
     * Cridat des de add_attachment, que WordPress dispara UNA SOLA vegada
     * quan l'usuari puja un fitxer nou. Mai es crida en regeneracions.
     */
    public static function mark_new_upload( $attachment_id ) {
        // TTL 5 minuts: suficient per cobrir la generació de metadata posterior.
        set_transient( self::upload_transient_key( $attachment_id ), '1', 300 );
    }

    /**
     * S'executa quan WordPress genera la metadata d'un attachment.
     * Gracies al transient, NOMES optimitza si és una pujada nova real.
     * Qualsevol regeneració interna o externa és ignorada completament.
     */
    public static function on_upload( $metadata, $attachment_id ) {
        // FILTRE PRINCIPAL: és una pujada nova real?
        // Si no existeix el transient, no és una pujada nova de l'usuari,
        // sino una regeneració interna/externa. Retornar sense fer res.
        // Cobreix: regeneració de thumbnails, fix_mime_mismatch,
        // process_thumbnails_background, revert, plugins externs, etc.
        if ( ! get_transient( self::upload_transient_key( $attachment_id ) ) ) {
            return $metadata;
        }

        // Consumir el transient immediatament: una sola optimització per upload.
        delete_transient( self::upload_transient_key( $attachment_id ) );

        // Comprovar que l'auto-optimització està activada
        $settings = self::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return $metadata;
        }

        // Comprovar que és una imatge
        $mime = get_post_mime_type( $attachment_id );
        if ( false === strpos( $mime, 'image/' ) ) {
            return $metadata;
        }

        $source_key = self::mime_to_source_key( $mime );
        if ( ! $source_key ) {
            return $metadata;
        }

        $allowed_sources = array_map( 'sanitize_key', (array) ( $settings['source_formats'] ?? array() ) );
        if ( empty( $allowed_sources ) ) {
            $allowed_sources = array( 'jpg', 'png', 'webp' );
        }

        if ( ! in_array( $source_key, $allowed_sources, true ) ) {
            return $metadata;
        }

        // Processar GIF només si és estàtic (no animat).
        if ( 'gif' === $source_key ) {
            $file_path = get_attached_file( $attachment_id );
            if ( ! $file_path || self::is_animated_gif( $file_path ) ) {
                return $metadata;
            }
        }

        $format  = isset( $settings['format'] )  ? $settings['format']  : 'webp';
        $quality = isset( $settings['quality'] ) ? $settings['quality'] : 82;

        // "Original" no és aplicable a BMP/TIFF amb el pipeline actual de GD.
        // Comportament robust: no convertir per evitar un "fallback" inesperat a JPG.
        if ( 'original' === $format && in_array( $source_key, array( 'bmp', 'tiff' ), true ) ) {
            return $metadata;
        }

        // Verificar suport WebP si cal
        if ( 'webp' === $format && ! TSOIMMA_Optimizer::webp_supported() ) {
            $format = 'jpg';
        }

        // Optimitzar imatge principal.
        // $make_backup = false: imatge nova, l'usuari te l'original al seu equip.
        $result = TSOIMMA_Optimizer::optimize( $attachment_id, $format, $quality, true, 0, 0, false );

        if ( ! is_wp_error( $result ) && ! empty( $result['replaced'] ) ) {

            // ── FASE 2: Actualitzar metadata principal a la BD ────────────
            // Ho fem ABANS de generar thumbnails perquè update_wp_metadata_only
            // escriu el nou path .webp i el nou mime type.
            TSOIMMA_Optimizer::update_wp_metadata_only( $attachment_id, $result, $format );

            // ── FASE 3: Eliminar thumbnails originals (PNG/JPG) ───────────
            // CRÍTIC: eliminar ABANS de wp_generate_attachment_metadata.
            // Si els thumbnails WebP ja existeixen quan WP els vol crear,
            // wp_unique_filename() genera noms amb sufix "-1.webp", "-2.webp"...
            // que trenquen les URLs i fan que la biblioteca no mostri previsualtizació.
            $current_meta = wp_get_attachment_metadata( $attachment_id );
            if ( ! empty( $current_meta['sizes'] ) ) {
                $thumb_dir = trailingslashit( dirname( get_attached_file( $attachment_id ) ) );
                foreach ( $current_meta['sizes'] as $size_data ) {
                    if ( empty( $size_data['file'] ) ) continue;
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

            // ── Registrar al historial ────────────────────────────────────
            $log_file = isset( $result['new_path'] ) ? $result['new_path'] : get_attached_file( $attachment_id );
            TSOIMMA_History::log( $attachment_id, 'auto_optimize', array(
                'filename'      => $log_file ? basename( $log_file ) : '',
                'format'        => $format,
                'quality'       => $quality,
                'original_size' => isset( $result['original_size'] ) ? $result['original_size'] : 0,
                'new_size'      => isset( $result['new_size'] )      ? $result['new_size']      : 0,
                'savings_bytes' => isset( $result['savings_bytes'] ) ? $result['savings_bytes'] : 0,
                'savings_pct'   => isset( $result['savings_pct'] )   ? $result['savings_pct']   : 0,
            ) );

            update_post_meta( $attachment_id, '_tso_im_auto_optimized', time() );
        }

        $saved = wp_get_attachment_metadata( $attachment_id );
        return ( $saved && is_array( $saved ) ) ? $saved : $metadata;
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
            'format'  => in_array( $format_raw, array( 'webp', 'jpg', 'original' ), true )
                            ? $format_raw : 'webp',
            'quality' => min( 100, max( 50, absint( isset( $settings['quality'] ) ? $settings['quality'] : 82 ) ) ),
            'source_formats' => $source_clean,
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
}
