<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_Image_Manager {

    /**
     * Reanomena el fitxer físic d'un attachment i actualitza totes les metadades
     * i referències a la base de dades.
     *
     * @param int    $attachment_id
     * @param string $new_name  Nom nou SENSE extensió (s'aplicarà slugify automàtic)
     * @return array|WP_Error
     */
    public static function rename( $attachment_id, $new_name, $args = array() ) {
        $attachment_id = absint( $attachment_id );
        $file_path     = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Fitxer no trobat.' );
        }

        // IMPORTANT: el rename manual mai ha d'entrar en mode SEO estricte per defecte.
        // Només s'activa quan el client ho demana explícitament via $args['strict_seo'].
        $strict_seo = isset( $args['strict_seo'] ) ? (bool) $args['strict_seo'] : false;

        // Per defecte preservem UTF-8 (ex: "març"); el mode SEO estricte és optatiu.
        $target_name = self::normalize_rename_name( $new_name, $strict_seo );
        if ( empty( $target_name ) ) {
            return new WP_Error( 'invalid_name', 'El nom nou no és vàlid.' );
        }

        $pi          = pathinfo( $file_path );
        $upload_dir  = wp_upload_dir();
        $base_dir    = $pi['dirname'];
        $ext         = strtolower( $pi['extension'] );

        // Comprovar que el nom no és ja l'actual
        if ( $pi['filename'] === $target_name ) {
            return new WP_Error( 'same_name', 'El nom és idèntic a l\'actual.' );
        }

        // Comprovar col·lisió de noms
        $new_path = $base_dir . '/' . $target_name . '.' . $ext;
        if ( file_exists( $new_path ) ) {
            return new WP_Error( 'name_collision', 'Ja existeix un fitxer amb aquest nom.' );
        }

        $old_url      = wp_get_attachment_url( $attachment_id );
        $old_dir_url  = trailingslashit( dirname( (string) $old_url ) );
        $old_basename = $pi['filename'];
        $meta         = wp_get_attachment_metadata( $attachment_id );
        $url_pairs    = array();

        if ( ! is_array( $meta ) ) {
            $meta = array();
        }

        // Snapshot thumbnail filenames before any filesystem change.
        $old_thumb_files = array();
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size_key => $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $old_thumb_files[ $size_key ] = (string) $size_data['file'];
                }
            }
        }

        // Copy main file first; delete originals only after validated copies succeed.
        if ( ! TSOIMMA_Optimizer::copy_file_validated( $file_path, $new_path ) ) {
            TSOIMMA_Optimizer::delete_file_if_exists( $new_path );
            return new WP_Error( 'rename_failed', 'No s\'ha pogut reanomenar el fitxer.' );
        }

        $files_to_delete = array( $file_path );

        // Reanomenar thumbnails (preservem el sufix de dimensions del nom real del fitxer).
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size_key => $size_data ) {
                if ( empty( $old_thumb_files[ $size_key ] ) ) {
                    continue;
                }

                $old_thumb_file = $old_thumb_files[ $size_key ];
                $old_thumb      = $base_dir . '/' . $old_thumb_file;
                if ( ! file_exists( $old_thumb ) ) {
                    continue;
                }

                $thumb_pi   = pathinfo( $old_thumb );
                $thumb_ext  = strtolower( isset( $thumb_pi['extension'] ) ? $thumb_pi['extension'] : $ext );
                $dims_suffix = '';

                if ( preg_match( '/-(\d+x\d+)$/', $thumb_pi['filename'], $dims_match ) ) {
                    $dims_suffix = '-' . $dims_match[1];
                } elseif ( ! empty( $size_data['width'] ) && ! empty( $size_data['height'] ) ) {
                    $dims_suffix = '-' . absint( $size_data['width'] ) . 'x' . absint( $size_data['height'] );
                }

                $new_thumb_name = $target_name . $dims_suffix . '.' . $thumb_ext;
                $new_thumb_path = $base_dir . '/' . $new_thumb_name;

                if ( TSOIMMA_Optimizer::copy_file_validated( $old_thumb, $new_thumb_path ) ) {
                    $files_to_delete[] = $old_thumb;
                    $meta['sizes'][ $size_key ]['file'] = $new_thumb_name;

                    $old_thumb_url = $old_dir_url . self::encode_rel_path_for_url( $old_thumb_file );
                    $new_thumb_url = $old_dir_url . self::encode_rel_path_for_url( $new_thumb_name );
                    if ( $old_thumb_url !== $new_thumb_url ) {
                        $url_pairs[ md5( $old_thumb_url ) ] = array( $old_thumb_url, $new_thumb_url );
                    }
                }
            }
        }

        // Reanomenar variants "-scaled" de WordPress si existeixen.
        foreach ( array( $ext, 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ) as $scaled_ext ) {
            $scaled_ext = strtolower( (string) $scaled_ext );
            if ( '' === $scaled_ext ) {
                continue;
            }
            $old_scaled_name = $old_basename . '-scaled.' . $scaled_ext;
            $old_scaled_path = $base_dir . '/' . $old_scaled_name;
            if ( ! file_exists( $old_scaled_path ) ) {
                continue;
            }

            $new_scaled_name = $target_name . '-scaled.' . $scaled_ext;
            $new_scaled_path = $base_dir . '/' . $new_scaled_name;
            if ( TSOIMMA_Optimizer::copy_file_validated( $old_scaled_path, $new_scaled_path ) ) {
                $files_to_delete[] = $old_scaled_path;

                $old_scaled_url = $old_dir_url . self::encode_rel_path_for_url( $old_scaled_name );
                $new_scaled_url = $old_dir_url . self::encode_rel_path_for_url( $new_scaled_name );
                if ( $old_scaled_url !== $new_scaled_url ) {
                    $url_pairs[ md5( $old_scaled_url ) ] = array( $old_scaled_url, $new_scaled_url );
                }
            }
        }

        foreach ( $files_to_delete as $old_file ) {
            wp_delete_file( $old_file );
        }

        // Actualitzar meta de WordPress
        $meta['file'] = str_replace( $pi['basename'], $target_name . '.' . $ext, $meta['file'] ?? $target_name . '.' . $ext );
        update_attached_file( $attachment_id, $new_path );
        wp_update_attachment_metadata( $attachment_id, $meta );

        // Calcular nova URL
        $attached_rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( ! is_string( $attached_rel ) || $attached_rel === '' ) {
            $attached_rel = ltrim( str_replace( wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) ), '', wp_normalize_path( $new_path ) ), '/' );
        }
        $new_url     = trailingslashit( $upload_dir['baseurl'] ) . self::encode_rel_path_for_url( $attached_rel );
        $new_dir_url = trailingslashit( dirname( $new_url ) );

        if ( $old_url && $new_url && $old_url !== $new_url ) {
            $url_pairs[ md5( $old_url ) ] = array( $old_url, $new_url );
        }

        // Actualitzar guid
        global $wpdb;
        $wpdb->update( $wpdb->posts, array( 'guid' => $new_url ), array( 'ID' => $attachment_id ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        // Reemplaçar URLs a posts, postmeta i options (pares exactes + variants -WxH/-scaled).
        if ( ! empty( $url_pairs ) ) {
            TSOIMMA_Optimizer::replace_url_pairs_in_content( array_values( $url_pairs ) );
        }
        TSOIMMA_Optimizer::replace_basename_dimension_urls_in_storage(
            $old_dir_url,
            $old_basename,
            $new_dir_url,
            $target_name,
            $ext
        );

        // Actualitzar el post_name (slug) de l'attachment
        wp_update_post( [
            'ID'        => $attachment_id,
            'post_name' => sanitize_title( $target_name ),
        ] );

        return [
            'attachment_id' => $attachment_id,
            'old_filename'  => $pi['basename'],
            'new_filename'  => $target_name . '.' . $ext,
            'old_url'       => $old_url,
            'new_url'       => $new_url,
        ];
    }

    /**
     * Actualitza el títol i/o text alternatiu d'una imatge.
     */
    public static function update_seo_fields( $attachment_id, $title = null, $alt = null, $description = null, $caption = null ) {
        $attachment_id = absint( $attachment_id );
        $post_data     = [ 'ID' => $attachment_id ];

        if ( $title !== null ) {
            $post_data['post_title'] = sanitize_text_field( $title );
        }
        if ( $description !== null ) {
            $post_data['post_content'] = wp_kses_post( $description );
        }
        if ( $caption !== null ) {
            $post_data['post_excerpt'] = sanitize_text_field( $caption );
        }

        $result = wp_update_post( $post_data, true );
        if ( is_wp_error( $result ) ) return $result;

        if ( $alt !== null ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        }

        return [
            'attachment_id' => $attachment_id,
            'title'         => get_the_title( $attachment_id ),
            'alt'           => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
        ];
    }

    /**
     * Elimina permanentment un o diversos attachments i els seus fitxers.
     *
     * @param int|array $attachment_ids
     * @return array  [ 'deleted' => [...], 'errors' => [...] ]
     */
    public static function delete( $attachment_ids ) {
        if ( ! is_array( $attachment_ids ) ) {
            $attachment_ids = array( $attachment_ids );
        }

        $deleted = array();
        $errors  = array();

        foreach ( $attachment_ids as $id ) {
            $id   = absint( $id );
            $file = get_attached_file( $id );

            // ── Eliminar fitxer de backup TSO del disc ─────────────────
            // wp_delete_attachment elimina els postmeta però NO coneix el fitxer
            // de backup (_tso_im_backup.jpg) perquè no és a metadata['sizes'].
            // Cal llegir el path ABANS de cridar wp_delete_attachment (que esborraria
            // el postmeta _tso_im_backup_file i perdríem la referència).
            $stored_backup = get_post_meta( $id, '_tso_im_backup_file', true );
            $safe_backup   = TSOIMMA_Optimizer::resolve_backup_path( $stored_backup, false );
            if ( false !== $safe_backup ) {
                if ( file_exists( $safe_backup ) ) {
                    wp_delete_file( $safe_backup );
                }
                TSOIMMA_Optimizer::prune_empty_backup_dirs( $safe_backup );
            }

            // ── Eliminar fitxer temporal de compressió PDF si existeix ─
            $pdf_temp = get_post_meta( $id, '_tso_im_pdf_bg_temp', true );
            if ( $pdf_temp && file_exists( $pdf_temp ) ) {
                wp_delete_file( $pdf_temp );
            }

            // ── Eliminar entrades de l'historial TSO ───────────────────
            // wp_delete_attachment no toca la taula tso_im_history (taula custom).
            TSOIMMA_History::delete_by_attachment( $id );

            // ── Eliminar el post, tots els postmeta i els thumbnails ───
            // wp_delete_attachment( $id, true ):
            //   true = force delete (salta la paperera)
            //   Elimina: post, tots els postmeta, fitxer principal, thumbnails de metadata['sizes']
            $res = wp_delete_attachment( $id, true );

            if ( $res ) {
                $deleted[] = array( 'id' => $id, 'file' => $file );
            } else {
                $errors[] = array( 'id' => $id, 'error' => 'No s\'ha pogut eliminar.' );
            }
        }

        // ── Netejar caches de plugins de cache ────────────────────────
        if ( ! empty( $deleted ) ) {
            if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
            do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
            if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
            if ( function_exists( 'w3tc_flush_all' ) )      w3tc_flush_all();
            if ( function_exists( 'wpfc_clear_all_cache' ) ) wpfc_clear_all_cache();
        }

        return array( 'deleted' => $deleted, 'errors' => $errors );
    }

    /**
     * Retorna llista paginada d'imatges amb info SEO.
     * $sort: 'date' | 'filesize' | 'modified'
     */
    public static function get_images_list( $page = 1, $per_page = 30, $search = '', $sort = 'date' ) {
        // filesize: cal ordenar en PHP (WP no indexa mida de fitxer)
        // search: filtre propi UTF-8 per prefix del nom (evita "ar" dins "mar").
        $search    = trim( (string) $search );
        $fetch_all = ( $sort === 'filesize' || $search !== '' );

        $orderby = 'date';
        $order   = 'DESC';
        if ( $sort === 'modified' ) {
            $orderby = 'modified';
        }

        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $fetch_all ? -1 : $per_page,
            'paged'          => $fetch_all ? 1 : $page,
            'orderby'        => $orderby,
            'order'          => $order,
            'no_found_rows'  => false,
        ];

        // IMPORTANT: no usem $args['s'] per cerca perquè WP pot fer coincidències
        // massa laxes amb diacrítics/variacions. Filtrarem nosaltres després.

        $query = new WP_Query( $args );
        $items = [];

        foreach ( $query->posts as $post ) {
            $file_path   = get_attached_file( $post->ID );
            $raw_size    = ( $file_path && file_exists( $file_path ) ) ? filesize( $file_path ) : 0;
            // Llegir mime directament del fitxer per tenir-lo actualitzat (fix bug WebP)
            $real_mime   = ( $file_path && file_exists( $file_path ) ) ? mime_content_type( $file_path ) : $post->post_mime_type;
            $real_ext    = self::mime_to_ext( $real_mime );

            $items[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'alt'          => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
                'caption'      => $post->post_excerpt,
                'description'  => $post->post_content,
                'filename'     => basename( $file_path ?? '' ),
                'url'          => wp_get_attachment_url( $post->ID ),
                'thumb'        => wp_get_attachment_image_url( $post->ID, 'medium' ),
                'filesize'     => $raw_size > 0 ? size_format( $raw_size ) : '—',
                'filesize_raw' => $raw_size,
                'mime'         => $real_mime,
                'ext'          => strtoupper( $real_ext ),
                'date'         => get_the_date( 'd/m/Y', $post->ID ),
                'slug_ok'      => self::is_seo_filename( basename( $file_path ?? '' ) ),
            ];
        }

        // Filtre de cerca estricte UTF-8: el nom base (sense extensió) ha de començar pel terme.
        if ( $search !== '' ) {
            $items = array_values( array_filter( $items, function( $item ) use ( $search ) {
                $base_filename = pathinfo( (string) $item['filename'], PATHINFO_FILENAME );
                return self::starts_with_utf8( $base_filename, $search );
            } ) );
        }

        // Ordenar per filesize si cal
        if ( $fetch_all ) {
            usort( $items, function( $a, $b ) {
                return $b['filesize_raw'] - $a['filesize_raw'];
            } );
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
        ];
    }

    /**
     * Case-insensitive UTF-8 prefix check without accent folding.
     * This keeps "ñ" different from "n" and avoids "ar" matching "mar".
     */
    private static function starts_with_utf8( $haystack, $needle ) {
        $haystack = (string) $haystack;
        $needle   = (string) $needle;
        if ( $needle === '' ) {
            return true;
        }
        if ( function_exists( 'mb_stripos' ) ) {
            return mb_stripos( $haystack, $needle, 0, 'UTF-8' ) === 0;
        }
        return 0 === strncasecmp( $haystack, $needle, strlen( $needle ) );
    }

    /**
     * Cerca tots els posts/pàgines on s'usa un attachment.
     * Cobreix: URL absoluta, URL relativa, nom de fitxer,
     * ID en blocs Gutenberg ("id":123), post_parent,
     * featured image (_thumbnail_id) i postmeta genèric (ACF, etc).
     */
    public static function get_used_in_posts( $attachment_id ) {
        global $wpdb;

        $found    = [];   // [ post_id => data ]
        $file     = get_attached_file( $attachment_id );
        $url      = wp_get_attachment_url( $attachment_id );
        $filename = $file ? basename( $file ) : '';

        // ── Helper intern ──────────────────────────────────────────
        $add = function( $rows, $how = '' ) use ( &$found ) {
            foreach ( $rows as $row ) {
                if ( isset( $found[ $row->ID ] ) ) continue;
                $found[ $row->ID ] = [
                    'id'      => (int) $row->ID,
                    'title'   => $row->post_title ?: '(sense títol)',
                    'type'    => $row->post_type,
                    'status'  => $row->post_status,
                    'url'     => get_permalink( $row->ID ),
                    'how'     => $how,
                    'featured'=> ( $how === 'featured' ),
                ];
            }
        };

        // $base_where conté una condicio SQL estatica (sense dades d'usuari).
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $base_where = "AND post_status NOT IN ('trash','auto-draft')
                       AND post_type NOT IN ('attachment','revision','nav_menu_item')";

        // 1. URL absoluta en post_content
        if ( $url ) {
            $add( $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts}
                 WHERE post_content LIKE %s $base_where LIMIT 20",
                '%' . $wpdb->esc_like( $url ) . '%'
            ) ), 'contingut' );
        }

        // 2. URL relativa (sense domini) en post_content
        if ( $url ) {
            $upload_dir  = wp_upload_dir();
            $relative    = str_replace( $upload_dir['baseurl'], '', $url );
            if ( $relative !== $url ) {
                $add( $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts}
                     WHERE post_content LIKE %s $base_where LIMIT 20",
                    '%' . $wpdb->esc_like( $relative ) . '%'
                ) ), 'contingut' );
            }
        }

        // 3. Nom de fitxer en post_content (cobreix Classic Editor sense domini complet)
        if ( $filename ) {
            $add( $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts}
                 WHERE post_content LIKE %s $base_where LIMIT 20",
                '%' . $wpdb->esc_like( $filename ) . '%'
            ) ), 'contingut' );
        }

        // 4. ID en blocs Gutenberg: "id":123 o \"id\":123
        $add( $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts}
             WHERE (post_content LIKE %s OR post_content LIKE %s) $base_where LIMIT 20",
            '%"id":' . $attachment_id . '%',
            '%\"id\":' . $attachment_id . '%'
        ) ), 'bloc Gutenberg' );

        // 5. Featured image (_thumbnail_id)
        $add( $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT p.ID, p.post_title, p.post_type, p.post_status
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s
               AND p.post_status NOT IN ('trash','auto-draft') LIMIT 10",
            $attachment_id
        ) ), 'featured' );

        // 6. Postmeta genèric (ACF image, Elementor, etc.) — valor = ID
        $add( $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_value = %s
               AND pm.meta_key NOT IN ('_thumbnail_id','_wp_attachment_metadata','_wp_attached_file')
               AND p.post_status NOT IN ('trash','auto-draft')
               AND p.post_type NOT IN ('attachment','revision') LIMIT 10",
            $attachment_id
        ) ), 'meta (ACF/Elementor)' );

        // 7. Postmeta que conté la URL (Elementor widget, etc.)
        if ( $url ) {
            $add( $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE pm.meta_value LIKE %s
                   AND p.post_status NOT IN ('trash','auto-draft')
                   AND p.post_type NOT IN ('attachment','revision') LIMIT 10",
                '%' . $wpdb->esc_like( $filename ) . '%'
            ) ), 'meta URL' );
        }

        // phpcs:enable

        // 8. post_parent (imatge adjunta directament a un post)
        $parent_id = wp_get_post_parent_id( $attachment_id );
        if ( $parent_id > 0 ) {
            $parent = get_post( $parent_id );
            if ( $parent && ! isset( $found[ $parent_id ] ) ) {
                $found[ $parent_id ] = [
                    'id'      => $parent_id,
                    'title'   => $parent->post_title ?: '(sense títol)',
                    'type'    => $parent->post_type,
                    'status'  => $parent->post_status,
                    'url'     => get_permalink( $parent_id ),
                    'how'     => 'adjunta',
                    'featured'=> false,
                ];
            }
        }

        return array_values( $found );
    }

    /**
     * Converteix mime type a extensió curta.
     */
    private static function mime_to_ext( $mime ) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        return $map[ $mime ] ?? 'img';
    }

    /**
     * Converteix un string a slug compatible amb Google (minúscules, guions, sense caràcters especials).
     */
    public static function slugify( $text ) {
        // Transliterar caràcters especials
        $text = remove_accents( $text );
        $text = strtolower( $text );
        $text = preg_replace( '/[^a-z0-9\-_]/', '-', $text );
        $text = preg_replace( '/-+/', '-', $text );
        $text = trim( $text, '-' );
        return $text;
    }

    /**
     * Normalitza nom manual de rename.
     * strict_seo=true: slug ASCII; strict_seo=false: preserva UTF-8 (ex: català).
     */
    private static function normalize_rename_name( $text, $strict_seo = false ) {
        $text = trim( (string) $text );
        if ( $text === '' ) {
            return '';
        }

        if ( $strict_seo ) {
            return self::slugify( $text );
        }

        // Evitar caràcters invàlids al sistema de fitxers mantenint UTF-8.
        $text = preg_replace( '/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '-', $text );
        $text = preg_replace( '/\s+/u', '-', $text );
        $text = preg_replace( '/-+/u', '-', $text );
        $text = trim( $text, " .-\t\n\r\0\x0B" );
        return $text;
    }

    private static function encode_rel_path_for_url( $rel_path ) {
        $rel_path = str_replace( '\\', '/', ltrim( (string) $rel_path, '/' ) );
        if ( '' === $rel_path ) {
            return '';
        }
        $parts = array_map( 'rawurlencode', explode( '/', $rel_path ) );
        return implode( '/', $parts );
    }

    /**
     * Comprova si un nom de fitxer ja és SEO-friendly.
     */
    public static function is_seo_filename( $filename ) {
        $pi = pathinfo( $filename );
        return $pi['filename'] === self::slugify( $pi['filename'] );
    }

    /**
     * Suggereix un nom de fitxer SEO-friendly per a un attachment.
     */
    public static function suggest_filename( $attachment_id ) {
        $title    = get_the_title( $attachment_id );
        $file     = get_attached_file( $attachment_id );
        $filename = $file ? pathinfo( $file, PATHINFO_FILENAME ) : '';
        $source   = ! empty( $title ) ? $title : $filename;
        return self::slugify( $source );
    }
}
