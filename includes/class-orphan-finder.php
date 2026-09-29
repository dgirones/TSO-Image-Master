<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_Orphan_Finder {

    /**
     * Retorna llista d'IDs d'attachments d'imatges que no estan referenciats
     * en cap post/pàgina/meta/opció de la base de dades.
     *
     * @param int $limit   Màxim d'attachments a escanejar (0 = tots)
     * @param int $offset  Offset per paginació
     * @return array  [ 'orphans' => [...], 'total_scanned' => int ]
     */
    public static function find( $limit = 200, $offset = 0 ) {
        $limit  = (int) $limit;
        $offset = (int) $offset;

        // Obtenir els attachments d'imatge d'aquest lot
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ];
        $attachment_ids = get_posts( $args );
        $total_scanned  = count( $attachment_ids );

        if ( empty( $attachment_ids ) ) {
            return [ 'orphans' => [], 'total_scanned' => 0 ];
        }

        update_meta_cache( 'post', $attachment_ids );

        // Índex de referències: es construeix UNA vegada per escaneig (lot 0)
        // i es reutilitza als següents lots. Comprovar cada imatge amb consultes
        // pròpies feia 8+ recorreguts de taules per imatge (molt lent).
        $index   = self::get_index( $offset > 0 );
        $orphans = [];

        foreach ( $attachment_ids as $id ) {
            if ( ! self::is_used_in_index( $id, $index ) ) {
                $orphans[] = self::build_orphan_data( $id );
            }
        }

        return [
            'orphans'       => $orphans,
            'total_scanned' => $total_scanned,
        ];
    }

    /**
     * Comprova si un attachment és orfe.
     */
    public static function is_orphan( $attachment_id ) {
        return '' === self::get_reference_reason( $attachment_id );
    }

    /**
     * Retorna el motiu pel qual un attachment NO és orfe (primer lloc on
     * s'ha trobat una referència real), o '' si és orfe.
     *
     * Notes de disseny (falsos "no orfe" evitats):
     * - post_parent NO compta com a ús: WordPress mai no l'esborra, encara
     *   que la imatge s'hagi tret del contingut o no s'hi hagi inserit mai.
     * - Es descarten revisions, esborranys automàtics, paperera i les
     *   pròpies metadades/opcions del plugin (històric, cachés d'escaneig...),
     *   que contenen noms de fitxer/URLs d'imatges sense ser-ne cap ús.
     * - La cerca per ID a postmeta ignora claus internes de WordPress
     *   (_edit_last, _wp_*...) que guarden números sense relació.
     *
     * @param int $attachment_id Attachment ID.
     * @return string Motiu de protecció, o '' si és orfe.
     */
    public static function get_reference_reason( $attachment_id ) {
        global $wpdb;

        // 1b. Personalitzador / Identitat del lloc: custom_logo i site_icon
        // guarden l'ID de l'adjunt directament (no una URL ni un valor de
        // postmeta), així que cap de les cerques d'URL/ID de més avall
        // no els pot trobar mai.
        if ( absint( get_theme_mod( 'custom_logo' ) ) === (int) $attachment_id ) return 'custom_logo';
        if ( absint( get_option( 'site_icon' ) ) === (int) $attachment_id ) return 'site_icon';

        // 1c. Fitxer escrit directament al codi del tema actiu.
        $file_path_for_theme_check = get_attached_file( $attachment_id );
        $filename_for_theme_check  = $file_path_for_theme_check ? basename( $file_path_for_theme_check ) : '';
        if ( $filename_for_theme_check
            && class_exists( 'TSOIMMA_Image_Manager' )
            && '' !== TSOIMMA_Image_Manager::find_filename_in_theme_files( $filename_for_theme_check )
        ) {
            return 'theme_file';
        }

        // 1d. Opció (ID) d'un tema/page-builder.
        if ( class_exists( 'TSOIMMA_Image_Manager' ) ) {
            $opt = TSOIMMA_Image_Manager::find_attachment_id_in_options( $attachment_id );
            if ( '' !== $opt ) {
                return 'option_id:' . $opt;
            }
        }

        // 1e. Opció (nom de fitxer) d'un panell d'opcions del tema.
        if ( $filename_for_theme_check && class_exists( 'TSOIMMA_Image_Manager' ) ) {
            $opt = TSOIMMA_Image_Manager::find_filename_in_options( $filename_for_theme_check );
            if ( '' !== $opt ) {
                return 'option_filename:' . $opt;
            }
        }

        // 2. Patrons de cerca (sempre 2): "2012/09/nom." cobreix la URL completa,
        // la relativa i les versions amb altra extensió; "2012/09/nom-" + "x"
        // cobreix totes les miniatures (nom-300x534.jpg). Una consulta per taula.
        $patterns = self::get_search_patterns( $attachment_id );
        if ( count( $patterns ) < 2 ) return '';
        list( $p1, $p2 ) = $patterns;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // 3. post_content / excerpt (sense revisions ni esborranys automàtics)
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->posts} p
             WHERE ( p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_excerpt LIKE %s OR p.post_excerpt LIKE %s )
             AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('attachment','revision','nav_menu_item')
             LIMIT 1",
            $p1, $p2, $p1, $p2
        ) );
        if ( $found ) return 'post_content';

        $own_a = $wpdb->esc_like( '_tsoimma' ) . '%';
        $own_b = $wpdb->esc_like( 'tsoimma' ) . '%';
        $own_c = '%' . $wpdb->esc_like( 'tso_image_master' ) . '%';

        // 4. post_meta (ACF, Elementor...) — només posts vius, no adjunts
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )
             AND pm.post_id <> %d
             AND pm.meta_key NOT IN ('_edit_last','_edit_lock','_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_wp_old_slug','_wp_old_date','_wp_trash_meta_time','_wp_trash_meta_status')
             AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s
             AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('attachment','revision','nav_menu_item','gal_display_source','displayed_gallery','display_type','lightbox_library','ngg_album','ngg_gallery','ngg_pictures','saved_displayed_gallery','attached_gallery')
             LIMIT 1",
            $p1, $p2, $attachment_id, $own_a, $own_b, $own_c
        ) );
        if ( $found ) return 'postmeta_url';

        // 5. ID exacte a post_meta (_thumbnail_id, ACF...) — posts vius, sense claus internes
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_value = %s
             AND pm.post_id <> %d
             AND pm.meta_key NOT IN ('_edit_last','_edit_lock','_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_wp_old_slug','_wp_old_date','_wp_trash_meta_time','_wp_trash_meta_status')
             AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s
             AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('revision','nav_menu_item','gal_display_source','displayed_gallery','display_type','lightbox_library','ngg_album','ngg_gallery','ngg_pictures','saved_displayed_gallery','attached_gallery')
             LIMIT 1",
            (string) $attachment_id, $attachment_id, $own_a, $own_b, $own_c
        ) );
        if ( $found ) return 'postmeta_id';

        // 6. options (widgets, customizer) — sense transients, logs, cachés ni opcions pròpies
        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE ( option_value LIKE %s OR option_value LIKE %s )
             LIMIT 200",
            $p1, $p2
        ) );
        foreach ( (array) $names as $option_name ) {
            if ( ! TSOIMMA_Image_Manager::is_excluded_option( $option_name ) ) {
                return 'option_url';
            }
        }

        // 7. Site Editor templates, template parts, and block patterns.
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->posts}
             WHERE post_type IN ('wp_template','wp_template_part','wp_block')
             AND post_status != 'trash'
             AND ( post_content LIKE %s OR post_content LIKE %s )
             LIMIT 1",
            $p1, $p2
        ) );
        if ( $found ) return 'site_editor';

        // 8. Term meta (ACF on taxonomies, etc.).
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->termmeta} WHERE ( meta_value LIKE %s OR meta_value LIKE %s ) LIMIT 1",
            $p1, $p2
        ) );
        if ( $found ) return 'termmeta';

        // 9. Navigation menu items referencing attachment ID.
        $found_menu = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'nav_menu_item'
             AND p.post_status != 'trash'
             AND ( pm.meta_key = '_menu_item_object_id' AND pm.meta_value = %s )
             AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = '_menu_item_object' AND pm2.meta_value = 'attachment' )",
            (string) $attachment_id
        ) );
        if ( $found_menu > 0 ) {
            return 'nav_menu';
        }

        // phpcs:enable

        return '';
    }

    // ------------------------------------------------------------------
    // Índex de referències (escaneig ràpid)
    // ------------------------------------------------------------------

    const STEM_REGEX = '~(?:(\d{4}/\d{2})/)?([^\s"\'<>()\\\\/=,;|?&#]+?)\.(?:jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?)(?![A-Za-z0-9])~i';

    /**
     * Retorna l'índex (de la memòria cau si $reuse i encara és vàlid).
     */
    private static function get_index( $reuse ) {
        $index = $reuse ? tsoimma_get_orphan_index_cache() : false;
        if ( ! is_array( $index ) || ! isset( $index['stems'], $index['loose'], $index['ids'] ) ) {
            $index = self::build_index();
            tsoimma_set_orphan_index_cache( $index );
        }
        return $index;
    }

    private static function lower( $text ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
    }

    /**
     * Afegeix un nom d'imatge trobat ("2012/09" + "foto-300x200") a l'índex.
     * Es guarda també la variant sense el sufix de mida (-300x200).
     */
    private static function add_stem( array &$idx, $dir, $name, $loose ) {
        $name = self::lower( rawurldecode( (string) $name ) );
        $dir  = self::lower( (string) $dir );
        if ( '' === $name ) {
            return;
        }
        $variants = array( $name );
        $stripped = preg_replace( '/-\d+x\d+$/', '', $name );
        if ( is_string( $stripped ) && '' !== $stripped && $stripped !== $name ) {
            $variants[] = $stripped;
        }
        foreach ( $variants as $variant ) {
            $idx['stems'][ ( '' !== $dir ? $dir . '/' : '' ) . $variant ] = 1;
            if ( $loose ) {
                $idx['loose'][ $variant ] = 1;
            }
        }
    }

    /**
     * Extreu d'un text (contingut, meta, opció, fitxer del tema) tots els noms
     * d'imatge i, opcionalment, els IDs d'adjunts (galeries, blocs, wp-image-N).
     */
    private static function index_text( array &$idx, $text, $loose, $scan_ids ) {
        if ( ! is_string( $text ) || strlen( $text ) < 5 ) {
            return;
        }
        // Barres invertides de JSON/Elementor ("\/2012\/09\/foto.jpg").
        $text = str_replace( '\\', '', $text );

        $hits = preg_match_all( self::STEM_REGEX . 'u', $text, $m, PREG_SET_ORDER );
        if ( false === $hits ) {
            // Text no UTF-8: reintent en mode bytes.
            $hits = preg_match_all( self::STEM_REGEX, $text, $m, PREG_SET_ORDER );
        }
        if ( $hits ) {
            foreach ( $m as $hit ) {
                self::add_stem( $idx, isset( $hit[1] ) ? $hit[1] : '', $hit[2], $loose );
            }
        }

        if ( ! $scan_ids ) {
            return;
        }

        if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
            foreach ( $m[1] as $n ) { $idx['ids'][ (int) $n ] = 1; }
        }
        if ( preg_match_all( '/data-id="(\d+)"/', $text, $m ) ) {
            foreach ( $m[1] as $n ) { $idx['ids'][ (int) $n ] = 1; }
        }
        // [gallery ids="1,2,3"] / include="1,2,3"
        if ( preg_match_all( '/\b(?:ids|include)\s*=\s*(["\'])([\d,\s]+)\1/', $text, $m ) ) {
            foreach ( $m[2] as $list ) {
                foreach ( preg_split( '/[,\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $n ) { $idx['ids'][ (int) $n ] = 1; }
            }
        }
        // Blocs: "ids":[1,2,3]
        if ( preg_match_all( '/"ids"\s*:\s*\[([^\]]*)\]/', $text, $m ) ) {
            foreach ( $m[1] as $list ) {
                if ( preg_match_all( '/\d+/', $list, $nums ) ) {
                    foreach ( $nums[0] as $n ) { $idx['ids'][ (int) $n ] = 1; }
                }
            }
        }
        // Blocs d'imatge/portada/etc.: {"id":123} i {"mediaId":123}
        if ( preg_match_all( '/wp:(?:image|cover|media-text|audio|video|file)\s+\{[^}]*?"(?:id|mediaId)"\s*:\s*(\d+)/', $text, $m ) ) {
            foreach ( $m[1] as $n ) { $idx['ids'][ (int) $n ] = 1; }
        }
        if ( preg_match_all( '/"mediaId"\s*:\s*(\d+)/', $text, $m ) ) {
            foreach ( $m[1] as $n ) { $idx['ids'][ (int) $n ] = 1; }
        }
    }

    /**
     * Construeix l'índex amb un nombre fix de consultes (no depèn del nombre d'imatges).
     */
    private static function build_index() {
        global $wpdb;

        $idx = array( 'stems' => array(), 'loose' => array(), 'ids' => array() );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // 1. Contingut i extracte de tots els posts vius (pàgines, CPT, plantilles...)
        $last = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
                 WHERE ID > %d AND post_status NOT IN ('trash','auto-draft')
                 AND post_type NOT IN ('attachment','revision','nav_menu_item')
                 ORDER BY ID ASC LIMIT 200",
                $last
            ) );
            foreach ( (array) $rows as $row ) {
                $last = (int) $row->ID;
                self::index_text( $idx, $row->post_content . "\n" . $row->post_excerpt, false, true );
            }
        } while ( ! empty( $rows ) );

        // 2. Postmeta amb noms d'imatge (ACF, Elementor...) — posts vius, no adjunts
        $own_a = $wpdb->esc_like( '_tsoimma' ) . '%';
        $own_b = $wpdb->esc_like( 'tsoimma' ) . '%';
        $own_c = '%' . $wpdb->esc_like( 'tso_image_master' ) . '%';
        $last  = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.meta_id, pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_id > %d
                 AND pm.meta_value REGEXP '[.](jpe?g|png|gif|webp|avif|svg)'
                 AND pm.meta_key NOT IN ('_edit_last','_edit_lock','_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_wp_old_slug','_wp_old_date','_wp_trash_meta_time','_wp_trash_meta_status')
                 AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s
                 AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('attachment','revision','nav_menu_item','gal_display_source','displayed_gallery','display_type','lightbox_library','ngg_album','ngg_gallery','ngg_pictures','saved_displayed_gallery','attached_gallery')
                 ORDER BY pm.meta_id ASC LIMIT 500",
                $last, $own_a, $own_b, $own_c
            ) );
            foreach ( (array) $rows as $row ) {
                $last = (int) $row->meta_id;
                self::index_text( $idx, $row->meta_value, false, false );
            }
        } while ( ! empty( $rows ) );

        // 3. Postmeta amb l'ID exacte (_thumbnail_id, ACF image...)
        $vals = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_value REGEXP '^[0-9]{1,10}$'
             AND pm.meta_key NOT IN ('_edit_last','_edit_lock','_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_wp_old_slug','_wp_old_date','_wp_trash_meta_time','_wp_trash_meta_status')
             AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s
             AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('revision','nav_menu_item','gal_display_source','displayed_gallery','display_type','lightbox_library','ngg_album','ngg_gallery','ngg_pictures','saved_displayed_gallery','attached_gallery')",
            $own_a, $own_b, $own_c
        ) );
        foreach ( (array) $vals as $v ) {
            $idx['ids'][ (int) $v ] = 1;
        }

        // 3b. WooCommerce: galeria de producte "12,34,56"
        $vals = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''" );
        foreach ( (array) $vals as $list ) {
            foreach ( preg_split( '/[,\s]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY ) as $n ) {
                $idx['ids'][ (int) $n ] = 1;
            }
        }

        // 4. Term meta amb noms d'imatge
        $last = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT umeta_id, meta_value FROM {$wpdb->termmeta}
                 WHERE umeta_id > %d
                 AND meta_value REGEXP '[.](jpe?g|png|gif|webp|avif|svg)'
                 ORDER BY umeta_id ASC LIMIT 500",
                $last
            ) );
            foreach ( (array) $rows as $row ) {
                $last = (int) $row->umeta_id;
                self::index_text( $idx, $row->meta_value, false, false );
            }
        } while ( ! empty( $rows ) );

        // 5. Elements de menú que apunten a un adjunt
        $vals = $wpdb->get_col(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'nav_menu_item' AND p.post_status <> 'trash'
             AND pm.meta_key = '_menu_item_object_id'
             AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = '_menu_item_object' AND pm2.meta_value = 'attachment' )"
        );
        foreach ( (array) $vals as $v ) {
            $idx['ids'][ (int) $v ] = 1;
        }

        // 6. Opcions (widgets, customizer, opcions del tema): noms d'imatge...
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE LENGTH(option_value) BETWEEN 5 AND 300000"
        );
        foreach ( (array) $rows as $row ) {
            if ( ! TSOIMMA_Image_Manager::is_excluded_option( $row->option_name ) ) {
                self::index_text( $idx, $row->option_value, true, false );
            }
        }
        // ... i IDs dins d'opcions (mapa compartit amb la pantalla d'imatge).
        foreach ( TSOIMMA_Image_Manager::get_option_ids_map() as $int => $name ) {
            $idx['ids'][ (int) $int ] = 1;
        }

        // phpcs:enable

        // 7. Codi de la plantilla del tema actiu
        foreach ( TSOIMMA_Image_Manager::get_theme_files_contents() as $content ) {
            self::index_text( $idx, $content, true, false );
        }

        // 8. Logo, icona, capçalera i fons del Personalitzador
        $logo = absint( get_theme_mod( 'custom_logo' ) );
        if ( $logo > 0 ) {
            $idx['ids'][ $logo ] = 1;
        }
        $icon = absint( get_option( 'site_icon' ) );
        if ( $icon > 0 ) {
            $idx['ids'][ $icon ] = 1;
        }
        foreach ( array( 'header_image', 'background_image' ) as $mod ) {
            $val = get_theme_mod( $mod );
            if ( is_string( $val ) && '' !== $val ) {
                self::index_text( $idx, $val, true, false );
            }
        }

        return $idx;
    }

    /**
     * Comprova un adjunt contra l'índex (sense consultar la base de dades).
     * post_parent NO compta com a ús.
     */
    private static function is_used_in_index( $attachment_id, array $idx ) {
        $attachment_id = (int) $attachment_id;
        if ( isset( $idx['ids'][ $attachment_id ] ) ) {
            return true;
        }

        $rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( ! is_string( $rel ) || '' === $rel ) {
            return false;
        }

        $dir = dirname( $rel );
        $dir = ( '.' === $dir || '' === $dir ) ? '' : self::lower( trim( $dir, '/' ) );

        $stem       = self::lower( pathinfo( $rel, PATHINFO_FILENAME ) );
        $candidates = array( $stem );
        if ( preg_match( '/^(.+)\.(?:jpe?g|png|gif|webp|avif|svg|bmp)$/', $stem, $m ) ) {
            $candidates[] = $m[1]; // doble extensió (foto.jpg.webp)
        }
        foreach ( $candidates as $candidate ) {
            $unscaled = preg_replace( '/-scaled$/', '', $candidate );
            if ( is_string( $unscaled ) && $unscaled !== $candidate ) {
                $candidates[] = $unscaled;
                break;
            }
        }

        foreach ( $candidates as $candidate ) {
            $key = ( '' !== $dir ? $dir . '/' : '' ) . $candidate;
            if ( isset( $idx['stems'][ $key ] ) || isset( $idx['loose'][ $candidate ] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Construeix les dades de display per a un attachment orfe.
     */
    private static function build_orphan_data( $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );
        $file_size = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;

        return [
            'id'        => $attachment_id,
            'title'     => get_the_title( $attachment_id ),
            'filename'  => basename( $file_path ?? '' ),
            'url'       => wp_get_attachment_url( $attachment_id ),
            'thumb'     => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
            'filesize'  => $file_size,
            'filesize_h'=> size_format( $file_size ),
            'mime'      => get_post_mime_type( $attachment_id ),
            'date'      => get_the_date( 'd/m/Y', $attachment_id ),
        ];
    }

    /**
     * Patrons LIKE (ja escapats) que cobreixen el fitxer, les seves
     * miniatures i variants d'extensió — sense un patró per miniatura.
     *
     * @return string[]
     */
    private static function get_search_patterns( $attachment_id ) {
        global $wpdb;
        $rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( ! is_string( $rel ) || '' === $rel ) {
            return array();
        }
        $stem = preg_replace( '/\.[^.\/]+$/', '', $rel );
        if ( '' === $stem ) {
            return array();
        }
        $like = $wpdb->esc_like( $stem );
        return array(
            '%' . $like . '.%',
            '%' . $like . '-%x%',
        );
    }

    /**
     * Retorna totes les URLs (originals i thumbnails) d'un attachment.
     */
    private static function get_all_urls( $attachment_id ) {
        $urls = [];

        $main_url = wp_get_attachment_url( $attachment_id );
        if ( $main_url ) $urls[] = $main_url;

        // URL relativa (sense domini)
        $upload_dir = wp_upload_dir();
        $rel = str_replace( $upload_dir['baseurl'], '', $main_url );
        if ( $rel !== $main_url ) $urls[] = $rel;

        // Thumbnails
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $meta['sizes'] ) ) {
            $base = trailingslashit( dirname( $main_url ) );
            foreach ( $meta['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $urls[] = $base . $size['file'];
                }
            }
        }

        return array_unique( $urls );
    }

    /**
     * Compta el total d'attachments d'imatge.
     */
    public static function count_total() {
        $count = wp_count_attachments( 'image' );
        $total = 0;
        foreach ( (array) $count as $mime => $n ) {
            $total += $n;
        }
        return $total;
    }
}
