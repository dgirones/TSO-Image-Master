<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_Optimizer {

    /**
     * FASE 1 — Conversió de fitxer (síncron, ràpid, sense DB WordPress).
     * Converteix, redimensiona, fa backup físic.
     * Retorna array de resultat o WP_Error.
     */
    public static function optimize(
        $attachment_id,
        $output_format = 'webp',
        $quality       = 82,
        $replace       = true,
        $max_width     = 0,
        $max_height    = 0,
        $make_backup   = true
    ) {
        $attachment_id   = absint( $attachment_id );
        $output_format   = self::normalize_output_format( $output_format );
        $quality         = min( 100, max( 1, absint( $quality ) ) );
        $file_path       = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Fitxer no trobat.' );
        }

        $mime = mime_content_type( $file_path );
        if ( strpos( $mime, 'image/' ) === false ) {
            return new WP_Error( 'not_image', 'El fitxer no és una imatge.' );
        }

        $original_size = filesize( $file_path );
        $path_info     = pathinfo( $file_path );
        $old_ext       = strtolower( $path_info['extension'] );

        // Defensiu: si el 'filename' conté una extensió d'imatge embeguda
        // (ex: 'foto.png' quan el fitxer real és 'foto.png.webp'),
        // eliminar-la per evitar crear 'foto.png.webp' en lloc de 'foto.webp'.
        $img_exts_clean = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
        $clean_filename = $path_info['filename'];
        $inner_pi       = pathinfo( $clean_filename );
        if ( ! empty( $inner_pi['extension'] ) && in_array( strtolower( $inner_pi['extension'] ), $img_exts_clean, true ) ) {
            $clean_filename = $inner_pi['filename'];
        }
        $path_info['filename'] = $clean_filename;

        // Remove leftover temp files from a previous failed conversion attempt.
        self::cleanup_stale_opt_temp_files( $path_info['dirname'], $path_info['filename'] );

        if ( 'image/gif' === $mime && self::is_animated_gif( $file_path ) ) {
            return new WP_Error(
                'animated_gif',
                'Els GIF animats no es poden convertir. Puja un GIF estàtic o desactiva la conversió per a GIF.'
            );
        }

        $image = self::load_image( $file_path, $mime );
        if ( ! $image ) {
            return new WP_Error(
                'load_failed',
                self::get_load_error_message( $mime, $old_ext )
            );
        }

        // ── Redimensionar si cal ─────────────────────────────────────
        $max_width  = absint( $max_width );
        $max_height = absint( $max_height );
        $orig_w     = imagesx( $image );
        $orig_h     = imagesy( $image );
        $resized    = false;
        $new_w      = $orig_w;
        $new_h      = $orig_h;

        if ( $max_width > 0 || $max_height > 0 ) {
            if ( $max_width > 0 && $max_height > 0 ) {
                $ratio = min( $max_width / $orig_w, $max_height / $orig_h );
            } elseif ( $max_width > 0 ) {
                $ratio = $max_width / $orig_w;
            } else {
                $ratio = $max_height / $orig_h;
            }
            if ( $ratio < 1.0 ) {
                $new_w       = max( 1, (int) round( $orig_w * $ratio ) );
                $new_h       = max( 1, (int) round( $orig_h * $ratio ) );
                $resized_img = imagecreatetruecolor( $new_w, $new_h );
                imagealphablending( $resized_img, false );
                imagesavealpha( $resized_img, true );
                $transparent = imagecolorallocatealpha( $resized_img, 0, 0, 0, 127 );
                imagefilledrectangle( $resized_img, 0, 0, $new_w, $new_h, $transparent );
                imagecopyresampled( $resized_img, $image, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
                imagedestroy( $image );
                $image   = $resized_img;
                $resized = true;
            }
        }

        $new_ext  = self::get_extension( $output_format, $old_ext );
        $new_mime = self::ext_to_mime( $new_ext );

        // Guardar fitxer temporal (flatten alpha when targeting JPEG).
        $temp_path = $path_info['dirname'] . '/' . $path_info['filename'] . '_tso_im_opt.' . $new_ext;
        if ( ! self::save_image_resource_to_path( $image, $temp_path, $new_ext, $quality ) ) {
            self::cleanup_stale_opt_temp_files( $path_info['dirname'], $path_info['filename'] );
            $detail = 'webp' === $new_ext && ! self::webp_supported()
                ? ' El servidor no te suport WebP (GD).'
                : '';
            return new WP_Error(
                'save_failed',
                'No s\'ha pogut guardar la imatge optimitzada.' . $detail
            );
        }

        if ( ! self::is_valid_image_file( $temp_path ) ) {
            self::delete_file_if_exists( $temp_path );
            return new WP_Error( 'save_failed', 'No s\'ha pogut guardar la imatge optimitzada.' );
        }

        clearstatcache( true, $temp_path );
        $new_size = filesize( $temp_path );

        // Si és més gran i no hem de reemplaçar, descartар
        if ( $new_size >= $original_size && ! $replace ) {
            wp_delete_file( $temp_path );
            return new WP_Error( 'no_improvement', 'La imatge optimitzada no és més lleugera que l\'original.' );
        }

        $result = array(
            'attachment_id' => $attachment_id,
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'savings_bytes' => max( 0, $original_size - $new_size ),
            'savings_pct'   => $original_size > 0 ? round( ( 1 - $new_size / $original_size ) * 100, 1 ) : 0,
            'format'        => $new_ext,
            'replaced'      => false,
            'resized'       => $resized,
            'orig_w'        => $orig_w,
            'orig_h'        => $orig_h,
            'new_w'         => $new_w,
            'new_h'         => $new_h,
            'old_ext'       => strtolower( $old_ext ),
            'new_ext'       => strtolower( $new_ext ),
            'new_mime'      => $new_mime,
            'quality'       => absint( $quality ),
        );

        if ( ! $replace ) {
            $result['optimized_path'] = $temp_path;
            return $result;
        }

        // ── Operacions de fitxer: moure temp→final i fer backup ──────
        $upload_dir   = wp_upload_dir();
        $final_path   = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $new_ext;
        $old_path     = $file_path;
        // Store backup in plugin-specific uploads subdirectory (WP.org guideline).
        $backup_path  = self::get_backup_path( $old_path, strtolower( $old_ext ) );

        // Backup silenciós — només si $make_backup és true, després de validar el temp.
        if ( $make_backup ) {
        if ( ! self::copy_file_validated( $old_path, $backup_path ) ) {
            self::delete_file_if_exists( $temp_path );
            self::prune_empty_backup_dirs( $backup_path );
            return new WP_Error(
                'backup_failed',
                'No s\'ha pogut crear la còpia de seguretat. No s\'ha modificat l\'original.'
            );
        }
        }

        if ( ! self::copy_file_validated( $temp_path, $final_path ) ) {
            self::delete_file_if_exists( $temp_path );
            self::delete_backup_file( $backup_path );
            return new WP_Error( 'move_failed', 'No s\'ha pogut escriure el fitxer final. Verifica permisos.' );
        }

        self::delete_file_if_exists( $temp_path );

        // Eliminar original (i variant -scaled de WordPress) si l'extensió ha canviat.
        if ( ! self::extensions_match( $old_ext, $new_ext ) ) {
            if ( file_exists( $old_path ) ) {
                wp_delete_file( $old_path );
            }
            self::delete_scaled_variant_if_exists( $path_info['dirname'], $path_info['filename'], $old_ext );
        }

        clearstatcache( true, $final_path );
        $new_size = filesize( $final_path );

        // Calcular URLs desde paths (sense consultar DB)
        $basedir_norm = wp_normalize_path( $upload_dir['basedir'] );
        $rel_new      = ltrim( str_replace( $basedir_norm, '', wp_normalize_path( $final_path ) ), '/' );
        $rel_old      = ltrim( str_replace( $basedir_norm, '', wp_normalize_path( $old_path ) ), '/' );
        $new_url      = trailingslashit( $upload_dir['baseurl'] ) . self::encode_rel_path_for_url( $rel_new );
        $old_url      = trailingslashit( $upload_dir['baseurl'] ) . self::encode_rel_path_for_url( $rel_old );

        $result['new_size']      = $new_size;
        $result['savings_bytes'] = max( 0, $original_size - $new_size );
        $result['savings_pct']   = $original_size > 0 ? round( ( 1 - $new_size / $original_size ) * 100, 1 ) : 0;
        $result['new_w']         = $new_w;
        $result['new_h']         = $new_h;
        $result['replaced']      = true;
        $result['old_url']       = $old_url;
        $result['new_url']       = $new_url;
        $result['new_path']      = $final_path;
        $result['old_path']      = $old_path;
        $result['backup_path']   = file_exists( $backup_path ) ? $backup_path : '';
        $result['has_backup']    = file_exists( $backup_path );
        $result['backup_size']   = file_exists( $backup_path ) ? filesize( $backup_path ) : 0;

        return $result;
    }

    /**
     * FASE 2 — Actualitzar metadata de WordPress (DB writes segurs).
     * Crida des del handler AJAX just després de optimize().
     * NO fa thumbnails (van al cron).
     */
    public static function update_wp_metadata_only( $attachment_id, $result, $format ) {
        $attachment_id = absint( $attachment_id );
        $new_path      = $result['new_path'];
        $old_url       = $result['old_url'];
        $new_url       = $result['new_url'];
        $new_mime      = $result['new_mime'];
        $new_w         = absint( $result['new_w'] );
        $new_h         = absint( $result['new_h'] );
        $old_ext       = $result['old_ext'];
        $new_ext       = $result['new_ext'];
        $backup_path   = $result['backup_path'];
        $quality       = isset( $result['quality'] ) ? absint( $result['quality'] ) : 82;

        // Desar metes del backup
        if ( $backup_path && file_exists( $backup_path ) ) {
            update_post_meta( $attachment_id, '_tso_im_backup_file', $backup_path );
            update_post_meta( $attachment_id, '_tso_im_backup_mime', self::ext_to_mime( $old_ext ) );
            update_post_meta( $attachment_id, '_tso_im_backup_size', filesize( $backup_path ) );
            update_post_meta( $attachment_id, '_tso_im_backup_attached_file', self::normalize_attached_file_meta_value( get_post_meta( $attachment_id, '_wp_attached_file', true ) ) );
            update_post_meta( $attachment_id, '_tso_im_backup_current_name', pathinfo( (string) $new_path, PATHINFO_FILENAME ) );
        }

        // Actualitzar fitxer principal a WP
        update_attached_file( $attachment_id, $new_path );

        // Actualitzar metadata: fitxer, dimensions, noms de thumbnail
        $upload_dir    = wp_upload_dir();
        $basedir_norm  = wp_normalize_path( $upload_dir['basedir'] );
        $relative_path = ltrim( str_replace( $basedir_norm, '', wp_normalize_path( $new_path ) ), '/' );

        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! is_array( $meta ) ) {
            $meta = array();
        }

        $thumb_replacements = array();
        $thumb_dir          = trailingslashit( dirname( $new_path ) );
        $old_dir_url        = trailingslashit( dirname( $old_url ) );
        $new_dir_url        = trailingslashit( dirname( $new_url ) );

        // Convert existing thumbnail files on disk BEFORE updating content URLs.
        if ( ! self::extensions_match( $old_ext, $new_ext ) && ! empty( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $sz => $sz_data ) {
                $tfile = isset( $sz_data['file'] ) ? (string) $sz_data['file'] : '';
                if ( '' === $tfile ) {
                    continue;
                }

                $pi_t = pathinfo( $tfile );
                if ( empty( $pi_t['extension'] ) || ! self::extensions_match( $pi_t['extension'], $old_ext ) ) {
                    continue;
                }

                $new_thumb_file = $pi_t['filename'] . '.' . $new_ext;
                $old_thumb_path = $thumb_dir . $tfile;
                $new_thumb_path = $thumb_dir . $new_thumb_file;

                if ( file_exists( $old_thumb_path ) ) {
                    self::convert_file_to_format( $old_thumb_path, $new_thumb_path, $format, $quality );
                    if ( file_exists( $new_thumb_path ) && wp_normalize_path( $old_thumb_path ) !== wp_normalize_path( $new_thumb_path ) ) {
                        wp_delete_file( $old_thumb_path );
                    }
                }

                // Do not point metadata/content to a thumbnail that was not created successfully.
                if ( ! file_exists( $new_thumb_path ) || filesize( $new_thumb_path ) < 1 ) {
                    continue;
                }

                $old_thumb_url = $old_dir_url . self::encode_rel_path_for_url( $tfile );
                $new_thumb_url = $new_dir_url . self::encode_rel_path_for_url( $new_thumb_file );
                if ( $old_thumb_url !== $new_thumb_url ) {
                    $thumb_replacements[] = array( $old_thumb_url, $new_thumb_url );
                }

                $meta['sizes'][ $sz ]['file']      = $new_thumb_file;
                $meta['sizes'][ $sz ]['mime-type'] = $new_mime;
            }
        }

        $meta['file']   = $relative_path;
        $meta['width']  = $new_w;
        $meta['height'] = $new_h;
        wp_update_attachment_metadata( $attachment_id, $meta );

        // Mime type via $wpdb directe (evita hooks save_post d'altres plugins)
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->posts,
            array( 'post_mime_type' => $new_mime ),
            array( 'ID'             => $attachment_id ),
            array( '%s' ),
            array( '%d' )
        );

        // Actualitzar URLs al contingut si han canviat
        if ( $old_url !== $new_url ) {
            $pairs = array_merge( array( array( $old_url, $new_url ) ), $thumb_replacements );
            self::replace_url_pairs_in_content( $pairs );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->posts,
                array( 'guid' => $new_url ),
                array( 'ID'   => $attachment_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        clean_attachment_cache( $attachment_id );
        wp_cache_delete( $attachment_id, 'posts' );
    }

    /**
     * FASE 3 — Thumbnails (WP-Cron, background, lent).
     * En aquest punt update_wp_metadata_only() ja ha actualitzat la DB,
     * per tant get_attached_file() retorna el path correcte.
     */
    public static function process_thumbnails_background( $attachment_id, $format, $quality ) {
        $attachment_id = absint( $attachment_id );
        $format        = sanitize_key( $format );
        $quality       = absint( $quality );

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit( 300 );
        ignore_user_abort( true );

        // These files are NOT loaded automatically in WP-Cron context.
        // wp_generate_attachment_metadata() and wp_update_attachment_metadata()
        // live in wp-admin/includes/image.php — must be required explicitly.
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_read_image_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) return;

        // ── FIX CRÍTIC: Eliminar thumbnails existents ABANS de wp_generate_attachment_metadata ──
        // Si no s'eliminen, WordPress detecta que el fitxer de destí ja existeix i usa
        // wp_unique_filename() per crear noms com photo-150x150-1.webp → metadades duplicades!
        // Eliminem totes les variants (jpg, webp, png...) per garantir nom net.
        $old_meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $old_meta['sizes'] ) ) {
            $thumb_dir = trailingslashit( dirname( $file ) );
            foreach ( $old_meta['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) continue;
                $thumb_path = $thumb_dir . $size_data['file'];
                if ( file_exists( $thumb_path ) ) {
                    wp_delete_file( $thumb_path );
                }
                // Eliminar variants d'extensió alternativa (seguretat addicional)
                $pi_t = pathinfo( $thumb_path );
                foreach ( array( 'webp', 'jpg', 'jpeg', 'png', 'gif', 'avif' ) as $try_ext ) {
                    if ( strtolower( isset( $pi_t['extension'] ) ? $pi_t['extension'] : '' ) === $try_ext ) {
                        continue;
                    }
                    $alt_path = $pi_t['dirname'] . '/' . $pi_t['filename'] . '.' . $try_ext;
                    if ( file_exists( $alt_path ) ) {
                        wp_delete_file( $alt_path );
                    }
                }
            }
        }

        // Regenerar thumbnails físics amb el nou fitxer principal
        // Ara que els fitxers antics han estat eliminats, WordPress crea noms nets sense sufixos _1, _2...
        $new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
        if ( ! is_wp_error( $new_meta ) && ! empty( $new_meta ) ) {
            wp_update_attachment_metadata( $attachment_id, $new_meta );
        }

        // Optimitzar els thumbnails generats (aplica format/qualitat personalitzats)
        // wp_generate pot crear thumbnails en format natiu (JPEG en WP < 6.1); convertim si cal
        self::optimize_thumbnails( $attachment_id, $format, $quality );

        // After regeneration, dimension URLs in posts (e.g. -1024x951.webp) may no longer exist
        // because the new source image is smaller. Point them to the closest valid file.
        self::repair_content_urls_for_attachment( $attachment_id, $old_meta );

        clean_attachment_cache( $attachment_id );
        if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
        do_action( 'litespeed_purge_post', $attachment_id );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
        do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
        if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
        if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
    }

    /**
     * Replace broken attachment URLs in content after thumbnail regeneration.
     *
     * @param int        $attachment_id Attachment ID.
     * @param array|null $old_meta      Metadata snapshot before thumbnail regeneration.
     * @return int Number of URL pairs repaired.
     */
    public static function repair_content_urls_for_attachment( $attachment_id, $old_meta = null ) {
        $attachment_id = absint( $attachment_id );
        $file          = get_attached_file( $attachment_id );

        if ( ! $file || ! file_exists( $file ) ) {
            return 0;
        }

        $meta       = wp_get_attachment_metadata( $attachment_id );
        $upload_dir = wp_upload_dir();
        $pairs      = self::collect_broken_attachment_url_pairs( $attachment_id, $file, $meta, $upload_dir, $old_meta );

        if ( empty( $pairs ) ) {
            return 0;
        }

        self::replace_url_pairs_in_content( array_values( $pairs ) );
        return count( $pairs );
    }

    /**
     * Collect stale/broken URL pairs for one attachment (proactive + DB scan).
     *
     * @param int        $attachment_id Attachment ID.
     * @param string     $file          Main file path.
     * @param array      $meta          Current metadata.
     * @param array      $upload_dir    wp_upload_dir() result.
     * @param array|null $old_meta      Metadata before regeneration.
     * @return array[] Array of [old_url, new_url] pairs keyed by hash.
     */
    private static function collect_broken_attachment_url_pairs( $attachment_id, $file, $meta, $upload_dir, $old_meta = null ) {
        $pairs      = array();
        $valid_urls = self::collect_attachment_public_urls( $attachment_id, $file, $meta, $upload_dir );

        if ( is_array( $old_meta ) ) {
            foreach ( self::build_pairs_from_stale_metadata( $file, $meta, $upload_dir, $old_meta, $valid_urls ) as $pair ) {
                $pairs[ md5( $pair[0] ) ] = $pair;
            }
        }

        foreach ( self::discover_broken_urls_in_storage( $file, $meta, $upload_dir, $valid_urls ) as $pair ) {
            $pairs[ md5( $pair[0] ) ] = $pair;
        }

        return $pairs;
    }

    /**
     * Map URLs from pre-regeneration metadata to the closest existing file.
     *
     * @param string   $file       Main file path.
     * @param array    $meta       Current metadata.
     * @param array    $upload_dir wp_upload_dir() result.
     * @param array    $old_meta   Metadata before regeneration.
     * @param string[] $valid_urls Existing public URLs.
     * @return array[]
     */
    private static function build_pairs_from_stale_metadata( $file, $meta, $upload_dir, $old_meta, $valid_urls ) {
        $pairs     = array();
        $base_dir  = wp_normalize_path( $upload_dir['basedir'] );
        $base_url = untrailingslashit( $upload_dir['baseurl'] );
        $rel_dir  = self::attachment_rel_dir_from_meta( $old_meta, $file, $base_dir );
        $dir_url  = $base_url . ( '' !== $rel_dir ? '/' . self::encode_rel_path_for_url( $rel_dir ) : '' );

        $stale_candidates = array();

        if ( ! empty( $old_meta['file'] ) ) {
            $stale_candidates[] = basename( (string) $old_meta['file'] );
        }

        if ( ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) {
            foreach ( $old_meta['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }
                $stale_candidates[] = (string) $size_data['file'];
            }
        }

        $base_name = pathinfo( $file, PATHINFO_FILENAME );
        foreach ( self::get_image_extension_list() as $ext ) {
            $stale_candidates[] = $base_name . '-scaled.' . $ext;
        }

        $stale_candidates = array_values( array_unique( array_filter( $stale_candidates ) ) );

        foreach ( $stale_candidates as $filename ) {
            foreach ( self::build_public_url_variants( $dir_url, $filename ) as $stale_url ) {
                if ( self::uploads_url_exists_on_disk( $stale_url, $base_dir, $base_url ) ) {
                    continue;
                }

                $replacement = self::pick_replacement_url_for_broken( $stale_url, $valid_urls, $meta, $upload_dir );
                if ( ! $replacement || self::urls_equivalent_for_repair( $stale_url, $replacement ) ) {
                    continue;
                }

                $pairs[ md5( $stale_url ) ] = array( $stale_url, $replacement );
            }
        }

        return $pairs;
    }

    /**
     * Scan posts, postmeta and options for broken URLs referencing this attachment.
     *
     * @param string   $file       Main file path.
     * @param array    $meta       Current metadata.
     * @param array    $upload_dir wp_upload_dir() result.
     * @param string[] $valid_urls Existing public URLs.
     * @return array[]
     */
    private static function discover_broken_urls_in_storage( $file, $meta, $upload_dir, $valid_urls ) {
        $pairs      = array();
        $base_name  = pathinfo( $file, PATHINFO_FILENAME );
        $base_dir   = wp_normalize_path( $upload_dir['basedir'] );
        $base_url   = untrailingslashit( $upload_dir['baseurl'] );
        $ext_pat    = self::get_image_extension_pattern();
        $patterns   = array(
            '#(https?://[^\s"\'<>\)]+/' . preg_quote( $base_name, '#' ) . '(?:-scaled|-\d+x\d+)?\.(?:' . $ext_pat . '))#i',
            '#(/wp-content/uploads/[^\s"\'<>\)]+/' . preg_quote( $base_name, '#' ) . '(?:-scaled|-\d+x\d+)?\.(?:' . $ext_pat . '))#i',
        );

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $base_name ) . '%';
        $chunks = array();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_content, post_excerpt FROM {$wpdb->posts}
                 WHERE (post_content LIKE %s OR post_excerpt LIKE %s)
                 AND post_status IN ('publish','draft','private','pending','future')",
                $like,
                $like
            )
        );
        foreach ( (array) $post_rows as $row ) {
            $chunks[] = (string) $row->post_content;
            $chunks[] = (string) $row->post_excerpt;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Targeted LIKE scan to find broken attachment URLs in custom fields.
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                $like
            )
        );
        foreach ( (array) $meta_rows as $row ) {
            $chunks[] = (string) $row->meta_value;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $option_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options}
                 WHERE option_value LIKE %s AND option_name NOT LIKE %s",
                $like,
                $wpdb->esc_like( '_transient' ) . '%'
            )
        );
        foreach ( (array) $option_rows as $row ) {
            $chunks[] = (string) $row->option_value;
        }

        $seen_urls = array();
        foreach ( $chunks as $content ) {
            if ( '' === $content || false === strpos( $content, $base_name ) ) {
                continue;
            }

            foreach ( $patterns as $pattern ) {
                if ( ! preg_match_all( $pattern, $content, $matches ) ) {
                    continue;
                }
                foreach ( array_unique( $matches[1] ) as $raw_url ) {
                    $url = self::normalize_discovered_upload_url( $raw_url, $base_url );
                    if ( ! $url || isset( $seen_urls[ $url ] ) ) {
                        continue;
                    }
                    $seen_urls[ $url ] = true;

                    if ( self::uploads_url_exists_on_disk( $url, $base_dir, $base_url ) ) {
                        continue;
                    }

                    $replacement = self::pick_replacement_url_for_broken( $url, $valid_urls, $meta, $upload_dir );
                    if ( ! $replacement || self::urls_equivalent_for_repair( $url, $replacement ) ) {
                        continue;
                    }

                    $pairs[ md5( $url ) ] = array( $url, $replacement );
                }
            }
        }

        return $pairs;
    }

    /**
     * @param string $raw_url  URL found in stored content.
     * @param string $base_url Uploads base URL.
     * @return string|null
     */
    private static function normalize_discovered_upload_url( $raw_url, $base_url ) {
        $raw_url = (string) $raw_url;
        if ( '' === $raw_url ) {
            return null;
        }

        if ( 0 === strpos( $raw_url, '/wp-content/uploads/' ) ) {
            return untrailingslashit( $base_url ) . $raw_url;
        }

        if ( 0 === strpos( $raw_url, 'http://' ) || 0 === strpos( $raw_url, 'https://' ) ) {
            return $raw_url;
        }

        return null;
    }

    /**
     * @param string $dir_url  Directory URL (no trailing slash).
     * @param string $filename File basename.
     * @return string[]
     */
    private static function build_public_url_variants( $dir_url, $filename ) {
        $dir_url  = untrailingslashit( (string) $dir_url );
        $filename = (string) $filename;
        $encoded  = $dir_url . '/' . self::encode_rel_path_for_url( $filename );
        $decoded  = $dir_url . '/' . str_replace( '\\', '/', $filename );

        return array_values( array_unique( array_filter( array( $encoded, $decoded ) ) ) );
    }

    /**
     * @param array  $meta     Attachment metadata.
     * @param string $file     Main file path.
     * @param string $base_dir Uploads basedir (normalized).
     * @return string Relative directory (e.g. 2012/06) or empty string.
     */
    private static function attachment_rel_dir_from_meta( $meta, $file, $base_dir ) {
        if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
            $rel = str_replace( '\\', '/', (string) $meta['file'] );
            $dir = dirname( $rel );
            return ( '.' === $dir ) ? '' : trim( $dir, '/' );
        }

        $rel_main = ltrim( str_replace( $base_dir, '', wp_normalize_path( $file ) ), '/' );
        $dir      = dirname( $rel_main );
        return ( '.' === $dir ) ? '' : trim( str_replace( '\\', '/', $dir ), '/' );
    }

    /**
     * @return string[]
     */
    private static function get_image_extension_list() {
        return array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
    }

    /**
     * @return string Regex alternation for image extensions.
     */
    private static function get_image_extension_pattern() {
        return implode( '|', self::get_image_extension_list() );
    }

    /**
     * @param int    $attachment_id Attachment ID.
     * @param string $file          Absolute main file path.
     * @param array  $meta          Attachment metadata.
     * @param array  $upload_dir    wp_upload_dir() result.
     * @return string[] Public URLs that exist on disk.
     */
    private static function collect_attachment_public_urls( $attachment_id, $file, $meta, $upload_dir ) {
        $urls       = array();
        $base_dir   = wp_normalize_path( $upload_dir['basedir'] );
        $base_url   = untrailingslashit( $upload_dir['baseurl'] );
        $rel_main   = ltrim( str_replace( $base_dir, '', wp_normalize_path( $file ) ), '/' );

        if ( file_exists( $file ) ) {
            $urls[] = $base_url . '/' . self::encode_rel_path_for_url( $rel_main );
        }

        if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
            $rel_dir = trailingslashit( dirname( $rel_main ) );
            if ( '.' === $rel_dir ) {
                $rel_dir = '';
            }
            foreach ( $meta['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }
                $thumb_path = trailingslashit( dirname( $file ) ) . $size_data['file'];
                if ( ! file_exists( $thumb_path ) ) {
                    continue;
                }
                $urls[] = $base_url . '/' . self::encode_rel_path_for_url( $rel_dir . $size_data['file'] );
            }
        }

        return array_values( array_unique( $urls ) );
    }

    /**
     * @param string   $broken_url Broken URL from content.
     * @param string[] $valid_urls Existing public URLs for the attachment.
     * @param array    $meta       Attachment metadata.
     * @param array    $upload_dir wp_upload_dir() result.
     * @return string|null
     */
    private static function pick_replacement_url_for_broken( $broken_url, $valid_urls, $meta, $upload_dir ) {
        if ( empty( $valid_urls ) ) {
            return null;
        }

        $base_url = untrailingslashit( $upload_dir['baseurl'] );
        $base_dir = wp_normalize_path( $upload_dir['basedir'] );
        $fallback = $valid_urls[0];

        // Same path stem but another extension (e.g. content still has .jpg, disk has .webp).
        $existing_variant = self::resolve_existing_upload_url_variant( $broken_url, $base_dir, $base_url );
        if ( $existing_variant && ! self::urls_equivalent_for_repair( $broken_url, $existing_variant ) ) {
            return $existing_variant;
        }

        $decoded = rawurldecode( (string) $broken_url );

        // WordPress "-scaled" originals map to the current main file.
        if ( preg_match( '/-scaled\.[a-z0-9]+$/i', $decoded ) ) {
            return $fallback;
        }

        if ( ! preg_match( '/-(\d+)x(\d+)\./i', $decoded, $dims ) ) {
            return $fallback;
        }

        $want_w    = absint( $dims[1] );
        $want_h    = absint( $dims[2] );
        $best_url  = null;
        $best_diff = PHP_INT_MAX;

        if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
            $rel_dir = self::attachment_rel_dir_from_meta( $meta, '', $base_dir );
            $prefix  = ( '' !== $rel_dir ) ? $rel_dir . '/' : '';

            foreach ( $meta['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $thumb_path = wp_normalize_path( $base_dir . '/' . $prefix . $size_data['file'] );
                if ( ! is_file( $thumb_path ) ) {
                    continue;
                }

                $w = isset( $size_data['width'] ) ? absint( $size_data['width'] ) : 0;
                $h = isset( $size_data['height'] ) ? absint( $size_data['height'] ) : 0;
                if ( ! $w || ! $h ) {
                    continue;
                }

                $diff = abs( $w - $want_w ) + abs( $h - $want_h );
                if ( $diff < $best_diff ) {
                    $best_diff = $diff;
                    $best_url  = $base_url . '/' . self::encode_rel_path_for_url( $prefix . $size_data['file'] );
                }
            }
        }

        return $best_url ? $best_url : $fallback;
    }

    /**
     * Whether a public uploads URL points to an existing file (any supported extension).
     *
     * @param string $url      Public URL.
     * @param string $base_dir Uploads basedir (normalized).
     * @param string $base_url Uploads baseurl (no trailing slash).
     * @return bool
     */
    private static function uploads_url_exists_on_disk( $url, $base_dir, $base_url ) {
        return (bool) self::resolve_existing_upload_url_variant( $url, $base_dir, $base_url );
    }

    /**
     * Resolve a broken uploads URL to an existing file URL (extension variants included).
     *
     * @param string $url      Public URL.
     * @param string $base_dir Uploads basedir (normalized).
     * @param string $base_url Uploads baseurl (no trailing slash).
     * @return string|null
     */
    private static function resolve_existing_upload_url_variant( $url, $base_dir, $base_url ) {
        $rel = self::uploads_rel_path_from_public_url( $url, $base_url );
        if ( null === $rel ) {
            return null;
        }

        $path = wp_normalize_path( $base_dir . '/' . $rel );
        if ( is_file( $path ) ) {
            $dir_rel = dirname( $rel );
            $dir_rel = ( '.' === $dir_rel ) ? '' : trim( str_replace( '\\', '/', $dir_rel ), '/' );
            $prefix  = ( '' !== $dir_rel ) ? $dir_rel . '/' : '';
            return untrailingslashit( $base_url ) . '/' . self::encode_rel_path_for_url( $prefix . basename( $rel ) );
        }

        $pi = pathinfo( $rel );
        if ( empty( $pi['filename'] ) ) {
            return null;
        }

        $dir_rel = isset( $pi['dirname'] ) ? $pi['dirname'] : '';
        $dir_rel = ( '.' === $dir_rel ) ? '' : trim( str_replace( '\\', '/', $dir_rel ), '/' );
        $prefix  = ( '' !== $dir_rel ) ? $dir_rel . '/' : '';

        foreach ( self::get_image_extension_list() as $ext ) {
            $candidate_rel  = $prefix . $pi['filename'] . '.' . $ext;
            $candidate_path = wp_normalize_path( $base_dir . '/' . $candidate_rel );
            if ( is_file( $candidate_path ) ) {
                return untrailingslashit( $base_url ) . '/' . self::encode_rel_path_for_url( $candidate_rel );
            }
        }

        return null;
    }

    /**
     * @param string $url      Public URL.
     * @param string $base_url Uploads baseurl (no trailing slash).
     * @return string|null Relative path inside uploads, decoded.
     */
    private static function uploads_rel_path_from_public_url( $url, $base_url ) {
        $url      = (string) $url;
        $base_url = untrailingslashit( (string) $base_url );

        if ( 0 !== strpos( $url, $base_url . '/' ) && 0 !== strpos( rawurldecode( $url ), $base_url . '/' ) ) {
            return null;
        }

        $rel = rawurldecode( ltrim( str_replace( $base_url . '/', '', $url ), '/' ) );
        if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
            return null;
        }

        return str_replace( '\\', '/', $rel );
    }

    private static function urls_equivalent_for_repair( $old_url, $new_url ) {
        if ( ! $old_url || ! $new_url ) {
            return false;
        }
        if ( $old_url === $new_url ) {
            return true;
        }
        return rawurldecode( $old_url ) === rawurldecode( $new_url );
    }

    /**
     * Genera thumbnails optimitzats per a un attachment.
     */
    public static function optimize_thumbnails( $attachment_id, $output_format = 'webp', $quality = 82 ) {
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( empty( $metadata['sizes'] ) ) return;

        $base_dir = trailingslashit( dirname( get_attached_file( $attachment_id ) ) );

        foreach ( $metadata['sizes'] as $size => $size_data ) {
            $thumb_path = $base_dir . $size_data['file'];
            if ( ! file_exists( $thumb_path ) ) continue;

            $mime  = mime_content_type( $thumb_path );
            $image = self::load_image( $thumb_path, $mime );
            if ( ! $image ) continue;

            $pi       = pathinfo( $thumb_path );
            $new_ext  = self::get_extension( $output_format, $pi['extension'] );
            $thumb_clean_name = $pi['filename'];
            $thumb_inner_pi   = pathinfo( $thumb_clean_name );
            if ( ! empty( $thumb_inner_pi['extension'] ) && in_array( strtolower( $thumb_inner_pi['extension'] ), array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true ) ) {
                $thumb_clean_name = $thumb_inner_pi['filename'];
            }
            $new_path = $pi['dirname'] . '/' . $thumb_clean_name . '.' . $new_ext;

            if ( ! self::save_image_resource_to_path( $image, $new_path, $new_ext, $quality ) ) {
                self::delete_file_if_exists( $new_path );
                continue;
            }

            if ( ! self::is_valid_image_file( $new_path ) ) {
                self::delete_file_if_exists( $new_path );
                continue;
            }

            if ( ! self::extensions_match( $pi['extension'], $new_ext ) ) {
                wp_delete_file( $thumb_path );
                $metadata['sizes'][ $size ]['file']      = $thumb_clean_name . '.' . $new_ext;
                $metadata['sizes'][ $size ]['mime-type'] = self::ext_to_mime( $new_ext );
            } elseif ( $thumb_clean_name !== $pi['filename'] && $new_path !== $thumb_path ) {
                wp_delete_file( $thumb_path );
                $metadata['sizes'][ $size ]['file'] = $thumb_clean_name . '.' . $new_ext;
            }
        }

        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    /**
     * Reemplaça URLs a posts, postmeta i options.
     */
    public static function replace_url_in_content( $old_url, $new_url ) {
        if ( $old_url === $new_url ) return;
        self::replace_url_pairs_in_content( array( array( $old_url, $new_url ) ) );
    }

    /**
     * Replace legacy dimension-suffixed URLs after a basename rename (same extension).
     *
     * @param string $old_dir_url   Old directory URL.
     * @param string $old_basename  Old filename without extension.
     * @param string $new_dir_url   New directory URL.
     * @param string $new_basename  New filename without extension.
     * @param string $ext           File extension (e.g. webp, jpg).
     */
    public static function replace_basename_dimension_urls_in_storage( $old_dir_url, $old_basename, $new_dir_url, $new_basename, $ext ) {
        $ext          = strtolower( (string) $ext );
        $old_basename = (string) $old_basename;
        $new_basename = (string) $new_basename;
        if ( '' === $ext || '' === $old_basename || '' === $new_basename || $old_basename === $new_basename ) {
            return;
        }

        $old_dir = trailingslashit( untrailingslashit( (string) $old_dir_url ) );
        $new_dir = trailingslashit( untrailingslashit( (string) $new_dir_url ) );

        $ext_variants = array( $ext );
        if ( 'jpg' === $ext ) {
            $ext_variants[] = 'jpeg';
        } elseif ( 'jpeg' === $ext ) {
            $ext_variants[] = 'jpg';
        }
        $ext_variants = array_values( array_unique( $ext_variants ) );

        foreach ( $ext_variants as $ext_variant ) {
            $old_url = $old_dir . self::encode_rel_path_for_url( $old_basename . '.' . $ext_variant );
            $new_url = $new_dir . self::encode_rel_path_for_url( $new_basename . '.' . $ext_variant );
            if ( $old_url === $new_url ) {
                continue;
            }
            self::replace_dimension_variant_urls_in_storage( $old_url, $new_url, $ext_variant, $ext_variant );
        }
    }

    /**
     * Replace URL pairs in posts, postmeta, and options (serialized-safe).
     *
     * @param array<int, array{0:string,1:string}> $pairs Old/new URL pairs.
     */
    public static function replace_url_pairs_in_content( $pairs ) {
        global $wpdb;

        $replacements = array();
        foreach ( $pairs as $pair ) {
            if ( empty( $pair[0] ) || empty( $pair[1] ) || $pair[0] === $pair[1] ) {
                continue;
            }
            $replacements[] = array( $pair[0], $pair[1] );
        }
        if ( empty( $replacements ) ) {
            return;
        }

        // Handle encoded/non-encoded URL variants (e.g. ñ vs %C3%B1) to avoid missed replacements.
        $normalized_replacements = self::expand_replacement_variants( $replacements );

        $fallback_replacements   = array();
        $dimension_replacements  = array();
        foreach ( $replacements as $pair ) {
            $old_url = $pair[0];
            $new_url = $pair[1];
            $old_ext = strtolower( pathinfo( $old_url, PATHINFO_EXTENSION ) );
            $new_ext = strtolower( pathinfo( $new_url, PATHINFO_EXTENSION ) );

            // Si l'extensió ha canviat, substituir també les variants de nom simple.
            if ( ! self::extensions_match( $old_ext, $new_ext ) ) {
                $basename = pathinfo( $old_url, PATHINFO_FILENAME );
                $fallback_replacements[] = array(
                    $basename . '.' . $old_ext,
                    $basename . '.' . $new_ext,
                );

                $basename_dec = pathinfo( rawurldecode( $old_url ), PATHINFO_FILENAME );
                if ( $basename_dec && $basename_dec !== $basename ) {
                    $fallback_replacements[] = array(
                        $basename_dec . '.' . $old_ext,
                        $basename_dec . '.' . $new_ext,
                    );
                }

                // Cover -WxH variants not present in attachment metadata.
                // Example: old "foto.jpg" can appear as "foto-1024x768.jpg" in legacy content.
                $dimension_replacements[] = array( $old_url, $new_url, $old_ext, $new_ext );
            }
        }

        $all_replacements = self::expand_replacement_variants( array_merge( $normalized_replacements, $fallback_replacements ) );
        foreach ( $all_replacements as $pair ) {
            $s    = $pair[0];
            $r    = $pair[1];
            $like = '%' . $wpdb->esc_like( $s ) . '%';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $s, $r, $like
            ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Serialized-safe per-row updates.
            $meta_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                    $like
                )
            );
            foreach ( (array) $meta_rows as $row ) {
                $updated = self::replace_in_stored_value( (string) $row->meta_value, $s, $r );
                if ( $updated !== (string) $row->meta_value ) {
                    update_metadata( 'post', (int) $row->post_id, $row->meta_key, $updated );
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $option_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options}
                     WHERE option_value LIKE %s AND option_name NOT LIKE %s",
                    $like,
                    $wpdb->esc_like( '_transient' ) . '%'
                )
            );
            foreach ( (array) $option_rows as $row ) {
                $updated = self::replace_in_stored_value( (string) $row->option_value, $s, $r );
                if ( $updated !== (string) $row->option_value ) {
                    update_option( (string) $row->option_name, $updated );
                }
            }
        }

        // Regex pass for dimension variants (-WxH), including encoded and decoded URL forms.
        foreach ( $dimension_replacements as $dim_pair ) {
            self::replace_dimension_variant_urls_in_storage( $dim_pair[0], $dim_pair[1], $dim_pair[2], $dim_pair[3] );
        }

        if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
        do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
        if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
        if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
    }

    private static function expand_replacement_variants( $pairs ) {
        $expanded = array();
        foreach ( (array) $pairs as $pair ) {
            if ( empty( $pair[0] ) || empty( $pair[1] ) ) {
                continue;
            }
            self::append_replacement_pair_unique( $expanded, $pair[0], $pair[1] );
            self::append_replacement_pair_unique( $expanded, rawurldecode( $pair[0] ), rawurldecode( $pair[1] ) );
        }
        return array_values( $expanded );
    }

    private static function append_replacement_pair_unique( &$pairs, $old, $new ) {
        $old = (string) $old;
        $new = (string) $new;
        if ( $old === '' || $new === '' || $old === $new ) {
            return;
        }
        $key = md5( $old . "\n" . $new );
        if ( isset( $pairs[ $key ] ) ) {
            return;
        }
        $pairs[ $key ] = array( $old, $new );
    }

    private static function replace_dimension_variant_urls_in_storage( $old_url, $new_url, $old_ext, $new_ext ) {
        global $wpdb;

        $old_variants = array_values( array_unique( array_filter( array(
            (string) $old_url,
            rawurldecode( (string) $old_url ),
        ) ) ) );
        $new_variants = array_values( array_unique( array_filter( array(
            (string) $new_url,
            rawurldecode( (string) $new_url ),
        ) ) ) );
        if ( empty( $old_variants ) || empty( $new_variants ) ) {
            return;
        }

        $like_keys = array();
        foreach ( $old_variants as $old_variant ) {
            $old_base = preg_replace( '/\.' . preg_quote( (string) $old_ext, '/' ) . '$/i', '', $old_variant );
            if ( $old_base ) {
                $like_keys[ $old_base . '-' ] = true;
            }
        }
        if ( empty( $like_keys ) ) {
            return;
        }

        $posts_by_id = array();
        foreach ( array_keys( $like_keys ) as $like_fragment ) {
            $like = '%' . $wpdb->esc_like( $like_fragment ) . '%';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
                     WHERE (post_content LIKE %s OR post_excerpt LIKE %s) AND post_status != 'trash'",
                    $like,
                    $like
                )
            );
            foreach ( (array) $rows as $row ) {
                $posts_by_id[ (int) $row->ID ] = $row;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Targeted LIKE scan to fix broken image URLs in custom fields/widgets.
            $meta_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                    $like
                )
            );
            foreach ( (array) $meta_rows as $row ) {
                $updated = self::apply_dimension_variant_regex( (string) $row->meta_value, $old_variants, $new_variants, $old_ext, $new_ext );
                if ( $updated !== (string) $row->meta_value ) {
                    update_metadata( 'post', (int) $row->post_id, $row->meta_key, $updated );
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $option_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options}
                     WHERE option_value LIKE %s AND option_name NOT LIKE %s",
                    $like,
                    $wpdb->esc_like( '_transient' ) . '%'
                )
            );
            foreach ( (array) $option_rows as $row ) {
                $updated = self::apply_dimension_variant_regex( (string) $row->option_value, $old_variants, $new_variants, $old_ext, $new_ext );
                if ( $updated !== (string) $row->option_value ) {
                    update_option( (string) $row->option_name, $updated );
                }
            }
        }

        foreach ( $posts_by_id as $row ) {
            $content_updated = self::apply_dimension_variant_regex( (string) $row->post_content, $old_variants, $new_variants, $old_ext, $new_ext );
            $excerpt_updated = self::apply_dimension_variant_regex( (string) $row->post_excerpt, $old_variants, $new_variants, $old_ext, $new_ext );

            if ( $content_updated !== (string) $row->post_content || $excerpt_updated !== (string) $row->post_excerpt ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $wpdb->posts,
                    array(
                        'post_content' => $content_updated,
                        'post_excerpt' => $excerpt_updated,
                    ),
                    array( 'ID' => (int) $row->ID ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
            }
        }
    }

    /**
     * @param string   $content      Stored text.
     * @param string[] $old_variants Old URL variants.
     * @param string[] $new_variants New URL variants.
     * @param string   $old_ext      Old extension.
     * @param string   $new_ext      New extension.
     * @return string
     */
    private static function apply_dimension_variant_regex( $content, $old_variants, $new_variants, $old_ext, $new_ext ) {
        $updated = (string) $content;

        for ( $i = 0; $i < count( $old_variants ); $i++ ) {
            $old_variant = $old_variants[ $i ];
            $new_variant = isset( $new_variants[ $i ] ) ? $new_variants[ $i ] : $new_variants[0];
            $old_base    = preg_replace( '/\.' . preg_quote( (string) $old_ext, '/' ) . '$/i', '', $old_variant );
            $new_base    = preg_replace( '/\.' . preg_quote( (string) $new_ext, '/' ) . '$/i', '', $new_variant );
            if ( ! $old_base || ! $new_base || $old_base === $new_base ) {
                continue;
            }

            $updated = preg_replace(
                '/' . preg_quote( $old_base, '/' ) . '-(\d+x\d+)\.' . preg_quote( (string) $old_ext, '/' ) . '/i',
                $new_base . '-$1.' . $new_ext,
                $updated
            );
        }

        return $updated;
    }

    /**
     * Roll back filesystem changes when optimize() succeeded but metadata update failed.
     *
     * @param array $result Result array from optimize().
     * @return bool True when rollback completed or nothing to roll back.
     */
    public static function rollback_optimize_files( $result ) {
        if ( empty( $result['replaced'] ) ) {
            return true;
        }

        $old_path    = isset( $result['old_path'] ) ? (string) $result['old_path'] : '';
        $new_path    = isset( $result['new_path'] ) ? (string) $result['new_path'] : '';
        $backup_path = isset( $result['backup_path'] ) ? (string) $result['backup_path'] : '';

        if ( $old_path && $backup_path && self::is_valid_image_file( $backup_path ) ) {
            if ( ! self::copy_file_validated( $backup_path, $old_path ) ) {
                self::delete_file_if_exists( $new_path );
                return false;
            }
        }

        if ( $new_path && ( ! $old_path || wp_normalize_path( $new_path ) !== wp_normalize_path( $old_path ) ) ) {
            self::delete_file_if_exists( $new_path );
        }

        return true;
    }

    /**
     * Reverteix una imatge optimitzada a la còpia de seguretat.
     */
    public static function revert( $attachment_id ) {
        $attachment_id = absint( $attachment_id );

        // Ensure admin image functions are available (not loaded outside admin context).
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $backup_path   = get_post_meta( $attachment_id, '_tso_im_backup_file', true );
        $backup_mime   = get_post_meta( $attachment_id, '_tso_im_backup_mime', true );
        $backup_attached_file = self::normalize_attached_file_meta_value( get_post_meta( $attachment_id, '_tso_im_backup_attached_file', true ) );
        $current_attached_file = self::normalize_attached_file_meta_value( get_post_meta( $attachment_id, '_wp_attached_file', true ) );

        $backup_status = self::get_backup_status( $attachment_id, true );
        if ( empty( $backup_status['has_backup'] ) ) {
            return new WP_Error( 'no_backup', 'No hi ha còpia de seguretat disponible.' );
        }
        $backup_path = $backup_status['backup_path'];

        // Safety guard:
        // If the current attached file no longer matches the backup context (usually after rename),
        // block direct revert to prevent restoring over a different file identity.
        if ( self::is_backup_context_mismatch( $backup_path, $backup_attached_file, $current_attached_file ) ) {
            return new WP_Error(
                'backup_mismatch_after_rename',
                'backup_mismatch_after_rename'
            );
        }

        $current_path = get_attached_file( $attachment_id );
        $current_url  = wp_get_attachment_url( $attachment_id );
        $old_meta     = wp_get_attachment_metadata( $attachment_id );
        $pi_backup    = pathinfo( $backup_path );
        $pi_current   = pathinfo( $current_path );
        $restored_path = $pi_current['dirname'] . '/' . $pi_current['filename'] . '.' . $pi_backup['extension'];

        if ( ! self::copy_file_validated( $backup_path, $restored_path ) ) {
            return new WP_Error( 'copy_failed', 'No s\'ha pogut restaurar el fitxer.' );
        }

        if ( ! self::extensions_match( $pi_current['extension'], $pi_backup['extension'] ) ) {
            if ( file_exists( $current_path ) ) {
                wp_delete_file( $current_path );
            }
            self::delete_scaled_variant_if_exists( $pi_current['dirname'], $pi_current['filename'], $pi_current['extension'] );
        }

        update_attached_file( $attachment_id, $restored_path );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->posts,
            array( 'post_mime_type' => $backup_mime ),
            array( 'ID'             => $attachment_id ),
            array( '%s' ), array( '%d' )
        );

        $new_meta    = array();
        $generated   = wp_generate_attachment_metadata( $attachment_id, $restored_path );
        if ( ! is_wp_error( $generated ) && ! empty( $generated ) ) {
            $new_meta = $generated;
            wp_update_attachment_metadata( $attachment_id, $new_meta );
        }

        clean_attachment_cache( $attachment_id );
        $restored_url = wp_get_attachment_url( $attachment_id );

        $url_pairs = array();
        if ( $current_url && $restored_url && $current_url !== $restored_url ) {
            $url_pairs[] = array( $current_url, $restored_url );
        }
        if ( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) && is_array( $new_meta ) && ! empty( $new_meta['sizes'] ) ) {
            $old_dir_url = trailingslashit( dirname( (string) $current_url ) );
            $new_dir_url = trailingslashit( dirname( (string) $restored_url ) );
            foreach ( $old_meta['sizes'] as $size_key => $old_sz ) {
                if ( empty( $old_sz['file'] ) || empty( $new_meta['sizes'][ $size_key ]['file'] ) ) {
                    continue;
                }
                $old_thumb_url = $old_dir_url . self::encode_rel_path_for_url( $old_sz['file'] );
                $new_thumb_url = $new_dir_url . self::encode_rel_path_for_url( $new_meta['sizes'][ $size_key ]['file'] );
                if ( $old_thumb_url !== $new_thumb_url ) {
                    $url_pairs[] = array( $old_thumb_url, $new_thumb_url );
                }
            }
        }
        if ( ! empty( $url_pairs ) ) {
            self::replace_url_pairs_in_content( $url_pairs );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->posts,
                array( 'guid' => $restored_url ),
                array( 'ID'   => $attachment_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        self::clear_backup_meta( $attachment_id );
        self::delete_backup_file( $backup_path );

        return array(
            'attachment_id'   => $attachment_id,
            'restored_url'    => $restored_url,
            'restored_size'   => file_exists( $restored_path ) ? filesize( $restored_path ) : 0,
            'restored_size_h' => file_exists( $restored_path ) ? size_format( filesize( $restored_path ) ) : '-',
            'restored_ext'    => strtoupper( $pi_backup['extension'] ),
        );
    }

    public static function webp_supported() {
        return function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromwebp' );
    }

    /**
     * Whether GD can encode AVIF images.
     *
     * @return bool
     */
    public static function avif_supported() {
        return function_exists( 'imageavif' ) && function_exists( 'imagecreatefromavif' );
    }

    /**
     * Run full optimize pipeline for one attachment (used by bulk + queue).
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $format        Output format.
     * @param int    $quality       Quality.
     * @param bool   $replace       Replace original.
     * @return array<string, mixed>|WP_Error
     */
    public static function run_optimize_pipeline( $attachment_id, $format, $quality, $replace = true ) {
        $attachment_id = absint( $attachment_id );
        $format        = sanitize_key( $format );
        $quality       = min( 100, max( 50, absint( $quality ) ) );

        $res = self::optimize( $attachment_id, $format, $quality, $replace );
        if ( is_wp_error( $res ) ) {
            return $res;
        }

        if ( ! empty( $res['replaced'] ) ) {
            try {
                self::update_wp_metadata_only( $attachment_id, $res, $format );
            } catch ( \Throwable $ex ) {
                self::rollback_optimize_files( $res );
                return new WP_Error( 'metadata_failed', 'FASE 2: ' . $ex->getMessage() );
            }

            $ext_changed = ! empty( $res['old_ext'] ) && ! empty( $res['new_ext'] )
                && ! self::extensions_match( $res['old_ext'], $res['new_ext'] );

            if ( $ext_changed ) {
                self::process_thumbnails_background( $attachment_id, $format, $quality );
            } else {
                self::optimize_thumbnails( $attachment_id, $format, $quality );
                self::repair_content_urls_for_attachment( $attachment_id );
            }
        }

        $bulk_file = get_attached_file( $attachment_id );
        TSOIMMA_History::log(
            $attachment_id,
            'optimize',
            array(
                'filename'      => $bulk_file ? basename( $bulk_file ) : '',
                'format'        => $format,
                'quality'       => $quality,
                'savings_bytes' => $res['savings_bytes'] ?? 0,
                'savings_pct'   => $res['savings_pct'] ?? 0,
            )
        );

        TSOIMMA_Cache_Helper::purge_after_change( $attachment_id );
        return $res;
    }

    /**
     * Skip auto-optimize when upload is already small/optimized (WP 7.1 client-side uploads).
     *
     * @param int $attachment_id Attachment ID.
     * @param int $max_kb        Max file size in KB to skip (0 = disabled).
     * @return bool
     */
    public static function should_skip_auto_optimize( $attachment_id, $max_kb = 0 ) {
        $max_kb = absint( $max_kb );
        if ( $max_kb <= 0 ) {
            return false;
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( 'image/webp' === $mime || 'image/avif' === $mime ) {
            $size_kb = (int) ceil( filesize( $file_path ) / 1024 );
            if ( $size_kb > 0 && $size_kb <= $max_kb ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove all backup post meta for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     */
    public static function clear_backup_meta( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        delete_post_meta( $attachment_id, '_tso_im_backup_file' );
        delete_post_meta( $attachment_id, '_tso_im_backup_mime' );
        delete_post_meta( $attachment_id, '_tso_im_backup_size' );
        delete_post_meta( $attachment_id, '_tso_im_backup_attached_file' );
        delete_post_meta( $attachment_id, '_tso_im_backup_current_name' );
    }

    /**
     * Verify a backup path is inside uploads/tso-image-master/.
     *
     * @param string $path            Stored or absolute backup path.
     * @param bool   $require_exists  When true, the file must exist on disk.
     * @return string|false Resolved absolute path, or false when invalid.
     */
    public static function resolve_backup_path( $path, $require_exists = true ) {
        $path = (string) $path;
        if ( '' === $path ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $marker     = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master/' );
        $norm_path  = wp_normalize_path( $path );

        if ( 0 !== strpos( $norm_path, $marker ) ) {
            return false;
        }

        if ( ! preg_match( '/_tso_im_backup\.[a-z0-9]+$/i', $norm_path ) ) {
            return false;
        }

        if ( ! file_exists( $path ) ) {
            return $require_exists ? false : $norm_path;
        }

        $real_path = realpath( $path );
        if ( false === $real_path || ! is_file( $real_path ) ) {
            return false;
        }

        $norm_real = wp_normalize_path( $real_path );
        if ( 0 !== strpos( $norm_real, $marker ) ) {
            return false;
        }

        return $real_path;
    }

    /**
     * Remove empty parent directories under uploads/tso-image-master/ after a backup is deleted.
     *
     * @param string $backup_file_path Absolute path of the deleted backup file.
     * @return void
     */
    public static function prune_empty_backup_dirs( $backup_file_path ) {
        $backup_file_path = (string) $backup_file_path;
        if ( '' === $backup_file_path ) {
            return;
        }

        $upload_dir  = wp_upload_dir();
        $backup_base = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master' );
        $dir         = wp_normalize_path( dirname( $backup_file_path ) );

        if ( $dir === $backup_base || 0 !== strpos( $dir, $backup_base . '/' ) ) {
            return;
        }

        while ( $dir !== $backup_base && 0 === strpos( $dir, $backup_base . '/' ) ) {
            if ( ! is_dir( $dir ) ) {
                break;
            }

            clearstatcache( true, $dir );
            $entries = scandir( $dir );
            if ( false === $entries ) {
                break;
            }

            $remaining = array_diff( $entries, array( '.', '..' ) );
            if ( ! empty( $remaining ) ) {
                break;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            if ( ! @rmdir( $dir ) ) {
                break;
            }

            $dir = wp_normalize_path( dirname( $dir ) );
        }
    }

    /**
     * Delete a backup file and prune empty directories left behind.
     *
     * @param string $backup_path Absolute backup file path.
     * @return void
     */
    private static function delete_backup_file( $backup_path ) {
        self::delete_file_if_exists( $backup_path );
        self::prune_empty_backup_dirs( $backup_path );
    }

    /**
     * Return backup availability based on the physical file, not stale DB meta alone.
     *
     * @param int  $attachment_id     Attachment ID.
     * @param bool $clear_stale_meta  Remove backup meta when the file no longer exists.
     * @return array{has_backup:bool,backup_size:string,backup_bytes:int,backup_path:string}
     */
    public static function get_backup_status( $attachment_id, $clear_stale_meta = true ) {
        $attachment_id = absint( $attachment_id );
        $empty         = array(
            'has_backup'   => false,
            'backup_size'  => '',
            'backup_bytes' => 0,
            'backup_path'  => '',
        );

        $stored_path = get_post_meta( $attachment_id, '_tso_im_backup_file', true );
        if ( ! $stored_path ) {
            return $empty;
        }

        $resolved = self::resolve_backup_path( $stored_path, true );
        if ( ! $resolved || ! self::is_valid_image_file( $resolved ) ) {
            $resolved = self::locate_backup_file_for_attachment( $attachment_id );
            if ( $resolved ) {
                update_post_meta( $attachment_id, '_tso_im_backup_file', $resolved );
                update_post_meta( $attachment_id, '_tso_im_backup_size', filesize( $resolved ) );
            }
        }

        if ( ! $resolved || ! self::is_valid_image_file( $resolved ) ) {
            if ( $clear_stale_meta ) {
                self::clear_backup_meta( $attachment_id );
            }
            return $empty;
        }

        clearstatcache( true, $resolved );
        $bytes = filesize( $resolved );

        return array(
            'has_backup'   => true,
            'backup_size'  => size_format( $bytes ),
            'backup_bytes' => (int) $bytes,
            'backup_path'  => $resolved,
        );
    }

    /**
     * Try to locate a backup file when stored meta path is stale.
     *
     * @param int $attachment_id Attachment ID.
     * @return string|false Absolute path when found.
     */
    private static function locate_backup_file_for_attachment( $attachment_id ) {
        $backup_mime     = (string) get_post_meta( $attachment_id, '_tso_im_backup_mime', true );
        $backup_attached = self::normalize_attached_file_meta_value(
            get_post_meta( $attachment_id, '_tso_im_backup_attached_file', true )
        );
        $upload_dir      = wp_upload_dir();
        $basedir_norm    = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );

        $mime_ext_map = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        );
        $ext = isset( $mime_ext_map[ $backup_mime ] ) ? $mime_ext_map[ $backup_mime ] : '';
        if ( '' === $ext ) {
            $stored = (string) get_post_meta( $attachment_id, '_tso_im_backup_file', true );
            $ext    = strtolower( pathinfo( $stored, PATHINFO_EXTENSION ) );
        }
        if ( '' === $ext ) {
            return false;
        }

        $source_paths = array();
        if ( $backup_attached ) {
            $source_paths[] = $basedir_norm . ltrim( wp_normalize_path( $backup_attached ), '/' );
        }

        $current = get_attached_file( $attachment_id );
        if ( $current ) {
            $source_paths[] = wp_normalize_path( $current );
        }

        $source_paths = array_values( array_unique( array_filter( $source_paths ) ) );
        foreach ( $source_paths as $source_path ) {
            $candidate = self::get_backup_path( $source_path, $ext );
            if ( self::is_valid_image_file( $candidate ) ) {
                return $candidate;
            }

            $filename = pathinfo( $source_path, PATHINFO_FILENAME );
            $legacy   = $basedir_norm . 'tso-image-master/' . $filename . '_tso_im_backup.' . $ext;
            if ( self::is_valid_image_file( $legacy ) ) {
                return wp_normalize_path( $legacy );
            }
        }

        return false;
    }

    // ── Helpers privats ─────────────────────────────────────────────

    /**
     * Replace a substring inside a stored value without breaking serialized PHP data.
     *
     * @param string $value   Stored meta/option value.
     * @param string $search  Needle.
     * @param string $replace Replacement.
     * @return string
     */
    public static function replace_in_stored_value( $value, $search, $replace ) {
        $value = (string) $value;
        if ( '' === $value || $search === $replace ) {
            return $value;
        }

        if ( is_serialized( $value ) ) {
            $data = maybe_unserialize( $value );
            if ( is_array( $data ) || is_object( $data ) ) {
                $data = map_deep(
                    $data,
                    function( $item ) use ( $search, $replace ) {
                        return is_string( $item ) ? str_replace( $search, $replace, $item ) : $item;
                    }
                );
                return maybe_serialize( $data );
            }
        }

        return str_replace( $search, $replace, $value );
    }

    /**
     * Delete a file if it exists on disk.
     *
     * @param string $path Absolute file path.
     */
    public static function delete_file_if_exists( $path ) {
        if ( $path && file_exists( $path ) ) {
            wp_delete_file( $path );
        }
    }

    /**
     * Whether a path points to a non-empty file.
     *
     * @param string $path     Absolute file path.
     * @param int    $min_size Minimum size in bytes.
     * @return bool
     */
    public static function is_valid_image_file( $path, $min_size = 1 ) {
        if ( ! $path || ! is_file( $path ) ) {
            return false;
        }
        clearstatcache( true, $path );
        return filesize( $path ) >= max( 1, (int) $min_size );
    }

    /**
     * Copy a file and verify the destination is non-empty.
     *
     * @param string $source Source path.
     * @param string $dest   Destination path.
     * @return bool
     */
    public static function copy_file_validated( $source, $dest ) {
        if ( ! @copy( $source, $dest ) ) {
            self::delete_file_if_exists( $dest );
            return false;
        }
        if ( ! self::is_valid_image_file( $dest, 1 ) ) {
            self::delete_file_if_exists( $dest );
            return false;
        }
        return true;
    }

    /**
     * Remove stale *_tso_im_opt.* files left by a failed conversion.
     *
     * @param string $dir      Directory containing the attachment.
     * @param string $basename Filename without extension.
     */
    private static function cleanup_stale_opt_temp_files( $dir, $basename ) {
        $dir = trailingslashit( (string) $dir );
        $basename = (string) $basename;
        if ( '' === $dir || '' === $basename ) {
            return;
        }
        foreach ( array( 'webp', 'jpg', 'jpeg', 'png', 'gif', 'avif', 'bmp' ) as $ext ) {
            self::delete_file_if_exists( $dir . $basename . '_tso_im_opt.' . $ext );
        }
    }

    /**
     * Human-readable load error for unsupported or corrupt sources.
     *
     * @param string $mime MIME type.
     * @param string $ext  File extension.
     * @return string
     */
    private static function get_load_error_message( $mime, $ext ) {
        $ext  = strtolower( (string) $ext );
        $mime = (string) $mime;

        if ( in_array( $ext, array( 'tif', 'tiff', 'bmp' ), true )
            || in_array( $mime, array( 'image/bmp', 'image/x-ms-bmp', 'image/x-bmp', 'image/tiff', 'image/x-tiff', 'image/tif' ), true ) ) {
            return 'No s\'ha pogut carregar la imatge. BMP/TIFF requereixen GD amb suport o Imagick al servidor.';
        }
        if ( 'gif' === $ext || 'image/gif' === $mime ) {
            return 'No s\'ha pogut carregar el GIF (pot estar corrupte o ser animat).';
        }
        if ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
            return 'No s\'ha pogut carregar la imatge. Verifica que el servidor tingui suport GD per a ' . strtoupper( $ext ) . '.';
        }
        return 'No s\'ha pogut carregar la imatge amb GD.';
    }

    /**
     * Returns true when GIF contains more than one frame.
     *
     * @param string $file_path Absolute path.
     * @return bool
     */
    private static function is_animated_gif( $file_path ) {
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;
        WP_Filesystem();

        if ( ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'get_contents' ) ) {
            return true;
        }

        $bytes = $wp_filesystem->get_contents( $file_path );
        if ( ! is_string( $bytes ) || '' === $bytes ) {
            return true;
        }

        return preg_match_all( '#\x00\x21\xF9\x04.{4}\x00\x2C#s', $bytes ) > 1;
    }

    private static function load_image( $path, $mime ) {
        switch ( $mime ) {
            case 'image/jpeg':
                return function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $path ) : false;
            case 'image/png':
                $img = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $path ) : false;
                if ( $img ) {
                    imagealphablending( $img, true );
                    imagesavealpha( $img, true );
                }
                return $img;
            case 'image/gif':
                return function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $path ) : false;
            case 'image/webp':
                return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
            case 'image/avif':
                return function_exists( 'imagecreatefromavif' ) ? @imagecreatefromavif( $path ) : false;
            case 'image/bmp':
            case 'image/x-ms-bmp':
            case 'image/x-bmp':
                if ( function_exists( 'imagecreatefrombmp' ) ) {
                    return @imagecreatefrombmp( $path );
                }
                return self::load_image_via_imagick( $path );
            case 'image/tiff':
            case 'image/x-tiff':
            case 'image/tif':
                return self::load_image_via_imagick( $path );
            default:
                return false;
        }
    }

    /**
     * Flatten transparency onto white before saving as JPEG (WebP/PNG → JPG).
     *
     * @param resource|\GdImage $image     GD image resource.
     * @param string            $target_ext Target extension.
     * @return resource|\GdImage
     */
    private static function prepare_image_for_output( $image, $target_ext ) {
        if ( ! in_array( strtolower( (string) $target_ext ), array( 'jpg', 'jpeg' ), true ) ) {
            return $image;
        }

        $width  = imagesx( $image );
        $height = imagesy( $image );
        if ( $width <= 0 || $height <= 0 ) {
            return $image;
        }

        $canvas = imagecreatetruecolor( $width, $height );
        if ( ! $canvas ) {
            return $image;
        }

        $white = imagecolorallocate( $canvas, 255, 255, 255 );
        imagefill( $canvas, 0, 0, $white );
        imagealphablending( $canvas, true );
        imagecopy( $canvas, $image, 0, 0, 0, 0, $width, $height );
        imagedestroy( $image );

        return $canvas;
    }

    /**
     * Convert one image file on disk to another format.
     *
     * @param string $source_path  Existing file.
     * @param string $dest_path    Destination file.
     * @param string $output_format Output format key (webp, jpg, original).
     * @param int    $quality      Quality 1-100.
     * @return bool
     */
    private static function convert_file_to_format( $source_path, $dest_path, $output_format, $quality ) {
        if ( ! file_exists( $source_path ) ) {
            return false;
        }

        $mime = mime_content_type( $source_path );
        if ( false === strpos( (string) $mime, 'image/' ) ) {
            return false;
        }

        $image = self::load_image( $source_path, $mime );
        if ( ! $image ) {
            return false;
        }

        $dest_ext = strtolower( pathinfo( $dest_path, PATHINFO_EXTENSION ) );
        if ( '' === $dest_ext ) {
            $dest_ext = self::get_extension( $output_format, pathinfo( $source_path, PATHINFO_EXTENSION ) );
        }

        $ok = self::save_image_resource_to_path( $image, $dest_path, $dest_ext, $quality );
        if ( ! $ok ) {
            self::delete_file_if_exists( $dest_path );
        }
        return $ok;
    }

    /**
     * Normalize requested output format (fallback when WebP is unavailable).
     *
     * @param string $output_format Requested format.
     * @return string
     */
    private static function normalize_output_format( $output_format ) {
        $output_format = sanitize_key( (string) $output_format );
        $allowed       = array( 'webp', 'jpg', 'jpeg', 'avif', 'png', 'original' );
        if ( ! in_array( $output_format, $allowed, true ) ) {
            $output_format = 'webp';
        }
        if ( 'jpeg' === $output_format ) {
            $output_format = 'jpg';
        }
        if ( 'webp' === $output_format && ! self::webp_supported() ) {
            return 'jpg';
        }
        if ( 'avif' === $output_format && ! self::avif_supported() ) {
            return self::webp_supported() ? 'webp' : 'jpg';
        }
        return $output_format;
    }

    /**
     * Save a GD image resource to disk with validation.
     *
     * @param resource|\GdImage $image  GD image.
     * @param string            $path   Destination path.
     * @param string            $ext    Target extension.
     * @param int               $quality Quality.
     * @return bool
     */
    private static function save_image_resource_to_path( $image, $path, $ext, $quality ) {
        if ( ! $image ) {
            return false;
        }
        $image = self::prepare_image_for_output( $image, $ext );
        if ( in_array( strtolower( (string) $ext ), array( 'webp', 'avif', 'png' ), true ) ) {
            $image = self::ensure_truecolor_image( $image, true );
        }
        $saved = self::save_image( $image, $path, $ext, $quality );
        imagedestroy( $image );
        clearstatcache( true, $path );
        if ( $saved && self::is_valid_image_file( $path, 1 ) ) {
            return true;
        }
        self::delete_file_if_exists( $path );
        return false;
    }

    /**
     * Convert palette/indexed GD images to truecolor (required for reliable WebP output).
     *
     * @param resource|\GdImage|null $image          GD image.
     * @param bool                   $preserve_alpha Keep alpha channel when possible.
     * @return resource|\GdImage|null
     */
    private static function ensure_truecolor_image( $image, $preserve_alpha = true ) {
        if ( ! $image ) {
            return $image;
        }

        if ( function_exists( 'imageistruecolor' ) && imageistruecolor( $image ) ) {
            if ( $preserve_alpha ) {
                imagealphablending( $image, false );
                imagesavealpha( $image, true );
            }
            return $image;
        }

        $width  = imagesx( $image );
        $height = imagesy( $image );
        if ( $width <= 0 || $height <= 0 ) {
            return $image;
        }

        $canvas = imagecreatetruecolor( $width, $height );
        if ( ! $canvas ) {
            return $image;
        }

        if ( $preserve_alpha ) {
            imagealphablending( $canvas, false );
            imagesavealpha( $canvas, true );
            $transparent = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
            imagefilledrectangle( $canvas, 0, 0, $width, $height, $transparent );
            imagealphablending( $canvas, true );
        } else {
            $white = imagecolorallocate( $canvas, 255, 255, 255 );
            imagefill( $canvas, 0, 0, $white );
        }

        imagecopy( $canvas, $image, 0, 0, 0, 0, $width, $height );
        imagedestroy( $image );

        return $canvas;
    }

    /**
     * Delete WordPress "-scaled.{ext}" leftover when the main extension changes.
     *
     * @param string $dir      Directory path.
     * @param string $filename Base filename without extension.
     * @param string $ext      Old extension.
     * @return void
     */
    private static function delete_scaled_variant_if_exists( $dir, $filename, $ext ) {
        $ext = strtolower( (string) $ext );
        if ( '' === $ext ) {
            return;
        }
        $scaled_path = trailingslashit( (string) $dir ) . $filename . '-scaled.' . $ext;
        if ( file_exists( $scaled_path ) ) {
            wp_delete_file( $scaled_path );
        }
    }

    private static function save_image( $image, $path, $ext, $quality ) {
        switch ( strtolower( $ext ) ) {
            case 'webp':
                if ( ! function_exists( 'imagewebp' ) ) return false;
                return imagewebp( $image, $path, $quality );
            case 'jpg':
            case 'jpeg':
                return imagejpeg( $image, $path, $quality );
            case 'png':
                // imagepng() compression level must be 0–9 (quality 100→0, quality 0→9).
                $png_q = (int) round( ( 100 - max( 0, min( 100, (int) $quality ) ) ) / 10 );
                $png_q = max( 0, min( 9, $png_q ) );
                return imagepng( $image, $path, $png_q );
            case 'gif':
                return imagegif( $image, $path );
            case 'avif':
                if ( ! function_exists( 'imageavif' ) ) {
                    return false;
                }
                return imageavif( $image, $path, $quality );
        }
        return false;
    }

    /**
     * Compare file extensions (jpg/jpeg treated as equivalent).
     *
     * @param string $a Extension A.
     * @param string $b Extension B.
     * @return bool
     */
    public static function extensions_match( $a, $b ) {
        $a = strtolower( (string) $a );
        $b = strtolower( (string) $b );
        if ( $a === $b ) {
            return true;
        }
        $jpg_family = array( 'jpg', 'jpeg' );
        return in_array( $a, $jpg_family, true ) && in_array( $b, $jpg_family, true );
    }

    private static function get_extension( $output_format, $original_ext ) {
        if ( $output_format === 'original' ) {
            $ext = strtolower( $original_ext );
            return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true ) ? $ext : 'jpg';
        }
        if ( $output_format === 'webp' )     return 'webp';
        if ( $output_format === 'avif' )     return 'avif';
        if ( $output_format === 'jpg' )      return 'jpg';
        if ( $output_format === 'png' )      return 'png';
        return strtolower( $original_ext );
    }

    private static function load_image_via_imagick( $path ) {
        if ( ! class_exists( 'Imagick' ) ) return false;
        try {
            $im = new \Imagick();
            $im->readImage( $path );
            if ( $im->getNumberImages() > 1 ) {
                $im->setIteratorIndex( 0 );
            }
            $im = $im->coalesceImages();
            $im->setImageFormat( 'png' );
            $blob = $im->getImageBlob();
            $im->clear();
            $im->destroy();
            return $blob ? @imagecreatefromstring( $blob ) : false;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private static function encode_rel_path_for_url( $rel_path ) {
        $rel_path = str_replace( '\\', '/', ltrim( (string) $rel_path, '/' ) );
        if ( '' === $rel_path ) return '';
        $parts = array_map( 'rawurlencode', explode( '/', $rel_path ) );
        return implode( '/', $parts );
    }

    private static function ext_to_mime( $ext ) {
        $map = array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
        );
        return isset( $map[ strtolower( $ext ) ] ) ? $map[ strtolower( $ext ) ] : 'image/jpeg';
    }

    /**
     * Normalizes attached-file values for reliable comparisons.
     */
    private static function normalize_attached_file_meta_value( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = trim( str_replace( '\\', '/', $value ) );
        return ltrim( $value, '/' );
    }

    /**
     * Detects dangerous mismatch between backup context and current file.
     *
     * New backups: compare stored _wp_attached_file snapshot with current _wp_attached_file.
     * Legacy backups: fallback to filename heuristic (backup base name vs current base name).
     */
    private static function is_backup_context_mismatch( $backup_path, $stored_attached_file, $current_attached_file ) {
        $stored_attached_file  = self::normalize_attached_file_meta_value( $stored_attached_file );
        $current_attached_file = self::normalize_attached_file_meta_value( $current_attached_file );

        if ( $stored_attached_file !== '' && $current_attached_file !== '' ) {
            return $stored_attached_file !== $current_attached_file;
        }

        $backup_base_name = pathinfo( (string) $backup_path, PATHINFO_FILENAME );
        $current_base_name = pathinfo( (string) $current_attached_file, PATHINFO_FILENAME );

        if ( $backup_base_name === '' || $current_base_name === '' ) {
            return false;
        }

        if ( preg_match( '/^(.*)_tso_im_backup$/', $backup_base_name, $matches ) !== 1 || empty( $matches[1] ) ) {
            return false;
        }

        return $matches[1] !== $current_base_name;
    }

    /**
     * Returns the backup path for an attachment file.
     *
     * Backups are stored in a dedicated plugin subdirectory inside uploads:
     *   wp-content/uploads/tso-image-master/YYYY/MM/filename_tso_im_backup.ext
     *
     * This keeps backups out of the general media tree (WP.org guideline) and
     * ensures the folder is never deleted on plugin upgrade (unlike the plugin dir).
     *
     * @param string $original_path  Absolute path of the original file.
     * @param string $ext            Extension to use for the backup file.
     * @return string  Absolute path for the backup file.
     */
    private static function get_backup_path( $original_path, $ext ) {
        $upload_dir   = wp_upload_dir();
        $backup_base  = trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master';
        $basedir_norm = wp_normalize_path( $upload_dir['basedir'] );
        $rel          = ltrim( str_replace( $basedir_norm, '', wp_normalize_path( $original_path ) ), '/' );
        $rel_dir      = dirname( $rel );
        $filename     = pathinfo( $original_path, PATHINFO_FILENAME );

        $dest_dir = ( '.' === $rel_dir || '' === $rel_dir )
            ? $backup_base
            : $backup_base . '/' . $rel_dir;

        if ( ! file_exists( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }

        return wp_normalize_path( $dest_dir . '/' . $filename . '_tso_im_backup.' . strtolower( $ext ) );
    }


}
