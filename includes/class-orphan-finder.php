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
        global $wpdb;

        // Obtenir tots els attachments d'imatge
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'offset'         => $offset,
            'fields'         => 'ids',
        ];
        $attachment_ids = get_posts( $args );
        $total_scanned  = count( $attachment_ids );

        if ( empty( $attachment_ids ) ) {
            return [ 'orphans' => [], 'total_scanned' => 0 ];
        }

        $orphans = [];

        // Obtenir totes les URLs base per a cada attachment
        foreach ( $attachment_ids as $id ) {
            if ( self::is_orphan( $id ) ) {
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
        global $wpdb;

        // 1. Té un post_parent assignat?
        $parent = wp_get_post_parent_id( $attachment_id );
        if ( $parent > 0 ) return false;

        // 2. Obtenir totes les URLs de l'attachment (inclosos thumbnails)
        $urls = self::get_all_urls( $attachment_id );
        if ( empty( $urls ) ) return true;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        // 3. Buscar en post_content
        foreach ( $urls as $url ) {
            $url_escaped = $wpdb->esc_like( $url );
            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE (post_content LIKE %s OR post_excerpt LIKE %s)
                 AND post_status != 'trash'
                 AND post_type != 'attachment'",
                '%' . $url_escaped . '%',
                '%' . $url_escaped . '%'
            ) );
            if ( $found > 0 ) return false;
        }

        // 4. Buscar en post_meta (inclou ACF, Elementor, etc.)
        foreach ( $urls as $url ) {
            $url_escaped = $wpdb->esc_like( $url );
            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                '%' . $url_escaped . '%'
            ) );
            if ( $found > 0 ) return false;
        }

        // 5. Buscar per ID directament en post_meta (_thumbnail_id, etc.)
        $found_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value = %s",
            $attachment_id
        ) );
        if ( $found_id > 0 ) return false;

        // 6. Buscar en options (widgets, customizer)
        foreach ( $urls as $url ) {
            $url_escaped = $wpdb->esc_like( $url );
            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_value LIKE %s
                 AND option_name NOT LIKE %s",
                '%' . $url_escaped . '%',
                $wpdb->esc_like( '_transient' ) . '%'
            ) );
            if ( $found > 0 ) return false;
        }

        // 7. Site Editor templates, template parts, and block patterns.
        foreach ( $urls as $url ) {
            $url_escaped = $wpdb->esc_like( $url );
            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type IN ('wp_template','wp_template_part','wp_block')
                 AND post_status != 'trash'
                 AND post_content LIKE %s",
                '%' . $url_escaped . '%'
            ) );
            if ( $found > 0 ) {
                return false;
            }
        }

        // 8. Term meta (ACF on taxonomies, etc.).
        foreach ( $urls as $url ) {
            $url_escaped = $wpdb->esc_like( $url );
            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_value LIKE %s",
                '%' . $url_escaped . '%'
            ) );
            if ( $found > 0 ) {
                return false;
            }
        }

        // 9. Navigation menu items referencing attachment ID or URL.
        $found_menu = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'nav_menu_item'
             AND p.post_status != 'trash'
             AND ( pm.meta_key = '_menu_item_object_id' AND pm.meta_value = %s )",
            (string) $attachment_id
        ) );
        if ( $found_menu > 0 ) {
            return false;
        }

        // phpcs:enable

        return true;
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
