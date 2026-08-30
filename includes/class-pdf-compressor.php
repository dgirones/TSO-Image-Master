<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_PDF_Compressor {

    /**
     * Comprimeix un fitxer PDF adjunt de WordPress.
     *
     * @param int $attachment_id
     * @param int $quality  72 | 96 | 150 | 300 (DPI per a GhostScript) o 1–100 (Imagick)
     * @param bool $replace  Reemplaça l'original
     * @return array|WP_Error
     */
    public static function compress( $attachment_id, $quality = 96, $replace = true ) {
        $attachment_id = absint( $attachment_id );
        $file_path     = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'not_found', 'Fitxer PDF no trobat.' );
        }

        $mime = mime_content_type( $file_path );
        if ( $mime !== 'application/pdf' ) {
            return new WP_Error( 'not_pdf', 'El fitxer no és un PDF.' );
        }

        // Tall ràpid: evitar processos llargs quan ja sabem que fallarà o no aportarà valor.
        if ( self::is_pdf_encrypted( $file_path ) ) {
            return new WP_Error( 'pdf_protected', 'Aquest PDF està protegit/encriptat i no es pot comprimir automàticament.' );
        }
        if ( tsoimma_get_attachment_meta( $attachment_id, 'pdf_compressed' ) ) {
            return new WP_Error( 'already_compressed', 'Aquest PDF ja consta com a comprimit. Evitem recomprimir per no perdre qualitat.' );
        }

        $original_size = filesize( $file_path );
        $pi            = pathinfo( $file_path );
        $temp_path     = $pi['dirname'] . '/' . $pi['filename'] . '_tso_im_compressed.pdf';

        // Intentar amb GhostScript primer (millors resultats)
        $result = self::try_ghostscript( $file_path, $temp_path, $quality );

        // Fallback a Imagick si GS no disponible
        if ( is_wp_error( $result ) && class_exists( 'Imagick' ) ) {
            $result = self::try_imagick( $file_path, $temp_path, $quality );
        }

        if ( is_wp_error( $result ) ) {
            wp_delete_file( $temp_path );
            return $result;
        }

        if ( ! file_exists( $temp_path ) ) {
            return new WP_Error( 'compress_failed', 'No s\'ha generat el fitxer comprimit.' );
        }

        $new_size = filesize( $temp_path );

        // Si el comprimit és més gran, descartar
        if ( $new_size >= $original_size ) {
            wp_delete_file( $temp_path );
            return new WP_Error( 'no_gain', 'El PDF comprimit és igual o més gran que l\'original. Ja estava optimitzat.' );
        }

        $output = [
            'attachment_id' => $attachment_id,
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'savings_bytes' => $original_size - $new_size,
            'savings_pct'   => round( ( 1 - $new_size / $original_size ) * 100, 1 ),
            'method'        => $result,
            'replaced'      => false,
        ];

        if ( $replace ) {
            // Reemplaçar l'original
            if ( ! @copy( $temp_path, $file_path ) ) {
                wp_delete_file( $temp_path );
                return new WP_Error( 'replace_failed', "No s'ha pogut reemplaçar el PDF original. Verifica permisos." );
            }
            wp_delete_file( $temp_path );

            clearstatcache( true, $file_path );

            $output['replaced']  = true;
            $output['new_size']  = filesize( $file_path );
            $output['url']       = wp_get_attachment_url( $attachment_id );

            // Actualitzar filesize a _wp_attachment_metadata (WP 6.0+)
            $meta = wp_get_attachment_metadata( $attachment_id );
            if ( is_array( $meta ) ) {
                $meta['filesize'] = $output['new_size'];
                wp_update_attachment_metadata( $attachment_id, $meta );
            }

            clean_attachment_cache( $attachment_id );
            wp_cache_delete( $attachment_id, 'posts' );
        } else {
            $output['compressed_path'] = $temp_path;
        }

        return $output;
    }

    /**
     * Retorna els PDFs de la biblioteca de medis.
     */
    public static function get_pdfs( $page = 1, $per_page = 30, $search = '' ) {
        $search = trim( (string) $search );
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'application/pdf',
            'post_status'    => 'inherit',
            'posts_per_page' => ( $search !== '' ? -1 : $per_page ),
            'paged'          => ( $search !== '' ? 1 : $page ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        // IMPORTANT: no usem $args['s'] per evitar coincidències laxes de WP amb diacrítics.

        $query = new WP_Query( $args );
        $items = [];

        foreach ( $query->posts as $post ) {
            $file  = get_attached_file( $post->ID );
            $size  = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
            $non_compressible = tsoimma_get_attachment_meta( $post->ID, 'pdf_non_compressible' );
            if ( ! is_array( $non_compressible ) ) {
                $non_compressible = array();
            }
            $items[] = [
                'id'          => $post->ID,
                'title'       => $post->post_title,
                'filename'    => $file ? basename( $file ) : '',
                'url'         => wp_get_attachment_url( $post->ID ),
                'filesize'    => $size > 0 ? size_format( $size ) : '—',
                'filesize_raw'=> $size,
                'date'        => get_the_date( 'd/m/Y', $post->ID ),
                'compressed'  => (bool) tsoimma_get_attachment_meta( $post->ID, 'pdf_compressed' ),
                'non_compressible' => ! empty( $non_compressible ),
                'non_compressible_reason' => isset( $non_compressible['message'] ) ? (string) $non_compressible['message'] : '',
                'non_compressible_at' => isset( $non_compressible['timestamp'] ) ? (int) $non_compressible['timestamp'] : 0,
            ];
        }

        // Cerca estricte UTF-8 per prefix (respecta ñ vs n).
        if ( $search !== '' ) {
            $items = array_values( array_filter( $items, function( $item ) use ( $search ) {
                $base_filename = pathinfo( (string) $item['filename'], PATHINFO_FILENAME );
                return self::starts_with_utf8( $base_filename, $search )
                    || self::starts_with_utf8( (string) $item['title'], $search );
            } ) );
        }

        // Ordenar per pes
        usort( $items, function( $a, $b ) { return $b['filesize_raw'] - $a['filesize_raw']; } );

        if ( $search !== '' ) {
            $total       = count( $items );
            $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
            $offset      = ( $page - 1 ) * $per_page;
            $items       = array_slice( $items, $offset, $per_page );
        } else {
            $total       = (int) $query->found_posts;
            $total_pages = (int) $query->max_num_pages;
        }

        return [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => $page,
            'gs_available'=> self::ghostscript_available(),
            'imagick_available' => class_exists('Imagick'),
        ];
    }

    /**
     * Case-insensitive UTF-8 prefix check without accent folding.
     */
    private static function starts_with_utf8( $haystack, $needle ) {
        $haystack = (string) $haystack;
        $needle   = (string) $needle;
        if ( '' === $needle ) {
            return true;
        }
        if ( function_exists( 'mb_stripos' ) ) {
            return mb_stripos( $haystack, $needle, 0, 'UTF-8' ) === 0;
        }
        return 0 === strncasecmp( $haystack, $needle, strlen( $needle ) );
    }

    // ── Mètodes de compressió ────────────────────────────────────────

    /**
     * @param bool $background  Si true, executa GhostScript en background (& al final)
     *                          i retorna immediatament sense esperar el resultat.
     */
    private static function try_ghostscript( $input, $output, $dpi, $background = false, $settings = '/ebook' ) {
        if ( ! self::ghostscript_available() ) {
            return new WP_Error( 'no_gs', 'GhostScript no disponible.' );
        }
        $gs  = self::ghostscript_binary();
        $dpi = in_array( (int)$dpi, [ 72, 96, 150, 300 ] ) ? (int)$dpi : 96;

        if ( $background ) {
            // Mode background: GhostScript s'executa independent de PHP.
            // $settings controla la qualitat: '/ebook' (default), '/screen', '/default'.
            // '/default' és el més compatible: ideal per PDFs que /ebook no pot comprimir.
            $cmd = sprintf(
                '%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=%s ' .
                '-dNOPAUSE -dQUIET -dBATCH -r%d ' .
                '-sOutputFile=%s %s > /dev/null 2>&1 &',
                escapeshellcmd( $gs ),
                escapeshellarg( $settings ),
                $dpi,
                escapeshellarg( $output ),
                escapeshellarg( $input )
            );
            exec( $cmd );  // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            return 'GhostScript_bg';
        }

        // Mode síncron (thumbnails, etc.)
        $cmd = sprintf(
            '%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook ' .
            '-dNOPAUSE -dQUIET -dBATCH -r%d ' .
            '-sOutputFile=%s %s 2>&1',
            escapeshellcmd( $gs ),
            $dpi,
            escapeshellarg( $output ),
            escapeshellarg( $input )
        );

        exec( $cmd, $out, $code );  // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        if ( $code !== 0 ) {
            return new WP_Error( 'gs_failed', 'Error GhostScript: ' . implode( ' ', $out ) );
        }
        return 'GhostScript';
    }

    private static function try_imagick( $input, $output, $quality ) {
        try {
            $imagick = new Imagick();
            $imagick->setResolution( 150, 150 );
            $imagick->readImage( $input );
            $imagick->setImageCompressionQuality( min( 100, max( 10, $quality ) ) );
            $imagick->setFormat( 'pdf' );
            $imagick->writeImages( $output, true );
            $imagick->clear();
            $imagick->destroy();
            return 'Imagick';
        } catch ( Exception $e ) {
            return new WP_Error( 'imagick_failed', 'Error Imagick: ' . $e->getMessage() );
        }
    }

    /**
     * Inicia la compressió en background (GhostScript amb &).
     * Retorna immediatament. Usar poll_status() per saber quan acaba.
     *
     * @return array|WP_Error  Array amb 'temp_path' i 'status'=>'processing'
     */
    public static function compress_background( $attachment_id, $quality = 96 ) {
        $attachment_id = absint( $attachment_id );
        $file_path     = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'not_found', 'Fitxer PDF no trobat.' );
        }
        if ( mime_content_type( $file_path ) !== 'application/pdf' ) {
            return new WP_Error( 'not_pdf', 'El fitxer no és un PDF.' );
        }
        if ( self::is_pdf_encrypted( $file_path ) ) {
            return new WP_Error( 'pdf_protected', 'Aquest PDF està protegit/encriptat i no es pot comprimir automàticament.' );
        }
        if ( tsoimma_get_attachment_meta( $attachment_id, 'pdf_compressed' ) ) {
            return new WP_Error( 'already_compressed', 'Aquest PDF ja consta com a comprimit. Evitem recomprimir per no perdre qualitat.' );
        }
        if ( ! self::ghostscript_available() ) {
            return new WP_Error( 'no_gs', 'GhostScript no disponible.' );
        }

        $pi        = pathinfo( $file_path );
        $temp_path = $pi['dirname'] . '/' . $pi['filename'] . '_tso_im_compressed.pdf';

        // Eliminar temp antic si existeix
        if ( file_exists( $temp_path ) ) {
            wp_delete_file( $temp_path );
        }

        // Guardar info per al polling
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_temp',     $temp_path );
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_original', $file_path );
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_size',     filesize( $file_path ) );
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_quality',  $quality );
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_settings', '/ebook' );
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_started',  time() );
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_fallback_tried', 0 );
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_status',      'processing' );

        // Llançar GhostScript en background amb configuració /ebook (màxima compressió)
        self::try_ghostscript( $file_path, $temp_path, $quality, true, '/ebook' );

        return array(
            'status'    => 'processing',
            'temp_path' => $temp_path,
        );
    }

    /**
     * Comprova si la compressió background ha acabat.
     * Quan acaba, aplica el resultat i retorna les dades.
     */
    public static function poll_status( $attachment_id, $replace = true ) {
        $attachment_id = absint( $attachment_id );
        $status        = tsoimma_get_attachment_meta( $attachment_id, 'pdf_status' );

        if ( $status !== 'processing' ) {
            return array( 'status' => $status ?: 'idle' );
        }

        $temp_path     = tsoimma_get_attachment_meta( $attachment_id, 'pdf_bg_temp' );
        $original_path = tsoimma_get_attachment_meta( $attachment_id, 'pdf_bg_original' );
        $original_size = (int) tsoimma_get_attachment_meta( $attachment_id, 'pdf_bg_size' );
        $quality       = (int) tsoimma_get_attachment_meta( $attachment_id, 'pdf_bg_quality' );
        $started_at    = (int) tsoimma_get_attachment_meta( $attachment_id, 'pdf_bg_started' );
        $fallback_tried = (int) tsoimma_get_attachment_meta( $attachment_id, 'pdf_bg_fallback_tried' );
        $gs_settings    = (string) tsoimma_get_attachment_meta( $attachment_id, 'pdf_bg_settings' );

        // Fitxer temp no existeix encara
        if ( ! $temp_path || ! file_exists( $temp_path ) ) {
            // Fallback robust: si GhostScript en background no genera sortida després d'un temps
            // raonable, provar Imagick una sola vegada abans de declarar timeout al client.
            $elapsed = $started_at > 0 ? ( time() - $started_at ) : 0;
            if (
                $temp_path
                && $original_path
                && $elapsed >= 90
                && ! $fallback_tried
                && class_exists( 'Imagick' )
            ) {
                tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_fallback_tried', 1 );
                $fallback = self::try_imagick( $original_path, $temp_path, $quality );
                if ( is_wp_error( $fallback ) ) {
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_temp' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_original' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_size' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_quality' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_prev_size' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_settings' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_started' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_fallback_tried' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_status' );
                    return $fallback;
                }
                // Deixar que el flux normal validi/apliqui el fitxer al següent bloc.
                clearstatcache( true, $temp_path );
            }

            if ( ! file_exists( $temp_path ) ) {
                // Tall net: no deixem polling infinit. Als 2 minuts retornem error explícit.
                if ( $elapsed >= 120 ) {
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_temp' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_original' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_size' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_quality' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_prev_size' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_settings' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_started' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_fallback_tried' );
                    tsoimma_delete_attachment_meta( $attachment_id, 'pdf_status' );
                    $timeout_msg = 'La compressió ha superat el temps límit (120s). Revisa GhostScript al servidor.';
                    if ( class_exists( 'Imagick' ) ) {
                        $timeout_msg .= $fallback_tried
                            ? ' També s\'ha intentat fallback amb Imagick.'
                            : ' Imagick està disponible però no s\'ha pogut completar el fallback.';
                    }
                    return new WP_Error(
                        'pdf_timeout',
                        $timeout_msg
                    );
                }
                return array( 'status' => 'processing' );
            }
        }

        // ── Detecció ràpida: 1 poll estable >= 10 KB ────────────────────
        // Polling simple i ràpid — igual que l'original.
        // La validació del fitxer es fa EN EL MOMENT D'APLICAR-LO (veure més avall),
        // no aquí, per evitar retards innecessaris durant el polling.
        clearstatcache( true, $temp_path );
        $current_size = filesize( $temp_path );

        if ( $current_size < 10240 ) {
            return array( 'status' => 'processing' );
        }

        $prev_size = (int) tsoimma_get_attachment_meta( $attachment_id, 'pdf_bg_prev_size' );
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_prev_size', $current_size );

        if ( $current_size !== $prev_size ) {
            return array( 'status' => 'processing' );
        }

        // Mida estable → GhostScript ha acabat. Validem ABANS d'aplicar.
        $new_size = $current_size;

        // Netejar meta de background
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_temp' );
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_original' );
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_size' );
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_quality' );
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_prev_size' );
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_settings' );
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_started' );
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_bg_fallback_tried' );
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_status' );

        if ( $new_size >= $original_size ) {
            wp_delete_file( $temp_path );
            return new WP_Error( 'no_gain', 'El PDF comprimit és igual o més gran. Ja estava optimitzat.' );
        }

        $result = array(
            'attachment_id' => $attachment_id,
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'savings_bytes' => $original_size - $new_size,
            'savings_pct'   => round( ( 1 - $new_size / $original_size ) * 100, 1 ),
            'method'        => 'GhostScript',
            'replaced'      => false,
        );

        if ( $replace && $original_path ) {
            // ── Validació del fitxer comprimit ABANS d'aplicar ──────────
            // Fem la validació aquí (no al polling) per mantenir el polling ràpid.
            // ── Validació d'integritat del PDF comprimit ────────────
            // Un PDF complet SEMPRE comença amb %PDF i acaba amb %%EOF.
            // Mida mínima absoluta: 1 KB (fins i tot un PDF buit és > 1 KB).
            // NO usem % de l'original: PDFs de text pur poden comprimir-se
            // dràsticament (ex: 5 MB → 20 KB) i seria un resultat vàlid.

            // Capçalera: primers 4 bytes han de ser %PDF
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $pdf_header = @file_get_contents( $temp_path, false, null, 0, 4 );
            $header_ok  = ( false !== $pdf_header && '%PDF' === $pdf_header );

            // Final: últims 32 bytes han de contenir %%EOF (PDF complet)
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $pdf_tail = @file_get_contents( $temp_path, false, null, -32 );
            $eof_ok   = ( false !== $pdf_tail && false !== strpos( $pdf_tail, '%%EOF' ) );

            // Mida absoluta mínima: 1 KB
            $size_ok = ( $new_size >= 1024 );

            if ( ! $header_ok || ! $eof_ok || ! $size_ok ) {
                wp_delete_file( $temp_path );
                $reason = '';
                if ( ! $header_ok ) $reason = 'capçalera %PDF absent';
                elseif ( ! $eof_ok ) $reason = 'marcador %%EOF absent (fitxer truncat)';
                elseif ( ! $size_ok ) $reason = 'fitxer buit';

                // ── Retry automàtic amb /default si el primer intent (/ebook) falla
                if ( '/ebook' === $gs_settings || '' === $gs_settings ) {
                    tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_settings', '/default' );
                    tsoimma_update_attachment_meta( $attachment_id, 'pdf_bg_prev_size', 0 );
                    tsoimma_update_attachment_meta( $attachment_id, 'pdf_status', 'processing' );
                    self::try_ghostscript( $original_path, $temp_path, $quality, true, '/default' );
                    return array( 'status' => 'processing' );
                }

                // Segon intent (/default) també ha fallat
                return new WP_Error(
                    'corrupt_pdf',
                    'GhostScript no pot comprimir aquest PDF (' . $reason . '). '
                    . 'El fitxer original NO ha estat modificat.'
                );
            }

            if ( ! @copy( $temp_path, $original_path ) ) {
                wp_delete_file( $temp_path );
                return new WP_Error( 'replace_failed', "No s'ha pogut reemplaçar el PDF original. Verifica permisos." );
            }
            wp_delete_file( $temp_path );

            // Esborrar la cache interna de PHP per a filesize() — sense això,
            // PHP retorna la mida antiga fins que el procés acaba.
            clearstatcache( true, $original_path );

            $result['replaced'] = true;
            $result['new_size'] = filesize( $original_path ); // mida real post-compressió
            $result['url']      = wp_get_attachment_url( $attachment_id );

            // Actualitzar el camp 'filesize' dins _wp_attachment_metadata.
            // WordPress 6.0+ guarda el filesize aquí i la biblioteca de medis
            // el llegeix d'aquest camp (NO directament de filesize() al disc).
            // Sense aquesta actualització, la biblioteca sempre mostra el pes original.
            $meta = wp_get_attachment_metadata( $attachment_id );
            if ( is_array( $meta ) ) {
                $meta['filesize'] = $result['new_size'];
                wp_update_attachment_metadata( $attachment_id, $meta );
            }

            // Netejar caches de WordPress i plugins de cache
            clean_attachment_cache( $attachment_id );
            wp_cache_delete( $attachment_id, 'posts' );
            if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
            do_action( 'litespeed_purge_post', $attachment_id );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
            do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
            if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
            if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
        }

        return $result;
    }

    public static function ghostscript_available() {
        $bin = self::ghostscript_binary();
        if ( ! $bin ) return false;
        exec( $bin . ' --version 2>&1', $out, $code );  // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        return $code === 0;
    }

    private static function ghostscript_binary() {
        // 'which' works on Linux/macOS; 'where' on Windows.
        $find_cmd = ( DIRECTORY_SEPARATOR === '\\' ) ? 'where' : 'which';
        foreach ( array( 'gs', 'gswin64c', 'gswin32c' ) as $bin ) {
            $out  = array();
            $code = 0;
            exec( $find_cmd . ' ' . escapeshellarg( $bin ) . ' 2>&1', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            if ( 0 === $code && ! empty( $out[0] ) ) return trim( $out[0] );
        }
        return '';
    }

    /**
     * Best-effort detection for encrypted/password-protected PDFs.
     * Uses qpdf when available; otherwise checks /Encrypt marker in first MB.
     */
    private static function is_pdf_encrypted( $file_path ) {
        $qpdf = self::qpdf_binary();
        if ( $qpdf ) {
            $out  = array();
            $code = 0;
            $cmd  = sprintf(
                '%s --show-encryption %s 2>&1',
                escapeshellcmd( $qpdf ),
                escapeshellarg( $file_path )
            );
            exec( $cmd, $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            if ( 0 === $code ) {
                $txt = strtolower( implode( "\n", $out ) );
                return ( false === strpos( $txt, 'not encrypted' ) );
            }
        }

        // Fallback lightweight check.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $head = @file_get_contents( $file_path, false, null, 0, 1024 * 1024 );
        if ( ! is_string( $head ) || '' === $head ) {
            return false;
        }
        return (bool) preg_match( '/\/Encrypt\b/', $head );
    }

    private static function qpdf_binary() {
        $find_cmd = ( DIRECTORY_SEPARATOR === '\\' ) ? 'where' : 'which';
        $out      = array();
        $code     = 0;
        exec( $find_cmd . ' ' . escapeshellarg( 'qpdf' ) . ' 2>&1', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        if ( 0 === $code && ! empty( $out[0] ) ) {
            return trim( $out[0] );
        }
        return '';
    }

    /**
     * Stores non-compressible status so we can avoid repeating expensive attempts.
     */
    public static function mark_non_compressible( $attachment_id, $code, $message ) {
        tsoimma_update_attachment_meta( $attachment_id, 'pdf_non_compressible', array(
            'code'      => sanitize_key( (string) $code ),
            'message'   => sanitize_text_field( (string) $message ),
            'timestamp' => time(),
        ) );
    }

    /**
     * Clears previous non-compressible status after successful compression.
     */
    public static function clear_non_compressible( $attachment_id ) {
        tsoimma_delete_attachment_meta( $attachment_id, 'pdf_non_compressible' );
    }
}
