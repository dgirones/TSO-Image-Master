<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_Image_Manager {

    /**
     * Per-request cache for attachment reference checks.
     *
     * @var array<int, bool>
     */
    private static $referenced_cache = array();

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
            $stored_backup = tsoimma_get_attachment_meta( $id, 'backup_file' );
            $safe_backup   = TSOIMMA_Optimizer::resolve_backup_path( $stored_backup, false );
            if ( false !== $safe_backup ) {
                if ( file_exists( $safe_backup ) ) {
                    wp_delete_file( $safe_backup );
                }
                TSOIMMA_Optimizer::prune_empty_backup_dirs( $safe_backup );
            }

            // ── Eliminar fitxer temporal de compressió PDF si existeix ─
            $pdf_temp = tsoimma_get_attachment_meta( $id, 'pdf_bg_temp' );
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
            $alt_text    = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );

            $items[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'alt'          => $alt_text,
                'alt_ok'       => '' !== trim( $alt_text ) && ! TSOIMMA_Dashboard::is_weak_alt( $alt_text, $post->ID ),
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
     * Whether post content explicitly references an attachment ID (blocks/HTML JSON).
     *
     * @param int $post_id       Post ID.
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function post_content_contains_attachment_id( $post_id, $attachment_id ) {
        $post_id       = absint( $post_id );
        $attachment_id = absint( $attachment_id );
        if ( $post_id <= 0 || $attachment_id <= 0 ) {
            return false;
        }

        $content = (string) get_post_field( 'post_content', $post_id );
        if ( '' === $content ) {
            return false;
        }

        $id = (string) $attachment_id;
        $patterns = array(
            '/\bdata-id="' . preg_quote( $id, '/' ) . '"/',
            '/(?:"|\\\\")id(?:"|\\\\")\s*:\s*' . preg_quote( $id, '/' ) . '(?=[,\}\s])/',
            '/"ids"\s*:\s*\[[^\]]*(?<![0-9])' . preg_quote( $id, '/' ) . '(?![0-9])[^\]]*\]/',
            '/\bwp-image-' . preg_quote( $id, '/' ) . '\b/',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $content ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify how an attachment is referenced (direct ID vs filename-only match).
     *
     * @param int $attachment_id Attachment ID.
     * @return array<string, mixed>
     */
    public static function get_attachment_reference_report( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        $refs          = self::get_used_in_posts( $attachment_id );
        $direct        = array();
        $indirect      = array();

        foreach ( $refs as $ref ) {
            $post_id   = isset( $ref['id'] ) ? absint( $ref['id'] ) : 0;
            $how       = isset( $ref['how'] ) ? (string) $ref['how'] : '';
            $is_direct = false;

            if ( ! empty( $ref['featured'] ) ) {
                $is_direct = true;
            } elseif ( 'adjunta' === $how ) {
                $is_direct = true;
            } elseif ( 'meta (ACF/Elementor)' === $how ) {
                $is_direct = true;
            } elseif ( 'bloc Gutenberg' === $how && self::post_content_contains_attachment_id( $post_id, $attachment_id ) ) {
                $is_direct = true;
            } elseif ( self::post_content_contains_attachment_id( $post_id, $attachment_id ) ) {
                $is_direct = true;
            }

            $ref['edit_url']     = TSOIMMA_Post_Editor_Highlight::get_post_edit_highlight_url( $post_id, $attachment_id );
            $ref['highlight_id'] = $attachment_id;
            $ref['detail']       = $is_direct
                ? self::describe_attachment_usage_in_post( $post_id, $attachment_id )
                : self::describe_indirect_reference( $post_id, $attachment_id, $how );

            if ( $is_direct ) {
                $ref['match'] = 'direct';
                $direct[]     = $ref;
            } else {
                $ref['match'] = 'indirect';
                $indirect[]   = $ref;
            }
        }

        $status = 'none';
        if ( ! empty( $direct ) ) {
            $status = 'embedded';
        } elseif ( ! empty( $indirect ) ) {
            $status = 'filename_only';
        }

        return array(
            'status'        => $status,
            'direct'        => $direct,
            'indirect'      => $indirect,
            'used_in_count' => count( $refs ),
        );
    }

    /**
     * Short label for where an attachment ID appears in post content.
     *
     * @param int $post_id       Post ID.
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    private static function describe_attachment_usage_in_post( $post_id, $attachment_id ) {
        $content = (string) get_post_field( 'post_content', $post_id );
        $id      = (string) absint( $attachment_id );

        if ( preg_match( '/wp:jetpack\/tiled-gallery/', $content )
            && preg_match( '/"ids"\s*:\s*\[[^\]]*(?<![0-9])' . preg_quote( $id, '/' ) . '(?![0-9])[^\]]*\]/', $content ) ) {
            return 'Jetpack Gallery · ids[]';
        }
        if ( preg_match( '/\bdata-id="' . preg_quote( $id, '/' ) . '"/', $content ) ) {
            return 'HTML data-id';
        }
        if ( preg_match( '/wp:image[^>]*"id"\s*:\s*' . preg_quote( $id, '/' ) . '\b/', $content ) ) {
            return 'Bloc imatge';
        }
        if ( preg_match( '/\bwp-image-' . preg_quote( $id, '/' ) . '\b/', $content ) ) {
            return 'Bloc imatge (classe wp-image)';
        }

        return 'ID al contingut';
    }

    /**
     * Explain a weak filename/URL match that does not embed this attachment ID.
     *
     * @param int    $post_id       Post ID.
     * @param int    $attachment_id Attachment ID.
     * @param string $how           Original match channel.
     * @return string
     */
    private static function describe_indirect_reference( $post_id, $attachment_id, $how ) {
        unset( $how );
        $filename = basename( (string) get_attached_file( $attachment_id ) );
        if ( '' === $filename ) {
            return 'Coincidència de nom/URL (aquest ID no és al codi)';
        }

        $content = (string) get_post_field( 'post_content', $post_id );
        if ( preg_match_all( '/\bdata-id="(\d+)"/', $content, $matches ) && ! empty( $matches[1] ) ) {
            foreach ( array_unique( array_map( 'absint', $matches[1] ) ) as $embedded_id ) {
                if ( $embedded_id <= 0 || $embedded_id === $attachment_id ) {
                    continue;
                }
                $embedded_file = get_attached_file( $embedded_id );
                if ( $embedded_file && basename( $embedded_file ) === $filename ) {
                    /* translators: 1: attachment ID used in content, 2: duplicate attachment ID */
                    return sprintf( 'El post usa #%1$d; #%2$d no hi és al codi', $embedded_id, $attachment_id );
                }
            }
        }

        if ( preg_match( '/"ids"\s*:\s*\[([^\]]+)\]/', $content, $ids_match ) ) {
            $ids = array_map( 'absint', preg_split( '/\s*,\s*/', $ids_match[1] ) );
            foreach ( $ids as $embedded_id ) {
                if ( $embedded_id <= 0 || $embedded_id === $attachment_id ) {
                    continue;
                }
                $embedded_file = get_attached_file( $embedded_id );
                if ( $embedded_file && basename( $embedded_file ) === $filename ) {
                    /* translators: 1: attachment ID used in content, 2: duplicate attachment ID */
                    return sprintf( 'El post usa #%1$d (ids[]); #%2$d no hi és al codi', $embedded_id, $attachment_id );
                }
            }
        }

        return 'Coincidència de nom de fitxer (aquest ID no és al codi)';
    }

    /**
     * Fast check whether an attachment is referenced anywhere (inverse of orphan check).
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function is_attachment_referenced( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 ) {
            return false;
        }

        return ! TSOIMMA_Orphan_Finder::is_orphan( $attachment_id );
    }

    /**
     * Cached wrapper for is_attachment_referenced (dashboard alt list).
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function is_attachment_referenced_cached( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 ) {
            return false;
        }

        if ( ! array_key_exists( $attachment_id, self::$referenced_cache ) ) {
            self::$referenced_cache[ $attachment_id ] = self::is_attachment_referenced( $attachment_id );
        }

        return self::$referenced_cache[ $attachment_id ];
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

    /**
     * Suggest alt text from the attachment filename only (ignores title/caption).
     *
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    public static function suggest_alt_from_filename( $attachment_id ) {
        $file_path = get_attached_file( absint( $attachment_id ) );
        $base      = $file_path ? pathinfo( basename( $file_path ), PATHINFO_FILENAME ) : '';
        return self::humanize_filename_for_alt( $base );
    }

    /**
     * Suggest accessible alt text from title or filename.
     *
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    public static function suggest_alt_text( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 ) {
            return '';
        }

        $candidates = array();

        $file_path = get_attached_file( $attachment_id );
        $file_base = $file_path ? pathinfo( basename( $file_path ), PATHINFO_FILENAME ) : '';

        $current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
        if ( '' !== $current_alt && self::is_useful_alt_source( $current_alt ) && '' !== $file_base && function_exists( 'remove_accents' ) ) {
            $fold_alt  = self::normalize_filename_token( remove_accents( $current_alt ) );
            $fold_base = self::normalize_filename_token( remove_accents( $file_base ) );
            if ( $fold_alt === $fold_base && remove_accents( $current_alt ) !== $current_alt ) {
                $candidates[] = self::normalize_alt_source_text( $current_alt );
            }
        }

        $title = trim( (string) get_the_title( $attachment_id ) );
        if ( self::is_useful_alt_source( $title )
            && ! ( '' !== $file_base && self::is_title_just_filename_stem( $title, $file_base ) ) ) {
            $candidates[] = self::normalize_alt_source_text( $title );
        }

        $post = get_post( $attachment_id );
        if ( $post ) {
            $caption = trim( (string) $post->post_excerpt );
            if ( self::is_useful_alt_source( $caption ) ) {
                $candidates[] = $caption;
            }

            $description = trim( wp_strip_all_tags( (string) $post->post_content ) );
            if ( self::is_useful_alt_source( $description ) ) {
                $candidates[] = self::truncate_alt_phrase( $description );
            }
        }

        if ( $file_path && file_exists( $file_path ) ) {
            $exif_hint = self::get_attachment_exif_hint( $file_path );
            if ( self::is_useful_alt_source( $exif_hint ) ) {
                $candidates[] = $exif_hint;
            }
        }

        if ( '' !== $file_base ) {
            $humanized = self::humanize_filename_for_alt( $file_base );
            if ( self::is_useful_alt_source( $humanized ) ) {
                $candidates[] = $humanized;
            }
        }

        $suggested = ! empty( $candidates ) ? (string) $candidates[0] : '';

        /**
         * Filter suggested alt text (e.g. vision/AI plugins may replace or enrich it).
         *
         * @param string $suggested     Current suggestion (may be empty).
         * @param int    $attachment_id Attachment ID.
         */
        return apply_filters( 'tsoimma_suggest_alt_text', $suggested, $attachment_id );
    }

    /**
     * Whether the attachment title is only the filename stem (not a real editorial title).
     *
     * @param string $title Attachment title.
     * @param string $base  Filename without extension.
     * @return bool
     */
    public static function is_title_just_filename_stem( $title, $base ) {
        $title = trim( (string) $title );
        $base  = trim( (string) $base );
        if ( '' === $title || '' === $base ) {
            return false;
        }

        // Multi-word titles are editorial text, not the raw upload stem.
        if ( false !== strpos( $title, ' ' ) ) {
            return false;
        }

        if ( self::normalize_filename_token( $title ) === self::normalize_filename_token( $base ) ) {
            return true;
        }

        // Same stem without accents (espanol vs español): prefer the titled spelling.
        if ( function_exists( 'remove_accents' ) ) {
            $fold_title = self::normalize_filename_token( remove_accents( $title ) );
            $fold_base  = self::normalize_filename_token( remove_accents( $base ) );
            if ( $fold_title === $fold_base && '' !== $fold_title ) {
                return false;
            }
        }

        return false;
    }

    /**
     * Normalize filename/title tokens for comparison.
     *
     * @param string $text Raw text.
     * @return string
     */
    private static function normalize_filename_token( $text ) {
        $text = strtolower( rawurldecode( (string) $text ) );
        return (string) preg_replace( '/[\s\-_\.]+/', '', $text );
    }

    /**
     * Whether a text string is useful as alt text (not generic camera/name noise).
     *
     * @param string $text Candidate text.
     * @return bool
     */
    private static function is_useful_alt_source( $text ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( '' === $text || strlen( $text ) < 2 ) {
            return false;
        }

        if ( preg_match( '/^(IMG[-_]?|DSC[-_]?|DSCN|PICT|PHOTO|PANO|Auto Draft)/i', $text ) ) {
            return false;
        }

        if ( preg_match( '/^\d+$/', $text ) ) {
            return false;
        }

        return true;
    }

    /**
     * Build readable alt text from a filename stem (no extension).
     *
     * @param string $base Filename without extension.
     * @return string
     */
    private static function humanize_filename_for_alt( $base ) {
        $base = trim( rawurldecode( (string) $base ) );
        if ( '' === $base ) {
            return '';
        }

        $base = preg_replace( '/-\d+x\d+$/', '', $base );
        $base = preg_replace( '/-scaled$/i', '', $base );

        if ( preg_match( '/^\d+$/', $base ) ) {
            return '';
        }

        if ( preg_match( '/^(IMG[-_]?|DSC[-_]?|DSCN|PICT|PHOTO|PANO)[-_]?\d+$/i', $base ) ) {
            return '';
        }

        $base = preg_replace( '/([a-z\d])([A-Z])/', '$1 $2', $base );
        $base = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1 $2', $base );
        $base = str_replace( array( '-', '_', '.' ), ' ', $base );
        $base = preg_replace( '/\s+\d{1,3}$/', '', $base );
        $base = preg_replace( '/(?<=[a-zA-Z])(\d{1,3})$/', '', $base );
        $base = preg_replace( '/\s+/u', ' ', trim( $base ) );

        if ( '' === $base || preg_match( '/^\d+$/', $base ) ) {
            return '';
        }

        if ( false === strpos( $base, ' ' ) ) {
            $base = self::split_concatenated_filename_token( $base );
        }

        return self::finalize_alt_phrase( $base );
    }

    /**
     * Normalize attachment title/caption-like sources for alt text.
     *
     * @param string $text Source text.
     * @return string
     */
    private static function normalize_alt_source_text( $text ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( '' === $text ) {
            return '';
        }

        if ( preg_match( '/[-_.]/', $text ) ) {
            $text = str_replace( array( '-', '_', '.' ), ' ', $text );
            $text = preg_replace( '/\s+/u', ' ', trim( $text ) );
        }

        return self::finalize_alt_phrase( $text );
    }

    /**
     * Capitalize and restore common Spanish accents lost in filenames.
     *
     * @param string $text Humanized phrase.
     * @return string
     */
    private static function finalize_alt_phrase( $text ) {
        return self::apply_common_spanish_accents( self::format_alt_phrase( $text ) );
    }

    /**
     * @param string $text Humanized phrase.
     * @return string
     */
    private static function apply_common_spanish_accents( $text ) {
        $text = (string) $text;
        if ( '' === trim( $text ) ) {
            return '';
        }

        $map = array(
            'espanol'   => 'español',
            'espanola'  => 'española',
            'espanoles' => 'españoles',
            'nino'      => 'niño',
            'nina'      => 'niña',
            'munoz'     => 'muñoz',
            'garcia'    => 'garcía',
            'perez'     => 'pérez',
            'martin'    => 'martín',
            'angel'     => 'ángel',
        );

        foreach ( $map as $plain => $accented ) {
            $text = preg_replace(
                '/\b' . preg_quote( $plain, '/' ) . '\b/iu',
                $accented,
                $text
            );
        }

        return $text;
    }

    /**
     * Split glued lowercase tokens only when a known prefix is present.
     *
     * @param string $token Single-word filename stem.
     * @return string
     */
    private static function split_concatenated_filename_token( $token ) {
        $lower = strtolower( (string) $token );
        if ( '' === $lower ) {
            return '';
        }

        $prefixes = array(
            'anti', 'auto', 'pre', 'pro', 'mini', 'mega', 'super', 'ultra',
            'photo', 'image', 'logo', 'icon', 'banner', 'header', 'footer',
            'background', 'bg', 'cover', 'hero', 'thumb', 'mobile', 'desktop',
        );

        foreach ( $prefixes as $prefix ) {
            $prefix_len = strlen( $prefix );
            if ( strlen( $lower ) <= $prefix_len + 2 ) {
                continue;
            }
            if ( 0 === strpos( $lower, $prefix ) ) {
                $rest = substr( $lower, $prefix_len );
                if ( preg_match( '/^[a-z]{2,}$/', $rest ) ) {
                    return $prefix . ' ' . $rest;
                }
            }
        }

        return $lower;
    }

    /**
     * @param string $text Long description/caption.
     * @param int    $max  Max characters.
     * @return string
     */
    private static function truncate_alt_phrase( $text, $max = 125 ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( '' === $text ) {
            return '';
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text ) <= $max ) {
                return $text;
            }
            return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
        }

        if ( strlen( $text ) <= $max ) {
            return $text;
        }

        return rtrim( substr( $text, 0, $max - 1 ) ) . '…';
    }

    /**
     * @param string $file_path Absolute image path.
     * @return string
     */
    private static function get_attachment_exif_hint( $file_path ) {
        if ( ! function_exists( 'wp_read_image_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $meta = wp_read_image_metadata( $file_path );
        if ( ! is_array( $meta ) ) {
            return '';
        }

        foreach ( array( 'caption', 'title' ) as $key ) {
            if ( empty( $meta[ $key ] ) ) {
                continue;
            }
            $hint = trim( (string) $meta[ $key ] );
            if ( self::is_useful_alt_source( $hint ) ) {
                return $hint;
            }
        }

        return '';
    }

    /**
     * @param string $text Humanized phrase.
     * @return string
     */
    private static function format_alt_phrase( $text ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( '' === $text ) {
            return '';
        }

        if ( function_exists( 'mb_strtolower' ) && function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
            $lower = mb_strtolower( $text );
            return mb_strtoupper( mb_substr( $lower, 0, 1 ) ) . mb_substr( $lower, 1 );
        }

        $lower = strtolower( $text );
        return ucfirst( $lower );
    }
}
