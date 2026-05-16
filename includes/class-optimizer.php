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
        $attachment_id = absint( $attachment_id );
        $file_path     = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Fitxer no trobat.' );
        }

        $mime = mime_content_type( $file_path );
        if ( strpos( $mime, 'image/' ) === false ) {
            return new WP_Error( 'not_image', 'El fitxer no és una imatge.' );
        }

        $image = self::load_image( $file_path, $mime );
        if ( ! $image ) {
            return new WP_Error( 'load_failed', 'No s\'ha pogut carregar la imatge amb GD.' );
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

        // Guardar fitxer temporal
        $temp_path = $path_info['dirname'] . '/' . $path_info['filename'] . '_tso_im_opt.' . $new_ext;
        $saved     = self::save_image( $image, $temp_path, $new_ext, $quality );
        imagedestroy( $image );

        if ( ! $saved || ! file_exists( $temp_path ) ) {
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

        // Backup silenciós — només si $make_backup és true
        // En auto-optimització de pujades noves el backup no és necessari
        if ( $make_backup ) {
            @copy( $old_path, $backup_path );
        }

        // Moure temporal → final
        $move_ok = @copy( $temp_path, $final_path );
        wp_delete_file( $temp_path );

        if ( ! $move_ok || ! file_exists( $final_path ) ) {
            wp_delete_file( $backup_path );
            return new WP_Error( 'move_failed', 'No s\'ha pogut escriure el fitxer final. Verifica permisos.' );
        }

        // Eliminar original si l'extensió ha canviat
        if ( strtolower( $old_ext ) !== strtolower( $new_ext ) && file_exists( $old_path ) ) {
            wp_delete_file( $old_path );
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
        if ( $old_ext !== $new_ext && ! empty( $meta['sizes'] ) ) {
            $old_dir_url = trailingslashit( dirname( $old_url ) );
            $new_dir_url = trailingslashit( dirname( $new_url ) );
            foreach ( $meta['sizes'] as $sz_data ) {
                $tfile = isset( $sz_data['file'] ) ? $sz_data['file'] : '';
                if ( ! $tfile ) {
                    continue;
                }
                $pi_t = pathinfo( $tfile );
                if ( empty( $pi_t['extension'] ) || strtolower( $pi_t['extension'] ) !== strtolower( $old_ext ) ) {
                    continue;
                }
                $new_thumb_file = $pi_t['filename'] . '.' . $new_ext;
                $old_thumb_url  = $old_dir_url . self::encode_rel_path_for_url( $tfile );
                $new_thumb_url  = $new_dir_url . self::encode_rel_path_for_url( $new_thumb_file );
                if ( $old_thumb_url !== $new_thumb_url ) {
                    $thumb_replacements[] = array( $old_thumb_url, $new_thumb_url );
                }
            }
        }
        $meta['file']   = $relative_path;
        $meta['width']  = $new_w;
        $meta['height'] = $new_h;

        // Actualitzar noms de thumbnail a la metadata si l'extensió ha canviat
        if ( $old_ext !== $new_ext && ! empty( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $sz => $sz_data ) {
                $tfile = isset( $sz_data['file'] ) ? $sz_data['file'] : '';
                if ( $tfile ) {
                    $pi_t = pathinfo( $tfile );
                    if ( isset( $pi_t['extension'] ) && strtolower( $pi_t['extension'] ) === $old_ext ) {
                        $meta['sizes'][ $sz ]['file']      = $pi_t['filename'] . '.' . $new_ext;
                        $meta['sizes'][ $sz ]['mime-type'] = $new_mime;
                    }
                }
            }
        }
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
                foreach ( array( 'webp', 'jpg', 'jpeg', 'png', 'gif' ) as $try_ext ) {
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

        clean_attachment_cache( $attachment_id );
        if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
        do_action( 'litespeed_purge_post', $attachment_id );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
        do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
        if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
        if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
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

            self::save_image( $image, $new_path, $new_ext, $quality );
            imagedestroy( $image );

            if ( strtolower( $pi['extension'] ) !== strtolower( $new_ext ) ) {
                wp_delete_file( $thumb_path );
                // FIX: usar $thumb_clean_name (sense doble-extensió) en lloc de $pi['filename']
                $metadata['sizes'][ $size ]['file']      = $thumb_clean_name . '.' . $new_ext;
                $metadata['sizes'][ $size ]['mime-type'] = self::ext_to_mime( $new_ext );
            } elseif ( $thumb_clean_name !== $pi['filename'] && file_exists( $new_path ) && $new_path !== $thumb_path ) {
                // Mateix format però nom netejat diferent (doble-extensió al nom): actualitzar path i metadata
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

    private static function replace_url_pairs_in_content( $pairs ) {
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
            if ( $old_ext !== $new_ext ) {
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
                $s, $r, $like
            ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_value LIKE %s AND option_name NOT LIKE %s",
                $s, $r, $like, $wpdb->esc_like( '_transient' ) . '%'
            ) );
        }

        // Regex pass for scaled variants (-WxH), including encoded and decoded URL forms.
        foreach ( $dimension_replacements as $dim_pair ) {
            self::replace_dimension_variant_urls_in_posts( $dim_pair[0], $dim_pair[1], $dim_pair[2], $dim_pair[3] );
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

    private static function replace_dimension_variant_urls_in_posts( $old_url, $new_url, $old_ext, $new_ext ) {
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

        $posts_by_id = array();
        foreach ( $old_variants as $old_variant ) {
            $old_base = preg_replace( '/\.' . preg_quote( (string) $old_ext, '/' ) . '$/i', '', $old_variant );
            if ( ! $old_base ) {
                continue;
            }
            $like = '%' . $wpdb->esc_like( $old_base . '-' ) . '%';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s",
                $like
            ) );
            foreach ( (array) $rows as $row ) {
                $posts_by_id[ (int) $row->ID ] = $row;
            }
        }

        if ( empty( $posts_by_id ) ) {
            return;
        }

        foreach ( $posts_by_id as $row ) {
            $updated = $row->post_content;
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

            if ( $updated !== $row->post_content ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $wpdb->posts,
                    array( 'post_content' => $updated ),
                    array( 'ID'           => (int) $row->ID ),
                    array( '%s' ),
                    array( '%d' )
                );
            }
        }
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

        if ( ! $backup_path || ! file_exists( $backup_path ) ) {
            return new WP_Error( 'no_backup', 'No hi ha còpia de seguretat disponible.' );
        }

        // Safety guard:
        // If the current attached file no longer matches the backup context (usually after rename),
        // block direct revert to prevent restoring over a different file identity.
        if ( self::is_backup_context_mismatch( $backup_path, $backup_attached_file, $current_attached_file ) ) {
            return new WP_Error(
                'backup_mismatch_after_rename',
                'No es pot revertir de forma directa perquè la imatge actual ja no coincideix amb el context del backup (possible rename posterior). Restaura-la primer amb el flux segur de restauració o torna-la a optimitzar per generar un backup nou.'
            );
        }

        $current_path  = get_attached_file( $attachment_id );
        $current_url   = wp_get_attachment_url( $attachment_id );
        $pi_backup     = pathinfo( $backup_path );
        $pi_current    = pathinfo( $current_path );
        $restored_path = $pi_current['dirname'] . '/' . $pi_current['filename'] . '.' . $pi_backup['extension'];

        if ( ! @copy( $backup_path, $restored_path ) ) {
            return new WP_Error( 'copy_failed', 'No s\'ha pogut restaurar el fitxer.' );
        }

        if ( strtolower( $pi_current['extension'] ) !== strtolower( $pi_backup['extension'] ) ) {
            wp_delete_file( $current_path );
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

        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata( $attachment_id, $restored_path )
        );

        clean_attachment_cache( $attachment_id );
        $restored_url = wp_get_attachment_url( $attachment_id );

        if ( $current_url !== $restored_url ) {
            self::replace_url_in_content( $current_url, $restored_url );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->posts,
                array( 'guid' => $restored_url ),
                array( 'ID'   => $attachment_id ),
                array( '%s' ), array( '%d' )
            );
        }

        delete_post_meta( $attachment_id, '_tso_im_backup_file' );
        delete_post_meta( $attachment_id, '_tso_im_backup_mime' );
        delete_post_meta( $attachment_id, '_tso_im_backup_size' );
        delete_post_meta( $attachment_id, '_tso_im_backup_attached_file' );
        delete_post_meta( $attachment_id, '_tso_im_backup_current_name' );
        wp_delete_file( $backup_path );

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

    // ── Helpers privats ─────────────────────────────────────────────

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

    private static function save_image( $image, $path, $ext, $quality ) {
        switch ( strtolower( $ext ) ) {
            case 'webp':
                if ( ! function_exists( 'imagewebp' ) ) return false;
                return imagewebp( $image, $path, $quality );
            case 'jpg':
            case 'jpeg':
                return imagejpeg( $image, $path, $quality );
            case 'png':
                $png_q = (int) round( ( 100 - $quality ) / 10 );
                return imagepng( $image, $path, $png_q );
            case 'gif':
                return imagegif( $image, $path );
        }
        return false;
    }

    private static function get_extension( $output_format, $original_ext ) {
        if ( $output_format === 'original' ) {
            $ext = strtolower( $original_ext );
            return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ? $ext : 'jpg';
        }
        if ( $output_format === 'webp' )     return 'webp';
        if ( $output_format === 'jpg' )      return 'jpg';
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
        $upload_dir  = wp_upload_dir();
        $backup_base = trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master';

        // Backups go directly into the tso-image-master root folder — flat structure.
        // No year/month subdirectories: they would be left empty after deletion.
        // The tso_im_backup suffix already makes the filename unique enough.
        if ( ! file_exists( $backup_base ) ) {
            wp_mkdir_p( $backup_base );
        }

        $filename = pathinfo( $original_path, PATHINFO_FILENAME );
        return $backup_base . '/' . $filename . '_tso_im_backup.' . strtolower( $ext );
    }


}
