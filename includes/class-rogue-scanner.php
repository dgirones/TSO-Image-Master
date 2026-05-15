<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Escàner de fitxers físics sense attachment de WordPress.
 * Detecta fitxers com imatge.jpg.webp, imatge_tso_im_backup.jpg,
 * i qualsevol imatge al disc que no estigui registrada a la BD.
 */
class TSOIMMA_Rogue_Scanner {

    // Patrons de fitxers que sabem que son "rogue" (prioritat alta)
    private static $known_patterns = array(
        '/\.(jpg|jpeg|png|gif|webp)\.(jpg|jpeg|png|gif|webp)$/i' => 'double_extension',
        '/_tso_im_backup\./i'                                     => 'tso_backup',
        '/_tso_im_opt\./i'                                        => 'tso_temp',
        '/_tso_im_compressed\./i'                                 => 'tso_pdf_compressed',
        '/\.(bk|bak)\./i'                                         => 'generic_backup',
    );

    /**
     * Escàner principal: retorna fitxers físics sense entrada a WP.
     */
    /**
     * Assegura que una ruta és UTF-8 vàlid per a json_encode.
     * Fitxers pujats en sistemes latin1 poden tenir bytes no-UTF-8.
     */
    private static function safe_utf8( $str ) {
        if ( function_exists( 'mb_detect_encoding' ) ) {
            $enc = mb_detect_encoding( $str, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
            if ( $enc && $enc !== 'UTF-8' ) {
                return mb_convert_encoding( $str, 'UTF-8', $enc );
            }
        }
        // Fallback: eliminar bytes invàlids per UTF-8
        return htmlspecialchars_decode( htmlspecialchars( $str, ENT_SUBSTITUTE, 'UTF-8' ) );
    }

    public static function scan() {
        $upload_dir = wp_upload_dir();
        $base_path  = trailingslashit( $upload_dir['basedir'] );
        $base_url   = trailingslashit( $upload_dir['baseurl'] );

        // Obtenir TOTS els fitxers registrats a WP (fitxer principal + thumbnails)
        $registered = self::get_all_registered_files( $base_path );
        $pdf_preview_parents = self::get_pdf_preview_parent_index();

        // Escanejar tots els fitxers físics
        $all_files = self::scan_directory( $base_path );

        $rogue   = array();
        $by_type = array( 'known_pattern' => 0, 'unregistered' => 0 );

        foreach ( $all_files as $full_path ) {
            // Normalitzar el path del filesystem per garantir coincidència amb $registered.
            // wp_normalize_path() converteix backslashes i elimina dobles slashes,
            // igual que fem en get_all_registered_files().
            $norm_path = wp_normalize_path( $full_path );
            $rel_path  = str_replace( wp_normalize_path( $base_path ), '', $norm_path );
            $filename  = basename( $full_path );
            $ext       = strtolower( pathinfo( $full_path, PATHINFO_EXTENSION ) );

            // Saltar fitxers no-imatge
            if ( ! in_array( $ext, array( 'jpg','jpeg','png','gif','webp','avif' ), true ) ) {
                continue;
            }

            $reason_code = '';
            $reason      = '';
            $priority = 'normal';

            // Previews de PDF legítims: només són rogue si no trobem cap attachment PDF parent.
            if ( self::is_pdf_preview_filename( $filename ) && self::has_pdf_preview_parent( $norm_path, $base_path, $pdf_preview_parents ) ) {
                continue;
            }

            // 1. Comprovar patrons coneguts (màxima prioritat)
            foreach ( self::$known_patterns as $pattern => $code ) {
                if ( preg_match( $pattern, $filename ) ) {
                    $reason_code = $code;
                    $reason      = self::reason_label_from_code( $code );
                    $priority = 'high';
                    $by_type['known_pattern']++;
                    break;
                }
            }

            // 2. Si no té patró, comprovar si és un fitxer NO registrat a WP.
            // Usar $norm_path (normalitzat) per coincidir amb les claus de $registered.
            if ( ! $reason_code && ! isset( $registered[ $norm_path ] ) ) {
                $reason_code = 'unregistered_wp_db';
                $reason      = self::reason_label_from_code( $reason_code );
                $by_type['unregistered']++;
            }

            if ( $reason_code ) {
                $size = file_exists( $full_path ) ? filesize( $full_path ) : 0;
                // Guardem el path absolut original (no normalitzat) en base64
                // per preservar l'encoding del filesystem en l'eliminació.
                $rogue[] = array(
                    'path'      => self::safe_utf8( $rel_path ),
                    'path_b64'  => base64_encode( $full_path ),
                    'filename'  => self::safe_utf8( $filename ),
                    'reason'    => $reason,
                    'reason_code' => $reason_code,
                    'priority'  => $priority,
                    'size'      => $size,
                    'size_h'    => size_format( $size ),
                    'url'       => $base_url . self::safe_utf8( $rel_path ),
                    'date'      => gmdate( 'd/m/Y H:i', filemtime( $full_path ) ),
                );
            }
        }

        // Ordenar A→Z per nom de fitxer
        usort( $rogue, function( $a, $b ) {
            return strcasecmp( $a['filename'], $b['filename'] );
        } );

        $total_size = array_sum( array_column( $rogue, 'size' ) );

        return array(
            'files'       => $rogue,
            'total'       => count( $rogue ),
            'total_size'  => $total_size,
            'total_size_h'=> size_format( $total_size ),
            'by_type'     => $by_type,
        );
    }

    private static function reason_label_from_code( $code ) {
        $labels = array(
            'double_extension'   => 'Doble extensió (.jpg.webp)',
            'tso_backup'         => 'Backup TSO',
            'tso_temp'           => 'Temporal TSO',
            'tso_pdf_compressed' => 'PDF comprimit TSO',
            'generic_backup'     => 'Backup genèric (.bk)',
            'unregistered_wp_db' => 'Fitxer no registrat a la BD de WordPress',
        );
        return isset( $labels[ $code ] ) ? $labels[ $code ] : 'Fitxer problemàtic';
    }

    /**
     * Obté tots els fitxers registrats a WP (originals + thumbnails).
     * Retorna un array indexat per ruta absoluta.
     */
    private static function get_all_registered_files( $base_path ) {
        global $wpdb;
        $registered = array();

        // Normalitzar $base_path una sola vegada per a totes les comparacions.
        // wp_normalize_path() converteix backslashes → forward slashes i
        // elimina dobles slashes. Imprescindible per a compatibilitat entre SO.
        $base_norm = wp_normalize_path( trailingslashit( $base_path ) );

        $rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_wp_attached_file', '_wp_attachment_metadata')
             AND meta_value != ''"
        );
        $by_attachment = array();
        foreach ( (array) $rows as $row ) {
            $id = (int) $row->post_id;
            if ( ! isset( $by_attachment[ $id ] ) ) {
                $by_attachment[ $id ] = array();
            }
            $by_attachment[ $id ][ $row->meta_key ] = $row->meta_value;
        }

        foreach ( $by_attachment as $attachment_meta ) {
            $attached_rel = isset( $attachment_meta['_wp_attached_file'] ) ? (string) $attachment_meta['_wp_attached_file'] : '';
            if ( $attached_rel !== '' ) {
                $registered[ wp_normalize_path( $base_norm . ltrim( $attached_rel, '/' ) ) ] = true;
            }

            if ( empty( $attachment_meta['_wp_attachment_metadata'] ) ) {
                continue;
            }

            $meta = maybe_unserialize( $attachment_meta['_wp_attachment_metadata'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            if ( ! is_array( $meta ) ) {
                continue;
            }

            $meta_file = isset( $meta['file'] ) ? (string) $meta['file'] : '';
            $subdir    = dirname( $meta_file );
            if ( '' === $meta_file || '.' === $subdir || '' === $subdir ) {
                $attached_subdir = dirname( $attached_rel );
                $subdir = ( '.' === $attached_subdir ) ? '' : (string) $attached_subdir;
            }

            $thumb_dir = ( '' === $subdir )
                ? $base_norm
                : $base_norm . trailingslashit( ltrim( $subdir, '/' ) );

            if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                foreach ( $meta['sizes'] as $size ) {
                    if ( ! empty( $size['file'] ) ) {
                        $size_file = ltrim( (string) $size['file'], '/' );
                        // WP pot guardar basename (normal) o subpath complet (casos puntuals/legacy).
                        if ( strpos( $size_file, '/' ) !== false ) {
                            $registered[ wp_normalize_path( $base_norm . $size_file ) ] = true;
                        } else {
                            $registered[ wp_normalize_path( $thumb_dir . $size_file ) ] = true;
                        }
                    }
                }
            }

            // Alguns previews de PDF queden a camps extra de metadata.
            if ( ! empty( $meta['original_image'] ) ) {
                $registered[ wp_normalize_path( $thumb_dir . $meta['original_image'] ) ] = true;
            }

            // Fallback explícit per previews de PDF: base "-pdf.jpg".
            // Només el marquem com registrat quan hi ha metadata de derivats del PDF.
            $attached_ext = strtolower( pathinfo( $attached_rel, PATHINFO_EXTENSION ) );
            if ( 'pdf' === $attached_ext ) {
                $has_pdf_preview_meta = false;
                if ( ! empty( $meta['original_image'] ) && preg_match( '/-pdf\.[a-z0-9]+$/i', (string) $meta['original_image'] ) ) {
                    $has_pdf_preview_meta = true;
                } elseif ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                    foreach ( $meta['sizes'] as $size ) {
                        if ( ! empty( $size['file'] ) && preg_match( '/-pdf(?:-\d+x\d+)?\.[a-z0-9]+$/i', (string) $size['file'] ) ) {
                            $has_pdf_preview_meta = true;
                            break;
                        }
                    }
                }

                if ( $has_pdf_preview_meta ) {
                    $pdf_preview_base = pathinfo( (string) $attached_rel, PATHINFO_FILENAME ) . '-pdf.jpg';
                    $registered[ wp_normalize_path( $thumb_dir . $pdf_preview_base ) ] = true;
                }
            }
        }

        // Backups TSO (els excloem del "no registrat" perquè ja els classifiquem per patró)
        $backups = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_tso_im_backup_file'
             AND meta_value != ''"
        );
        foreach ( (array) $backups as $path ) {
            $registered[ wp_normalize_path( $path ) ] = true;
        }

