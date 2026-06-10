<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_URL_Fixer {

    private static $img_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

    private static function uploads_base() {
        static $base = null;
        if ( $base === null ) {
            $upload_dir = wp_upload_dir();
            $base = array(
                'dir' => trailingslashit( $upload_dir['basedir'] ),
                'url' => trailingslashit( $upload_dir['baseurl'] ),
            );
        }
        return $base;
    }

    /**
     * Whether a URL points to the site uploads directory (encoded or decoded).
     *
     * @param string $url URL to check.
     * @return bool
     */
    private static function is_uploads_media_url( $url ) {
        $url = (string) $url;
        if ( '' === $url ) {
            return false;
        }

        $base     = self::uploads_base();
        $prefixes = array(
            untrailingslashit( $base['url'] ),
            untrailingslashit( rawurldecode( $base['url'] ) ),
        );

        foreach ( array_unique( array( $url, rawurldecode( $url ) ) ) as $candidate ) {
            foreach ( $prefixes as $prefix ) {
                if ( $candidate === $prefix || 0 === strpos( $candidate, $prefix . '/' ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve an uploads URL to a filesystem path that must stay inside uploads.
     *
     * @param string $url Media URL under uploads.
     * @param bool   $must_exist Require the file to exist on disk.
     * @return string|null Absolute path, or null when invalid/outside uploads.
     */
    private static function uploads_path_from_url( $url, $must_exist = true ) {
        if ( ! self::is_uploads_media_url( $url ) ) {
            return null;
        }

        $base = self::uploads_base();
        $rel  = rawurldecode( ltrim( str_replace( $base['url'], '', $url ), '/' ) );
        if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
            return null;
        }

        $candidate = wp_normalize_path( $base['dir'] . $rel );
        $real_base = realpath( $base['dir'] );
        if ( false === $real_base ) {
            return null;
        }

        $norm_base = wp_normalize_path( $real_base ) . '/';

        if ( $must_exist ) {
            $real_path = realpath( $candidate );
            if ( false === $real_path || ! is_file( $real_path ) ) {
                return null;
            }
            $candidate = $real_path;
        } else {
            $real_path = realpath( $candidate );
            if ( false !== $real_path ) {
                $candidate = $real_path;
            }
        }

        $norm_candidate = wp_normalize_path( $candidate );
        if ( 0 !== strpos( $norm_candidate, $norm_base ) ) {
            return null;
        }

        return $candidate;
    }

    private static function encode_rel_path_for_url( $rel_path ) {
        $rel_path = str_replace( '\\', '/', ltrim( (string) $rel_path, '/' ) );
        if ( '' === $rel_path ) return '';
        $parts = array_map( 'rawurlencode', explode( '/', $rel_path ) );
        return implode( '/', $parts );
    }

    private static function add_remap_pair( &$remap, $old_url, $new_url ) {
        if ( ! $old_url || ! $new_url || self::urls_equivalent( $old_url, $new_url ) ) {
            return;
        }
        $remap[ $old_url ] = $new_url;

        $old_dec = rawurldecode( $old_url );
        $new_dec = rawurldecode( $new_url );
        if ( ( $old_dec !== $old_url || $new_dec !== $new_url ) && ! self::urls_equivalent( $old_dec, $new_dec ) ) {
            $remap[ $old_dec ] = $new_dec;
        }
    }

    private static function urls_equivalent( $old_url, $new_url ) {
        if ( ! $old_url || ! $new_url ) {
            return false;
        }
        if ( $old_url === $new_url ) {
            return true;
        }
        return rawurldecode( $old_url ) === rawurldecode( $new_url );
    }

    private static function get_scannable_post_types() {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        $excluded   = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' );
        $post_types = array_values( array_diff( array_map( 'sanitize_key', $post_types ), $excluded ) );

        return empty( $post_types ) ? array( 'post', 'page' ) : $post_types;
    }

    private static function get_scannable_posts() {
        return get_posts( array(
            'post_type'        => self::get_scannable_post_types(),
            'post_status'      => array( 'publish', 'draft', 'private' ),
            'posts_per_page'   => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => false,
        ) );
    }

    private static function maybe_render_scan_source( $content ) {
        $content = (string) $content;
        if ( '' === trim( $content ) ) {
            return '';
        }

        $rendered = $content;

        if ( function_exists( 'do_blocks' ) && false !== strpos( $rendered, '<!-- wp:' ) ) {
            $blocked = do_blocks( $rendered );
            if ( is_string( $blocked ) && '' !== trim( $blocked ) ) {
                $rendered = $blocked;
            }
        }

        if ( function_exists( 'do_shortcode' ) && false !== strpos( $rendered, '[' ) ) {
            $shortcoded = do_shortcode( $rendered );
            if ( is_string( $shortcoded ) && '' !== trim( $shortcoded ) ) {
                $rendered = $shortcoded;
            }
        }

        return $rendered;
    }

    private static function collect_post_scan_sources( $post ) {
        $sources = array();

        foreach ( array( (string) $post->post_content, (string) $post->post_excerpt ) as $raw_source ) {
            if ( '' === trim( $raw_source ) ) {
                continue;
            }

            $sources[] = $raw_source;

            $rendered = self::maybe_render_scan_source( $raw_source );
            if ( $rendered !== $raw_source ) {
                $sources[] = $rendered;
            }
        }

        return array_values( array_unique( array_filter( $sources, 'strlen' ) ) );
    }

    public static function scan() {
        $base = self::uploads_base();
        $known_remap = self::build_known_remap( $base );
        $posts = self::get_scannable_posts();

        $total_scanned = count( $posts );
        $all_issues    = array();
        $seen_urls     = array();
        $ext_pattern   = implode( '|', self::$img_exts );

        foreach ( $posts as $post ) {
            $pattern = '/(https?:\/\/[^\s"\'<>\)]+\.(' . $ext_pattern . '))/i';
            $urls    = array();

            foreach ( self::collect_post_scan_sources( $post ) as $source ) {
                if ( ! preg_match_all( $pattern, $source, $matches ) ) {
                    continue;
                }

                foreach ( array_unique( $matches[1] ) as $url ) {
                    $urls[ $url ] = true;
                }
            }

            if ( empty( $urls ) ) {
                continue;
            }

            foreach ( array_keys( $urls ) as $url ) {
                if ( strpos( $url, $base['url'] ) === false ) continue;

                $new_url = null;
                $reason  = '';
                $type    = '';

                // TIPUS A: thumbnail obsolet (WP ja te nou format)
                if ( isset( $known_remap[ $url ] ) ) {
                    $new_url = $known_remap[ $url ];
                    $reason  = 'Thumbnail obsolet: el servidor ja te el format nou';
                    $type    = 'outdated';
                }

                // TIPUS B: fitxer absent
                if ( ! $type ) {
                    // Important: content URLs can be percent-encoded (%C3%B1) while filesystem paths are not.
                    $rel_path  = rawurldecode( str_replace( $base['url'], '', $url ) );
                    $full_path = wp_normalize_path( $base['dir'] . ltrim( $rel_path, '/' ) );
                    if ( ! file_exists( $full_path ) ) {
                        $pi = pathinfo( $full_path );

                        // B1: Buscar el mateix nom en altres formats (jpg→webp, etc.)
                        foreach ( self::$img_exts as $try_ext ) {
                            if ( strtolower( $try_ext ) === strtolower( $pi['extension'] ) ) continue;
                            $candidate = $pi['dirname'] . '/' . $pi['filename'] . '.' . $try_ext;
                            if ( file_exists( $candidate ) ) {
                                $candidate_rel = str_replace( wp_normalize_path( $base['dir'] ), '', wp_normalize_path( $candidate ) );
                                $new_url = $base['url'] . self::encode_rel_path_for_url( $candidate_rel );
                                if ( self::urls_equivalent( $url, $new_url ) ) {
                                    $new_url = null;
                                    continue;
                                }
                                $reason  = 'Fitxer absent: trobada alternativa en format .' . $try_ext;
                                $type    = 'missing';
                                break;
                            }
                        }

                        // B2: Si el nom té sufix de dimensions WordPress (-WxH), buscar la imatge base.
                        // Cas típic: l'article tenia 'foto-1024x885.jpg' però la imatge es va
                        // redimensionar i convertir → 'foto-1024x885.jpg/webp' ja no existeix,
                        // però sí existeix 'foto.webp' (la imatge principal optimitzada).
                        if ( ! $type && preg_match( '/^(.+)-(\d+)x(\d+)$/', $pi['filename'], $m ) ) {
                            $base_name = $m[1]; // nom sense dimensions, ex: 'halloween-y-paro'
                            foreach ( self::$img_exts as $try_ext ) {
                                $candidate = $pi['dirname'] . '/' . $base_name . '.' . $try_ext;
                                if ( file_exists( $candidate ) ) {
                                    $candidate_rel = str_replace( wp_normalize_path( $base['dir'] ), '', wp_normalize_path( $candidate ) );
                                    $new_url = $base['url'] . self::encode_rel_path_for_url( $candidate_rel );
                                    if ( self::urls_equivalent( $url, $new_url ) ) {
                                        $new_url = null;
                                        continue;
                                    }
                                    $reason  = 'Thumbnail redimensionat: la mida original ja no existeix, suggerida la imatge base .' . $try_ext;
                                    $type    = 'missing';
                                    break;
                                }
                            }
                        }

                        if ( ! $type ) {
                            $reason = 'Fitxer absent: no s\'ha trobat cap alternativa';
                            $type   = 'missing_no_fix';
                        }
                    }
                }

                if ( ! $type ) continue;

                $old_ext    = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );
                $new_ext    = $new_url ? strtolower( pathinfo( $new_url, PATHINFO_EXTENSION ) ) : null;
                $post_entry = array( 'id' => $post->ID, 'title' => $post->post_title, 'type' => $post->post_type );

                if ( isset( $seen_urls[ $url ] ) ) {
                    foreach ( $all_issues as &$issue ) {
                        if ( $issue['old_url'] === $url ) {
                            $already = false;
                            foreach ( $issue['posts'] as $p ) {
                                if ( $p['id'] === $post->ID ) { $already = true; break; }
                            }
                            if ( ! $already ) {
                                $issue['posts'][]   = $post_entry;
                                $issue['occurrences']++;
                            }
                            break;
                        }
                    }
                    unset( $issue );
                } else {
                    $seen_urls[ $url ] = true;
                    $all_issues[] = array(
                        'old_url'      => $url,
                        'new_url'      => $new_url,
                        'old_ext'      => $old_ext,
                        'new_ext'      => $new_ext,
                        'filename'     => pathinfo( $url, PATHINFO_FILENAME ),
                        'new_filename' => $new_url ? pathinfo( $new_url, PATHINFO_FILENAME ) : '',
                        'has_fix'      => ( $new_url !== null ),
                        'type'        => $type,
                        'reason'      => $reason,
                        'occurrences' => 1,
                        'posts'       => array( $post_entry ),
                    );
                }
            }
        }

        $type_order = array( 'outdated' => 0, 'missing' => 1, 'missing_no_fix' => 2 );
        usort( $all_issues, function( $a, $b ) use ( $type_order ) {
            $oa = isset( $type_order[ $a['type'] ] ) ? $type_order[ $a['type'] ] : 9;
            $ob = isset( $type_order[ $b['type'] ] ) ? $type_order[ $b['type'] ] : 9;
            return ( $oa !== $ob ) ? ( $oa - $ob ) : ( $b['occurrences'] - $a['occurrences'] );
        } );

        $fixable = count( array_filter( $all_issues, function( $i ) { return $i['has_fix']; } ) );

        return array(
            'issues'              => $all_issues,
            'total'               => count( $all_issues ),
            'total_posts_scanned' => $total_scanned,
            'fixable'             => $fixable,
        );
    }

    private static function build_known_remap( $base ) {
        global $wpdb;
        $remap = array();

        $rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT pm_file.meta_value AS attached_file, pm_meta.meta_value AS wp_meta
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = '_wp_attached_file'
             INNER JOIN {$wpdb->postmeta} pm_meta  ON pm_meta.post_id  = p.ID AND pm_meta.meta_key  = '_wp_attachment_metadata'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
        );

        foreach ( $rows as $row ) {
            $meta = maybe_unserialize( $row->wp_meta );
            if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) continue;

            $year_month = dirname( $row->attached_file );
            $rel_dir    = '.' === $year_month ? '' : trim( str_replace( '\\', '/', $year_month ), '/' );

            foreach ( $meta['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) continue;
                $current_file = $size_data['file'];
                $current_ext  = strtolower( pathinfo( $current_file, PATHINFO_EXTENSION ) );
                $base_name    = pathinfo( $current_file, PATHINFO_FILENAME );
                $current_rel  = ltrim( ( $rel_dir ? $rel_dir . '/' : '' ) . $current_file, '/' );
                $current_url  = $base['url'] . self::encode_rel_path_for_url( $current_rel );

                foreach ( self::$img_exts as $old_ext ) {
                    if ( $old_ext === $current_ext ) continue;
                    $old_rel = ltrim( ( $rel_dir ? $rel_dir . '/' : '' ) . $base_name . '.' . $old_ext, '/' );
                    $old_url = $base['url'] . self::encode_rel_path_for_url( $old_rel );
                    self::add_remap_pair( $remap, $old_url, $current_url );
                }
            }
        }
        return $remap;
    }

    public static function fix( $fixes ) {
        global $wpdb;
        $fixed   = 0;
        $skipped = 0;
        $errors  = array();

        foreach ( $fixes as $fix ) {
            $old = esc_url_raw( $fix['old_url'] );
            $new = esc_url_raw( $fix['new_url'] );
            if ( ! $old || ! $new || $old === $new ) {
                $skipped++;
                continue;
            }

            if ( ! self::is_uploads_media_url( $old ) || ! self::is_uploads_media_url( $new ) ) {
                $path     = wp_parse_url( $old, PHP_URL_PATH );
                $errors[] = 'URL fora de uploads: ' . basename( '' !== $path ? (string) $path : $old );
                $skipped++;
                continue;
            }

            $new_path = self::uploads_path_from_url( $new, true );
            if ( null === $new_path ) {
                $errors[] = 'Fitxer desti no trobat: ' . basename( $new );
                $skipped++;
                continue;
            }

            $affected = self::replace_in_db( $old, $new );
            if ( $affected > 0 ) { $fixed++; } else { $skipped++; }
        }

        if ( $fixed > 0 ) {
            if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
            do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
            if ( function_exists( 'rocket_clean_domain' ) )  rocket_clean_domain();
            if ( function_exists( 'w3tc_flush_all' ) )       w3tc_flush_all();
            if ( function_exists( 'wpfc_clear_all_cache' ) ) wpfc_clear_all_cache();
        }

        return array( 'fixed' => $fixed, 'skipped' => $skipped, 'errors' => $errors );
    }

    private static function replace_in_db( $old_url, $new_url ) {
        global $wpdb;
        $affected = 0;

        $pairs = array( array( $old_url, $new_url ) );
        $old_dec = rawurldecode( $old_url );
        $new_dec = rawurldecode( $new_url );
        if ( $old_dec !== $old_url || $new_dec !== $new_url ) {
            $pairs[] = array( $old_dec, $new_dec );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        foreach ( $pairs as $pair ) {
            $old = $pair[0];
            $new = $pair[1];
            if ( ! $old || ! $new || $old === $new ) continue;
            $like = '%' . $wpdb->esc_like( $old ) . '%';

            $affected += (int) $wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "UPDATE {$wpdb->posts}
                 SET post_content = REPLACE(post_content, %s, %s),
                     post_excerpt = REPLACE(post_excerpt, %s, %s)
                 WHERE post_content LIKE %s OR post_excerpt LIKE %s",
                $old, $new, $old, $new, $like, $like
            ) );

            $affected += (int) $wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
                $old, $new, $like
            ) );

            $affected += (int) $wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "UPDATE {$wpdb->options}
                 SET option_value = REPLACE(option_value, %s, %s)
                 WHERE option_value LIKE %s
                 AND option_name NOT LIKE %s
                 AND option_name NOT LIKE %s",
                $old, $new, $like,
                $wpdb->esc_like( '_transient' ) . '%',
                $wpdb->esc_like( '_site_transient' ) . '%'
            ) );
        }
        // phpcs:enable

        return $affected;
    }
}
