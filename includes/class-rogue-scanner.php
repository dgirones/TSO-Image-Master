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

            // 1. Comprovar patrons coneguts
            foreach ( self::$known_patterns as $pattern => $code ) {
                if ( preg_match( $pattern, $filename ) ) {
                    $reason_code = $code;
                    $reason      = self::reason_label_from_code( $code );
                    $priority    = in_array( $code, array( 'double_extension', 'tso_temp' ), true ) ? 'high' : 'normal';
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
                    'path'        => self::safe_utf8( $rel_path ),
                    'path_b64'    => base64_encode( $full_path ),
                    'filename'    => self::safe_utf8( $filename ),
                    'reason'      => $reason,
                    'reason_code' => $reason_code,
                    'priority'    => $priority,
                    'size'        => $size,
                    'size_h'      => size_format( $size ),
                    'url'         => $base_url . self::safe_utf8( $rel_path ),
                    'mtime'       => (int) filemtime( $full_path ),
                    'date'        => gmdate( 'd/m/Y H:i', filemtime( $full_path ) ),
                );
            }
        }

        // Ordenar per data de modificació (més recent primer).
        usort( $rogue, function( $a, $b ) {
            $mtime_cmp = ( $b['mtime'] ?? 0 ) <=> ( $a['mtime'] ?? 0 );
            if ( 0 !== $mtime_cmp ) {
                return $mtime_cmp;
            }
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
        return isset( $labels[ $code ] ) ? $labels[ $code ] : 'Fitxer extra';
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
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key IN (%s, %s)
                 AND meta_value != ''",
                tsoimma_get_attachment_meta_key( 'backup_file' ),
                tsoimma_get_attachment_meta_key_legacy( 'backup_file' )
            )
        );
        foreach ( (array) $backups as $path ) {
            if ( $path && file_exists( $path ) ) {
                $registered[ wp_normalize_path( $path ) ] = true;
            }
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

    /**
     * Transient TTL for rogue delete allowlist (per admin user).
     */
    const DELETE_ALLOWLIST_TTL = DAY_IN_SECONDS;

    /**
     * @param int $user_id User ID.
     * @return string
     */
    private static function delete_allowlist_transient_key( $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        return 'tsoimma_rogue_del_' . $user_id;
    }

    /**
     * @return array{expires:int,count:int}
     */
    public static function get_delete_allowlist_info( $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        if ( ! $user_id ) {
            return array(
                'expires' => 0,
                'count'   => 0,
            );
        }

        $key     = self::delete_allowlist_transient_key( $user_id );
        $allowed = get_transient( $key );
        $timeout = (int) get_option( '_transient_timeout_' . $key, 0 );

        return array(
            'expires' => $timeout,
            'count'   => is_array( $allowed ) ? count( $allowed ) : 0,
        );
    }

    /**
     * Remember absolute paths from the latest scan so delete AJAX cannot target arbitrary uploads files.
     *
     * @param array<string, mixed> $scan_result Result from scan().
     * @return void
     */
    public static function store_delete_allowlist_from_scan( $scan_result ) {
        $user_id = get_current_user_id();
        if ( ! $user_id || ! is_array( $scan_result ) ) {
            return;
        }

        $allowed = array();
        foreach ( (array) ( $scan_result['files'] ?? array() ) as $file ) {
            if ( empty( $file['path_b64'] ) ) {
                continue;
            }
            $norm = self::normalize_uploads_file_path_from_b64( (string) $file['path_b64'] );
            if ( '' !== $norm ) {
                $allowed[ $norm ] = true;
            }
        }

        set_transient(
            self::delete_allowlist_transient_key( $user_id ),
            $allowed,
            self::DELETE_ALLOWLIST_TTL
        );
    }

    /**
     * @param string $resolved_real_path Absolute path after realpath() inside uploads.
     * @return bool
     */
    public static function is_resolved_path_in_delete_allowlist( $resolved_real_path ) {
        $user_id = get_current_user_id();
        if ( ! $user_id || '' === (string) $resolved_real_path ) {
            return false;
        }

        $allowed = get_transient( self::delete_allowlist_transient_key( $user_id ) );
        if ( ! is_array( $allowed ) ) {
            return false;
        }

        $norm = wp_normalize_path( (string) $resolved_real_path );
        return ! empty( $allowed[ $norm ] );
    }

    /**
     * @param string $path_b64 Base64-encoded absolute filesystem path from scan().
     * @return string Normalized real path, or empty when invalid.
     */
    private static function normalize_uploads_file_path_from_b64( $path_b64 ) {
        $abs_path = base64_decode( sanitize_text_field( (string) $path_b64 ), true );
        if ( false === $abs_path ) {
            return '';
        }

        return self::normalize_uploads_file_path( $abs_path );
    }

    /**
     * Resolve a candidate uploads file path (same rules as rogue delete AJAX).
     *
     * @param string $abs_path Candidate absolute path.
     * @return string Normalized real path, or empty when not allowed.
     */
    public static function normalize_uploads_file_path( $abs_path ) {
        $abs_path = (string) $abs_path;
        if ( '' === $abs_path ) {
            return '';
        }

        $upload_dir = wp_upload_dir();
        $base_path  = trailingslashit( $upload_dir['basedir'] );
        $real_base  = realpath( $base_path );
        if ( false === $real_base ) {
            return '';
        }

        $real_path = realpath( $abs_path );
        if ( false === $real_path || ! is_file( $real_path ) ) {
            return '';
        }

        $norm_base = wp_normalize_path( $real_base ) . '/';
        $norm_file = wp_normalize_path( $real_path );
        if ( 0 !== strpos( $norm_file, $norm_base ) ) {
            return '';
        }

        return $norm_file;
    }
}