        return $registered;
    }

    private static function is_pdf_preview_filename( $filename ) {
        return (bool) preg_match( '/-pdf(?:-\d+x\d+)?\.(jpg|jpeg|png|webp)$/i', (string) $filename );
    }

    private static function get_pdf_preview_parent_index() {
        global $wpdb;

        $index = array();
        $rows  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             AND meta_value LIKE '%.pdf'"
        );

        foreach ( (array) $rows as $rel_pdf ) {
            $rel_pdf = str_replace( '\\', '/', ltrim( (string) $rel_pdf, '/' ) );
            if ( '' === $rel_pdf ) {
                continue;
            }
            $dir  = dirname( $rel_pdf );
            $dir  = ( '.' === $dir ) ? '' : $dir;
            $base = pathinfo( $rel_pdf, PATHINFO_FILENAME );
            if ( '' === $base ) {
                continue;
            }
            $index[ $dir . '|' . $base ] = true;
        }

        return $index;
    }

    private static function has_pdf_preview_parent( $norm_path, $base_path, $pdf_preview_parents ) {
        $rel_path = ltrim( str_replace( wp_normalize_path( $base_path ), '', wp_normalize_path( $norm_path ) ), '/' );
        $rel_path = str_replace( '\\', '/', $rel_path );

        $dir      = dirname( $rel_path );
        $dir      = ( '.' === $dir ) ? '' : $dir;
        $filename = basename( $rel_path );
        $base     = preg_replace( '/-pdf(?:-\d+x\d+)?\.[a-z0-9]+$/i', '', $filename );
        if ( '' === $base || $base === $filename ) {
            return false;
        }

        return ! empty( $pdf_preview_parents[ $dir . '|' . $base ] );
    }

    /**
     * Escaneja recursivament un directori i retorna tots els fitxers.
     */
    private static function scan_directory( $dir ) {
        $files = array();
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() ) {
                    $files[] = $file->getPathname();
                }
            }
        } catch ( Exception $e ) {
            // Directori no accessible
        }
        return $files;
    }
}
