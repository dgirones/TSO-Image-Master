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
            if ( self::is_confirmed_orphan( $id, $index ) ) {
                $orphans[] = self::build_orphan_data( $id );
            }
        }

        return [
            'orphans'       => $orphans,
            'total_scanned' => $total_scanned,
        ];
    }

    /**
     * Quins d'aquests attachments són orfes (mateix criteri que la pestanya Orfes),
     * amb UN sol índex de referències per a tots.
     *
     * @param int[] $ids   Attachment IDs.
     * @param bool  $reuse Reutilitza l'índex en memòria cau (15 min) en comptes de reconstruir-lo.
     * @return array<int, true> Conjunt d'IDs orfes.
     */
    public static function orphan_id_set( array $ids, $reuse = false ) {
        $set = array();
        if ( empty( $ids ) ) {
            return $set;
        }
        update_meta_cache( 'post', $ids );
        $index = self::get_index( (bool) $reuse );
        foreach ( $ids as $id ) {
            if ( self::is_confirmed_orphan( (int) $id, $index ) ) {
                $set[ (int) $id ] = true;
            }
        }
        return $set;
    }

    /**
     * ÚNICA definició d'"orfa", compartida per la pestanya Orfes i per la
     * subpestanya Sense adjuntar, perquè mai no es contradiguin: una imatge
     * és orfa només si ni l'índex ràpid ni la cerca directa (la que dóna el
     * motiu concret) hi troben cap ús. Qualsevol dels dos que la trobi usada
     * la fa NO orfa, i aleshores la subpestanya Sense adjuntar la mostra
     * (si no té pare) amb el lloc on s'usa.
     *
     * La cerca directa només s'executa per als candidats (els que l'índex
     * no ha trobat usats), així l'escaneig continua sent ràpid.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $index         Índex de referències.
     * @return bool
     */
    private static function is_confirmed_orphan( $attachment_id, array $index ) {
        if ( self::is_used_in_index( $attachment_id, $index ) ) {
            return false;
        }
        return '' === self::get_reference_reason( $attachment_id );
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
     * @param int  $attachment_id Attachment ID.
     * @param bool $skip_posts    Ignora els usos dins d'entrades (contingut, postmeta, imatge destacada):
     *                            serveix per saber si una imatge s'usa també FORA d'entrades.
     * @return string Motiu de protecció, o '' si és orfe.
     */
    public static function get_reference_reason( $attachment_id, $skip_posts = false ) {
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

        if ( ! $skip_posts ) {
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
    
        }

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

        // 10. Term meta / user meta holding this attachment ID (WooCommerce
        // category thumbnails, ACF taxonomy images, avatar plugins).
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->termmeta} WHERE meta_value = %s AND meta_key NOT IN ('order','count','product_count_product_cat','product_count_product_tag') LIMIT 1",
            (string) $attachment_id
        ) );
        if ( $found ) {
            return 'termmeta_id';
        }
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_value = %s AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s LIMIT 1",
            (string) $attachment_id,
            '%' . $wpdb->esc_like( 'user_level' ),
            '%' . $wpdb->esc_like( 'quick_press' ) . '%',
            $wpdb->esc_like( 'tsoimma' ) . '%'
        ) );
        if ( $found ) {
            return 'usermeta_id';
        }

        // phpcs:enable

        // 11. Referenced by ID inside post content ([gallery ids=""], image/gallery blocks, wp-image-N):
        // no URL or file name appears there, so none of the searches above can see it.
        if ( ! $skip_posts && self::find_content_id_post( $attachment_id ) > 0 ) {
            return 'post_content_id';
        }

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

        // 3c. Term meta and user meta that hold an attachment ID (WooCommerce
        // category thumbnails, ACF taxonomy images, avatar plugins...). Numeric
        // values of unrelated keys only make an image look "used" (safe side).
        $vals = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
             WHERE meta_value REGEXP '^[0-9]{1,10}$'
             AND meta_key NOT IN ('order','count','product_count_product_cat','product_count_product_tag')"
        );
        foreach ( (array) $vals as $v ) {
            $idx['ids'][ (int) $v ] = 1;
        }
        $vals = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->usermeta}
                 WHERE meta_value REGEXP '^[0-9]{1,10}$'
                 AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s",
                '%' . $wpdb->esc_like( 'user_level' ),
                '%' . $wpdb->esc_like( 'quick_press' ) . '%',
                $wpdb->esc_like( 'tsoimma' ) . '%',
                $wpdb->esc_like( 'session_tokens' )
            )
        );
        foreach ( (array) $vals as $v ) {
            $idx['ids'][ (int) $v ] = 1;
        }

        // 4. Term meta amb noms d'imatge
        $last = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->termmeta}
                 WHERE meta_id > %d
                 AND meta_value REGEXP '[.](jpe?g|png|gif|webp|avif|svg)'
                 ORDER BY meta_id ASC LIMIT 500",
                $last
            ) );
            foreach ( (array) $rows as $row ) {
                $last = (int) $row->meta_id;
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
     * Imatges sense pare (post_parent = 0 o pare esborrat) que SÍ s'usen en
     * algun lloc, cadascuna amb el lloc concret on s'usa i, si s'usa dins d'una
     * entrada, l'entrada a la qual es pot adjuntar ('attach_to'). No es retornen
     * les orfes (ja surten a la pestanya Orfes).
     *
     * Només diagnòstic (no esborra res): serveix per entendre per què un
     * llistat de "Media sin adjuntar" no coincideix amb el d'orfes.
     *
     * @param int $limit  Màxim d'imatges sense adjuntar a revisar en aquest lot.
     * @param int $offset Offset del lot.
     * @return array{items: array, total: int, scanned: int}
     */
    public static function find_unattached( $limit = 100, $offset = 0 ) {
        global $wpdb;

        $limit  = max( 1, min( 200, (int) $limit ) );
        $offset = max( 0, (int) $offset );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $mime = $wpdb->esc_like( 'image/' ) . '%';

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s
             AND ( p.post_parent = 0 OR par.ID IS NULL )",
            $mime
        ) );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s
             AND ( p.post_parent = 0 OR par.ID IS NULL )
             ORDER BY p.ID DESC LIMIT %d OFFSET %d",
            $mime,
            $limit,
            $offset
        ) );
        // phpcs:enable

        $items = array();
        if ( empty( $ids ) ) {
            return array( 'items' => $items, 'total' => $total, 'scanned' => 0 );
        }

        $ids = array_map( 'intval', $ids );
        update_meta_cache( 'post', $ids );
        $index = self::get_index( $offset > 0 );

        foreach ( $ids as $id ) {
            $class = self::classify_unattached( $id, $index );
            if ( 'orphan' === $class['state'] ) {
                continue; // Orfa: ja surt a la subpestanya d'orfes.
            }

            $reason               = $class['reason'];
            $item                 = self::build_orphan_data( $id );
            $item['state']        = $class['state'];
            $item['reason']       = $reason;
            $item['ref_post']     = $class['weak_ref'] ? $class['weak_ref'] : self::find_reference_post( $id, $reason );
            if ( ! $item['ref_post'] && $class['attach_to'] ) {
                // The reason was found elsewhere (index, option...) but a post really uses it: show that post.
                $item['ref_post'] = $class['attach_to'];
            }
            $item['attach_to']    = $class['attach_to'];
            $item['ref_option']   = self::find_reference_option( $id, $reason );
            $item['ref_theme_file'] = ( 'theme_file' === $reason && class_exists( 'TSOIMMA_Image_Manager' ) )
                ? TSOIMMA_Image_Manager::find_filename_in_theme_files( $item['filename'] )
                : '';
            $item['parent_state'] = ( 0 === (int) wp_get_post_parent_id( $id ) ) ? 'none' : 'deleted';
            $edit_link            = get_edit_post_link( $id, 'raw' );
            $item['edit_url']     = $edit_link ? esc_url_raw( $edit_link ) : '';
            $items[]              = $item;
        }

        return array( 'items' => $items, 'total' => $total, 'scanned' => count( $ids ) );
    }

    /**
     * Entrada a la qual té sentit adjuntar la imatge: la primera entrada o
     * pàgina que l'usa DE VERITAT (primer com a imatge destacada, després dins
     * del contingut per URL, després en un camp amb la seva URL i, finalment,
     * dins del contingut per ID: galeria [gallery ids=""], blocs, wp-image-N).
     *
     * Es prefereix un tipus de contingut PÚBLIC (entrada, pàgina, producte...). Si
     * només l'usa un tipus no públic però editable al tauler (una diapositiva
     * d'un slider, per exemple), s'adjunta a aquest: és un pare vàlid.
     *
     * @param int $attachment_id Attachment ID.
     * @return array|null array{id:int,title:string,type:string,type_label:string,kind:string,meta_field:string,edit_url:string,view_url:string}
     */
    private static function find_attach_target( $attachment_id ) {
        $fallback = null;

        foreach ( array( 'postmeta_id', 'post_content', 'postmeta_url', 'post_content_id' ) as $kind ) {
            $exclude = array();
            // A few candidates per kind: the first one found may be a type that cannot be a parent.
            for ( $i = 0; $i < 10; $i++ ) {
                $ref = self::find_reference_post( $attachment_id, $kind, $exclude );
                if ( ! $ref ) {
                    break;
                }
                $exclude[] = (int) $ref['id'];

                // A number that merely equals the image ID in a random custom field
                // (a counter, a rating...) is not a use. Only the featured image counts.
                if ( 'postmeta_id' === $kind && '_thumbnail_id' !== $ref['meta_field'] ) {
                    continue;
                }

                $type = get_post_type_object( $ref['type'] );
                if ( ! $type ) {
                    continue; // Type not registered (plugin off): it cannot be edited.
                }

                $target = array(
                    'id'         => (int) $ref['id'],
                    'title'      => (string) $ref['title'],
                    'type'       => (string) $ref['type'],
                    'type_label' => (string) $ref['type_label'],
                    'kind'       => $kind,
                    'meta_field' => (string) $ref['meta_field'],
                    'edit_url'   => (string) $ref['edit_url'],
                    'view_url'   => (string) $ref['view_url'],
                );
                if ( ! empty( $type->public ) ) {
                    return $target;
                }
                if ( null === $fallback && ! empty( $type->show_ui ) ) {
                    $fallback = $target;
                }
            }
        }

        return $fallback;
    }

    /**
     * ID del primer post (no exclòs) que referencia l'adjunt PER ID dins del
     * contingut: galeria [gallery ids="1,2"] / include="", blocs d'imatge i
     * galeria, wp-image-N i data-id. La cerca per URL no ho veu mai: aquestes
     * imatges no hi apareixen amb el seu nom de fitxer.
     *
     * @param int   $attachment_id Attachment ID.
     * @param int[] $exclude       Post IDs to skip.
     * @return int Post ID or 0.
     */
    private static function find_content_id_post( $attachment_id, array $exclude = array() ) {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 ) {
            return 0;
        }
        $not_in = empty( $exclude ) ? '' : ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $exclude ) ) . ')';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidates = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_content LIKE %s
             AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE %s )
             AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('attachment','revision','nav_menu_item'){$not_in}
             ORDER BY ( p.post_type = 'page' ) DESC, p.ID ASC
             LIMIT 60",
            '%' . $wpdb->esc_like( (string) $attachment_id ) . '%',
            '%' . $wpdb->esc_like( 'ids=' ) . '%',
            '%' . $wpdb->esc_like( 'include=' ) . '%',
            '%' . $wpdb->esc_like( '"ids"' ) . '%',
            '%' . $wpdb->esc_like( 'wp-image-' ) . '%',
            '%' . $wpdb->esc_like( 'data-id=' ) . '%',
            '%' . $wpdb->esc_like( '<!-- wp:' ) . '%'
        ) );
        // phpcs:enable

        foreach ( (array) $candidates as $candidate ) {
            if ( TSOIMMA_Image_Manager::post_content_contains_attachment_id( (int) $candidate, $attachment_id ) ) {
                return (int) $candidate;
            }
        }
        return 0;
    }

    /**
     * Coincidència feble: un camp personalitzat d'una entrada (que no és la imatge
     * destacada) amb un valor numèric igual a l'ID de la imatge. Sovint és una
     * casualitat (un comptador, una puntuació...), no un ús real.
     *
     * @param int $attachment_id Attachment ID.
     * @return array|null Dades de l'entrada (com find_reference_post) o null.
     */
    private static function find_weak_reference( $attachment_id ) {
        $ref = self::find_reference_post( $attachment_id, 'postmeta_id' );
        if ( $ref && '_thumbnail_id' !== $ref['meta_field'] ) {
            return $ref;
        }
        return null;
    }

    /**
     * ÚNICA classificació d'una imatge sense pare, compartida pel llistat i per
     * la protecció d'eliminació:
     *  - orphan   : no s'usa enlloc (surt a la subpestanya d'orfes).
     *  - fix      : s'usa de veritat dins d'una entrada: cal adjuntar-la (no eliminar).
     *  - inuse    : s'usa fora d'entrades (opcions, widgets, logo, avatars, tema...): res a fer.
     *  - doubtful : només coincideix el seu número en un camp d'una entrada: potser no
     *               és un ús real; es pot eliminar (amb còpia de seguretat).
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $index         Índex de referències.
     * @return array{state:string,reason:string,attach_to:?array,weak_ref:?array}
     */
    private static function classify_unattached( $attachment_id, array $index ) {
        $out = array( 'state' => 'orphan', 'reason' => '', 'attach_to' => null, 'weak_ref' => null );

        $reason = self::get_reference_reason( $attachment_id );
        // L'escaneig d'orfes usa l'índex ràpid; si la cerca directa no troba res
        // però l'índex sí, és una coincidència d'índex (així aquest llistat i la
        // pestanya Orfes mai no es contradiuen).
        if ( '' === $reason && self::is_used_in_index( $attachment_id, $index ) ) {
            $reason = 'index_match';
        }
        if ( '' === $reason ) {
            return $out;
        }
        $out['reason'] = $reason;

        $target = self::find_attach_target( $attachment_id );
        if ( $target ) {
            $out['state']     = 'fix';
            $out['attach_to'] = $target;
            return $out;
        }

        $outside = self::get_reference_reason( $attachment_id, true );
        if ( '' !== $outside ) {
            $out['state']  = 'inuse';
            $out['reason'] = $outside;
            return $out;
        }

        $weak = self::find_weak_reference( $attachment_id );
        if ( $weak ) {
            $out['state']    = 'doubtful';
            $out['reason']   = 'postmeta_id';
            $out['weak_ref'] = $weak;
            return $out;
        }

        $out['state'] = 'inuse';
        return $out;
    }

    /**
     * Si es pot eliminar des de la llista "Referenciades": només les orfes i les
     * dubtoses. Mai una imatge usada de veritat per una entrada (se n'ha
     * d'adjuntar) ni una usada fora d'entrades (opcions, logo, avatars...).
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function is_deletable_from_list( $attachment_id ) {
        $class = self::classify_unattached( (int) $attachment_id, self::get_index( true ) );
        return in_array( $class['state'], array( 'orphan', 'doubtful' ), true );
    }

    /**
     * Adjunta cada imatge sense pare a l'entrada on s'usa (el mateix que el
     * botó "Adjuntar" de la Biblioteca de mitjans): en tenir pare ja no surt a
     * la llista de "sense adjuntar". Només es toca el camp post_parent.
     *
     * @param int[] $attachment_ids Attachment IDs.
     * @return array{attached: array, errors: array}
     */
    public static function attach_to_posts( array $attachment_ids ) {
        $attached = array();
        $errors   = array();

        foreach ( array_unique( array_map( 'absint', $attachment_ids ) ) as $id ) {
            $post = $id ? get_post( $id ) : null;

            if ( ! $post || 'attachment' !== $post->post_type || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
                $errors[] = array( 'id' => $id, 'error' => __( 'This is not an image from the media library.', 'tso-image-master' ) );
                continue;
            }

            $parent = (int) $post->post_parent;
            if ( $parent > 0 && get_post( $parent ) ) {
                $errors[] = array( 'id' => $id, 'error' => __( 'This image is already attached to a post.', 'tso-image-master' ) );
                continue;
            }

            $target = self::find_attach_target( $id );
            if ( ! $target ) {
                $errors[] = array( 'id' => $id, 'error' => __( 'This image is not used inside any post, so there is nothing to attach it to.', 'tso-image-master' ) );
                continue;
            }

            if ( ! current_user_can( 'edit_post', $target['id'] ) ) {
                $errors[] = array( 'id' => $id, 'error' => __( 'You are not allowed to edit that post.', 'tso-image-master' ) );
                continue;
            }

            $result = wp_update_post( array( 'ID' => $id, 'post_parent' => $target['id'] ), true );
            if ( is_wp_error( $result ) ) {
                $errors[] = array( 'id' => $id, 'error' => $result->get_error_message() );
                continue;
            }

            clean_post_cache( $id );
            $attached[] = array(
                'id'         => $id,
                'post_id'    => $target['id'],
                'post_title' => $target['title'],
            );
        }

        return array( 'attached' => $attached, 'errors' => $errors );
    }

    /**
     * Primer post/pàgina que referencia l'adjunt (només per als motius que
     * viuen a posts o postmeta), per poder-hi enllaçar al diagnòstic.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $reason        Motiu retornat per get_reference_reason().
     * @return array|null array{id:int,title:string,type:string,meta_field:string,edit_url:string,view_url:string}
     */
    private static function find_reference_post( $attachment_id, $reason, array $exclude = array() ) {
        global $wpdb;

        if ( ! in_array( $reason, array( 'post_content', 'postmeta_url', 'postmeta_id', 'site_editor', 'post_content_id' ), true ) ) {
            return null;
        }
        // Integers only (cast), so it is safe to build into the queries below.
        $not_in = empty( $exclude ) ? '' : ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $exclude ) ) . ')';

        $patterns = self::get_search_patterns( $attachment_id );
        if ( count( $patterns ) < 2 ) {
            return null;
        }
        list( $p1, $p2 ) = $patterns;

        $post_id  = 0;
        $meta_key = '';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( 'post_content_id' === $reason ) {
            $post_id = self::find_content_id_post( $attachment_id, $exclude );
        } elseif ( 'post_content' === $reason ) {
            $post_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 WHERE ( p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_excerpt LIKE %s OR p.post_excerpt LIKE %s )
                 AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('attachment','revision','nav_menu_item'){$not_in}
                 ORDER BY ( p.post_type = 'page' ) DESC, p.ID ASC
                 LIMIT 1",
                $p1, $p2, $p1, $p2
            ) );
        } elseif ( 'site_editor' === $reason ) {
            $post_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type IN ('wp_template','wp_template_part','wp_block') AND post_status != 'trash'
                 AND ( post_content LIKE %s OR post_content LIKE %s )
                 LIMIT 1",
                $p1, $p2
            ) );
        } elseif ( 'postmeta_url' === $reason ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT p.ID AS post_id, pm.meta_key FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s ) AND pm.post_id <> %d
                 AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('attachment','revision','nav_menu_item'){$not_in}
                 LIMIT 1",
                $p1, $p2, $attachment_id
            ) );
            if ( $row ) {
                $post_id  = (int) $row->post_id;
                $meta_key = (string) $row->meta_key;
            }
        } else {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT p.ID AS post_id, pm.meta_key FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_value = %s AND pm.post_id <> %d
                 AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('revision','nav_menu_item'){$not_in}
                 ORDER BY ( pm.meta_key = '_thumbnail_id' ) DESC
                 LIMIT 1",
                (string) $attachment_id, $attachment_id
            ) );
            if ( $row ) {
                $post_id  = (int) $row->post_id;
                $meta_key = (string) $row->meta_key;
            }
        }
        // phpcs:enable

        if ( $post_id <= 0 ) {
            return null;
        }

        $edit_link = get_edit_post_link( $post_id, 'raw' );
        $view_url  = '';
        if ( 'publish' === get_post_status( $post_id ) && is_post_type_viewable( get_post_type( $post_id ) ) ) {
            $permalink = get_permalink( $post_id );
            $view_url  = $permalink ? esc_url_raw( $permalink ) : '';
        }

        $post_type_obj = get_post_type_object( (string) get_post_type( $post_id ) );

        return array(
            'id'       => $post_id,
            'title'    => get_the_title( $post_id ),
            'type'     => get_post_type( $post_id ),
            'type_label' => $post_type_obj && ! empty( $post_type_obj->labels->singular_name ) ? (string) $post_type_obj->labels->singular_name : (string) get_post_type( $post_id ),
            'kind'     => $reason,
            'meta_field' => $meta_key,
            'edit_url' => $edit_link ? esc_url_raw( $edit_link ) : '',
            'view_url' => $view_url,
        );
    }

    /**
     * Lloc concret d'una referència que viu a una opció: widget (amb títol i
     * zona), mod del Personalitzador, avatar per defecte o opció genèrica.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $reason        Motiu retornat per get_reference_reason().
     * @return array|null array{name:string,kind:string,title:string,zone:string,inactive:bool,mod:string,url:string}
     */
    private static function find_reference_option( $attachment_id, $reason ) {
        global $wpdb;

        $name = '';
        if ( 0 === strpos( $reason, 'option_id:' ) ) {
            $name = substr( $reason, strlen( 'option_id:' ) );
        } elseif ( 0 === strpos( $reason, 'option_filename:' ) ) {
            $name = substr( $reason, strlen( 'option_filename:' ) );
        } elseif ( 'option_url' === $reason ) {
            $patterns = self::get_search_patterns( $attachment_id );
            if ( count( $patterns ) < 2 ) {
                return null;
            }
            list( $p1, $p2 ) = $patterns;
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $names = $wpdb->get_col( $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE ( option_value LIKE %s OR option_value LIKE %s ) LIMIT 200",
                $p1, $p2
            ) );
            // phpcs:enable
            foreach ( (array) $names as $candidate ) {
                if ( ! TSOIMMA_Image_Manager::is_excluded_option( $candidate ) ) {
                    $name = (string) $candidate;
                    break;
                }
            }
        }

        if ( '' === $name ) {
            return null;
        }

        $rel  = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $stem = is_string( $rel ) ? preg_replace( '/\.[^.\/]+$/', '', $rel ) : '';
        $by_id = ( 0 === strpos( $reason, 'option_id:' ) );

        $out = array(
            'name'     => $name,
            'kind'     => 'option',
            'title'    => '',
            'zone'     => '',
            'inactive' => false,
            'mod'      => '',
            'url'      => '',
        );

        if ( in_array( $name, array( 'wpupa_attachment_id', 'avatar_default_wp_user_avatar', 'avatar_default' ), true ) ) {
            $out['kind'] = 'avatar';
            $out['url']  = admin_url( 'options-discussion.php' );
            return $out;
        }

        if ( 0 === strpos( $name, 'widget_' ) ) {
            $out['kind'] = 'widget';
            $out['url']  = admin_url( 'widgets.php' );
            $value       = get_option( $name );
            if ( is_array( $value ) ) {
                $sidebars = get_option( 'sidebars_widgets', array() );
                foreach ( $value as $number => $instance ) {
                    if ( ! is_int( $number ) || ! is_array( $instance ) || ! self::value_mentions( $instance, $stem, $attachment_id, $by_id ) ) {
                        continue;
                    }
                    $out['title'] = isset( $instance['title'] ) && is_string( $instance['title'] ) ? $instance['title'] : '';
                    $widget_id    = substr( $name, strlen( 'widget_' ) ) . '-' . $number;
                    foreach ( (array) $sidebars as $sidebar_id => $widgets ) {
                        if ( is_array( $widgets ) && in_array( $widget_id, $widgets, true ) ) {
                            $registered = isset( $GLOBALS['wp_registered_sidebars'][ $sidebar_id ]['name'] )
                                ? (string) $GLOBALS['wp_registered_sidebars'][ $sidebar_id ]['name']
                                : (string) $sidebar_id;
                            $out['zone']     = $registered;
                            $out['inactive'] = ( 'wp_inactive_widgets' === $sidebar_id );
                            break;
                        }
                    }
                    break;
                }
            }
            return $out;
        }

        if ( 0 === strpos( $name, 'theme_mods_' ) ) {
            $out['kind'] = 'theme_mod';
            $out['url']  = admin_url( 'customize.php' );
            $value       = get_option( $name );
            if ( is_array( $value ) ) {
                foreach ( $value as $mod => $mod_value ) {
                    if ( self::value_mentions( $mod_value, $stem, $attachment_id, $by_id ) ) {
                        $out['mod'] = (string) $mod;
                        break;
                    }
                }
            }
            return $out;
        }

        return $out;
    }

    /**
     * Si un valor d'opció (recursiu) menciona la imatge pel nom de fitxer
     * (o, si $by_id, per l'ID exacte).
     *
     * @param mixed  $value Valor.
     * @param string $stem  Ruta relativa sense extensió (2014/09/foto).
     * @param int    $id    Attachment ID.
     * @param bool   $by_id Cal comparar també per ID.
     * @return bool
     */
    private static function value_mentions( $value, $stem, $id, $by_id ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            foreach ( (array) $value as $inner ) {
                if ( self::value_mentions( $inner, $stem, $id, $by_id ) ) {
                    return true;
                }
            }
            return false;
        }
        if ( $by_id && ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d{1,10}$/', $value ) ) ) && (int) $value === (int) $id ) {
            return true;
        }
        if ( ! is_string( $value ) || '' === $stem ) {
            return false;
        }
        $value = str_replace( '\\', '', $value );
        return false !== stripos( $value, $stem . '.' )
            || (bool) preg_match( '/' . preg_quote( $stem, '/' ) . '-\d+x\d+/i', $value );
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
