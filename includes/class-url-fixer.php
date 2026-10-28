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

    private static function uploads_url_prefixes() {
        $base     = self::uploads_base();
        $prefixes = array(
            untrailingslashit( $base['url'] ),
            untrailingslashit( rawurldecode( $base['url'] ) ),
        );

        foreach ( array( 'http', 'https' ) as $scheme ) {
            $scheme_url = set_url_scheme( $base['url'], $scheme );
            $prefixes[] = untrailingslashit( $scheme_url );
            $prefixes[] = untrailingslashit( rawurldecode( $scheme_url ) );
        }

        return array_values( array_unique( array_filter( $prefixes ) ) );
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
        $prefixes = self::uploads_url_prefixes();

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
     * Strip uploads base URL prefix (http/https, encoded/decoded) and return relative path.
     *
     * @param string $url Media URL under uploads.
     * @return string|null Relative path inside uploads, or null when invalid.
     */
    private static function uploads_rel_path_from_url( $url ) {
        $url = (string) $url;
        if ( '' === $url || ! self::is_uploads_media_url( $url ) ) {
            return null;
        }

        foreach ( array_unique( array( $url, rawurldecode( $url ) ) ) as $candidate ) {
            foreach ( self::uploads_url_prefixes() as $prefix ) {
                if ( $candidate === $prefix ) {
                    return '';
                }
                if ( 0 === strpos( $candidate, $prefix . '/' ) ) {
                    return rawurldecode( ltrim( substr( $candidate, strlen( $prefix ) + 1 ), '/' ) );
                }
            }
        }

        return null;
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
        $rel  = self::uploads_rel_path_from_url( $url );
        if ( null === $rel || false !== strpos( $rel, '..' ) ) {
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
        $renamed_map   = tsoimma_get_renamed_map();
        $ext_pattern   = implode( '|', self::$img_exts );

        foreach ( $posts as $post ) {
            $pattern = '/(https?:\/\/[^\s"\'<>\)]+\.(' . $ext_pattern . '))/i';
            $urls    = array();

            $url_attachment = array();
            $post_text      = '';
            foreach ( self::collect_post_scan_sources( $post ) as $source ) {
                $post_text .= "\n" . $source;
                if ( ! preg_match_all( $pattern, $source, $matches ) ) {
                    continue;
                }

                foreach ( array_unique( $matches[1] ) as $url ) {
                    $urls[ $url ] = true;
                }

                foreach ( self::img_attachment_ids_from_html( $source ) as $img_url => $img_id ) {
                    $url_attachment[ $img_url ] = $img_id;
                }
            }

            if ( empty( $urls ) ) {
                continue;
            }

            foreach ( array_keys( $urls ) as $url ) {
                if ( ! self::is_uploads_media_url( $url ) ) {
                    continue;
                }

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
                    $rel_path = self::uploads_rel_path_from_url( $url );
                    if ( null === $rel_path ) {
                        continue;
                    }
                    $full_path = wp_normalize_path( $base['dir'] . ltrim( $rel_path, '/' ) );
                    if ( ! file_exists( $full_path ) ) {
                        $pi = pathinfo( $full_path );

                        // B0: the image was renamed with this plugin; point to its current file.
                        $renamed_rel = self::resolve_renamed_rel( $rel_path, $renamed_map, $base );
                        if ( null !== $renamed_rel ) {
                            $new_url = $base['url'] . self::encode_rel_path_for_url( $renamed_rel );
                            $reason  = 'Fitxer renomenat: la imatge té ara un altre nom';
                            $type    = 'missing';
                        }

                        // B0b: <img class="wp-image-ID">: the attachment still exists, use its current file.
                        if ( ! $type && isset( $url_attachment[ $url ] ) ) {
                            $by_id = self::current_url_for_attachment( (int) $url_attachment[ $url ], $url );
                            if ( null !== $by_id && self::attachment_already_in_text( (int) $url_attachment[ $url ], $post_text ) ) {
                                // The same image is already there with a valid URL: repairing would duplicate it.
                                $reason = 'Duplicada: la mateixa imatge ja hi és amb la URL correcta';
                                $type   = 'missing_no_fix';
                            } elseif ( null !== $by_id && ! self::urls_equivalent( $url, $by_id ) ) {
                                $new_url = $by_id;
                                $reason  = 'Imatge amb ID #' . (int) $url_attachment[ $url ] . ': l\'adjunt té ara un altre nom';
                                $type    = 'missing';
                            }
                        }

                        // B1: Buscar el mateix nom en altres formats (jpg→webp, etc.)
                        foreach ( $type ? array() : self::$img_exts as $try_ext ) {
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
            'removable'           => count( $all_issues ) - $fixable,
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
        $fixed      = 0;
        $skipped    = 0;
        $errors     = array();
        $fixed_urls = array();

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
            if ( $affected > 0 ) {
                $fixed++;
                // Report back the exact string the caller sent us (post
                // esc_url_raw(), same as what class-ajax-handler.php passed
                // in) so the admin UI can remove only the rows that actually
                // succeeded from its list — matching by count alone made
                // every selected row disappear even when some were skipped.
                $fixed_urls[] = $old;
            } else {
                $skipped++;
            }
        }

        if ( $fixed > 0 ) {
            if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
            do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
            if ( function_exists( 'rocket_clean_domain' ) )  rocket_clean_domain();
            if ( function_exists( 'w3tc_flush_all' ) )       w3tc_flush_all();
            if ( function_exists( 'wpfc_clear_all_cache' ) ) wpfc_clear_all_cache();
        }

        return array(
            'fixed'      => $fixed,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'fixed_urls' => $fixed_urls,
        );
    }

    /**
     * Remove broken image URL references from posts, postmeta, and options.
     *
     * A backup of every row that is about to change is written first; when the
     * backup cannot be written nothing is modified.
     *
     * @param string[] $urls Absolute uploads URLs to strip from storage.
     * @return array{removed:int,skipped:int,errors:string[],removed_urls:string[],backup:string}
     */
    public static function remove_urls( $urls ) {
        $skipped = 0;
        $errors  = array();
        $todo    = array();

        foreach ( (array) $urls as $url ) {
            $url = esc_url_raw( (string) $url );
            if ( ! $url ) {
                $skipped++;
                continue;
            }

            if ( ! self::is_uploads_media_url( $url ) ) {
                $path     = wp_parse_url( $url, PHP_URL_PATH );
                $errors[] = 'URL fora de uploads: ' . basename( '' !== $path ? (string) $path : $url );
                $skipped++;
                continue;
            }

            if ( null !== self::uploads_path_from_url( $url, true ) ) {
                $errors[] = 'El fitxer encara existeix al disc: ' . basename( $url );
                $skipped++;
                continue;
            }

            $todo[] = $url;
        }

        $result = array(
            'removed'      => 0,
            'skipped'      => $skipped,
            'errors'       => $errors,
            'removed_urls' => array(),
            'backup'       => '',
        );

        if ( empty( $todo ) ) {
            return $result;
        }

        // Dry run: collect the original rows that would change.
        $snap  = array( 'posts' => array(), 'meta' => array(), 'options' => array() );
        $would = array();
        foreach ( $todo as $url ) {
            if ( self::remove_url_from_db( $url, false, $snap ) > 0 ) {
                $would[] = $url;
            } else {
                $result['skipped']++;
            }
        }

        if ( empty( $would ) ) {
            return $result;
        }

        $backup_file = self::write_removal_backup( $would, $snap );
        if ( '' === $backup_file ) {
            $result['errors'][]  = 'No s\'ha pogut crear la còpia de seguretat; no s\'ha modificat res.';
            $result['skipped']  += count( $would );
            return $result;
        }

        foreach ( $would as $url ) {
            if ( self::remove_url_from_db( $url, true ) > 0 ) {
                $result['removed']++;
                $result['removed_urls'][] = $url;
            } else {
                $result['skipped']++;
            }
        }

        self::finalize_removal_backup( $backup_file );
        $result['backup'] = $backup_file;

        if ( $result['removed'] > 0 ) {
            if ( function_exists( 'wp_cache_flush' ) ) {
                wp_cache_flush();
            }
            do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
            if ( function_exists( 'rocket_clean_domain' ) ) {
                rocket_clean_domain();
            }
            if ( function_exists( 'w3tc_flush_all' ) ) {
                w3tc_flush_all();
            }
            if ( function_exists( 'wpfc_clear_all_cache' ) ) {
                wpfc_clear_all_cache();
            }
        }

        return $result;
    }

    /**
     * Strip one uploads URL from stored text (HTML, blocks, serialized values).
     *
     * @param string $value Stored value.
     * @param string $url   URL to remove.
     * @return string
     */
    private static function remove_url_from_stored_value( $value, $url ) {
        $value = (string) $value;
        if ( '' === $value || '' === (string) $url ) {
            return $value;
        }

        if ( is_serialized( $value ) ) {
            $data = maybe_unserialize( $value );
            if ( is_array( $data ) || is_object( $data ) ) {
                $data = map_deep(
                    $data,
                    function( $item ) use ( $url ) {
                        return is_string( $item ) ? self::remove_url_from_text( $item, $url ) : $item;
                    }
                );
                return maybe_serialize( $data );
            }
        }

        return self::remove_url_from_text( $value, $url );
    }

    /**
     * Remove only the <img> tag(s) that use the broken URL, together with a link that
     * wraps only that image; a link with other content is unwrapped, never deleted.
     *
     * @param string $html Content fragment.
     * @param string $u    Broken URL (one written form).
     * @return string
     */
    private static function strip_broken_img( $html, $u ) {
        $esc = preg_quote( $u, '/' );
        $img = '<img\b(?=[^>]*(?:\bsrc|\bsrcset)\s*=\s*["\'][^"\']*' . $esc . ')[^>]*\/?>';

        // <a ...><img broken></a> (the link wraps only the broken image).
        $html = preg_replace( '/<a\b[^>]*>\s*' . $img . '\s*<\/a>/i', '', $html );
        // Any remaining broken <img>.
        $html = preg_replace( '/' . $img . '/i', '', $html );
        // Link whose href is the broken URL: unwrap it, keeping whatever it wraps (image or text).
        $html = preg_replace_callback(
            '/<a\b[^>]*\bhref=(["\'])' . $esc . '\1[^>]*>([\s\S]*?)<\/a>/i',
            function ( $m ) {
                return $m[2];
            },
            $html
        );

        return $html;
    }

    /**
     * Remove the broken image from HTML/text without touching other images: a Gutenberg
     * image block is dropped only when no other <img> is left in it.
     *
     * @param string $text  Content fragment.
     * @param string $url   Broken media URL.
     * @return string
     */
    private static function remove_url_from_text( $text, $url ) {
        $text = (string) $text;
        if ( '' === $text || '' === (string) $url ) {
            return $text;
        }

        $variants = array_values(
            array_unique(
                array_filter(
                    array( $url, rawurldecode( $url ) ),
                    'strlen'
                )
            )
        );

        foreach ( $variants as $u ) {
            $text = preg_replace_callback(
                '/<!-- wp:image\b[^>]*-->[\s\S]*?<!-- \/wp:image -->/i',
                function ( $match ) use ( $u ) {
                    $block = $match[0];
                    if ( false === stripos( $block, $u ) && false === stripos( rawurldecode( $block ), rawurldecode( $u ) ) ) {
                        return $block;
                    }
                    $stripped = self::strip_broken_img( $block, $u );
                    // Keep the block when another image is still inside it.
                    return ( false !== stripos( $stripped, '<img' ) ) ? $stripped : '';
                },
                $text
            );

            $text = self::strip_broken_img( $text, $u );
            $text = str_replace( $u, '', $text );
        }

        $text = preg_replace( '/<figure\b[^>]*>\s*<\/figure>/i', '', $text );
        $text = preg_replace( '/<p>\s*<\/p>/i', '', $text );

        return $text;
    }

    /**
     * Remove one broken URL everywhere it appears in public storage.
     *
     * @param string     $url   Media URL.
     * @param bool       $apply False = dry run (only collects the original rows into $snap).
     * @param array|null $snap  Collector for original rows (posts, meta, options).
     * @return int Number of storage rows that change.
     */
    private static function remove_url_from_db( $url, $apply = true, &$snap = null ) {
        global $wpdb;
        $affected = 0;

        $variants = array_values(
            array_unique(
                array_filter(
                    array( $url, rawurldecode( $url ) ),
                    'strlen'
                )
            )
        );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        foreach ( $variants as $u ) {
            $like = '%' . $wpdb->esc_like( $u ) . '%';

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
                     WHERE (post_content LIKE %s OR post_excerpt LIKE %s) AND post_status != 'trash'",
                    $like,
                    $like
                )
            );
            foreach ( (array) $rows as $row ) {
                $content_updated = self::remove_url_from_text( (string) $row->post_content, $url );
                $excerpt_updated = self::remove_url_from_text( (string) $row->post_excerpt, $url );
                if ( $content_updated !== (string) $row->post_content || $excerpt_updated !== (string) $row->post_excerpt ) {
                    if ( is_array( $snap ) && ! isset( $snap['posts'][ (int) $row->ID ] ) ) {
                        $snap['posts'][ (int) $row->ID ] = array(
                            'content' => (string) $row->post_content,
                            'excerpt' => (string) $row->post_excerpt,
                        );
                    }
                    if ( $apply ) {
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
                        clean_post_cache( (int) $row->ID );
                    }
                    $affected++;
                }
            }

            $meta_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                    $like
                )
            );
            foreach ( (array) $meta_rows as $row ) {
                $updated = self::remove_url_from_stored_value( (string) $row->meta_value, $url );
                if ( $updated !== (string) $row->meta_value ) {
                    if ( is_array( $snap ) && ! isset( $snap['meta'][ (int) $row->meta_id ] ) ) {
                        $snap['meta'][ (int) $row->meta_id ] = array(
                            'post_id' => (int) $row->post_id,
                            'key'     => (string) $row->meta_key,
                            'value'   => (string) $row->meta_value,
                        );
                    }
                    if ( $apply ) {
                        TSOIMMA_Optimizer::update_meta_row_raw( (int) $row->meta_id, (int) $row->post_id, $updated );
                    }
                    $affected++;
                }
            }

            $option_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options}
                     WHERE option_value LIKE %s
                     AND option_name NOT LIKE %s
                     AND option_name NOT LIKE %s",
                    $like,
                    $wpdb->esc_like( '_transient' ) . '%',
                    $wpdb->esc_like( '_site_transient' ) . '%'
                )
            );
            foreach ( (array) $option_rows as $row ) {
                $updated = self::remove_url_from_stored_value( (string) $row->option_value, $url );
                if ( $updated !== (string) $row->option_value ) {
                    if ( is_array( $snap ) && ! isset( $snap['options'][ (string) $row->option_name ] ) ) {
                        $snap['options'][ (string) $row->option_name ] = array( 'value' => (string) $row->option_value );
                    }
                    if ( $apply ) {
                        update_option( (string) $row->option_name, TSOIMMA_Optimizer::unwrap_serialized_for_write( $updated ) );
                    }
                    $affected++;
                }
            }
        }
        // phpcs:enable

        return $affected;
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

            $meta_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                    $like
                )
            );
            foreach ( (array) $meta_rows as $row ) {
                $updated = TSOIMMA_Optimizer::replace_in_stored_value( (string) $row->meta_value, $old, $new );
                if ( $updated !== (string) $row->meta_value ) {
                    TSOIMMA_Optimizer::update_meta_row_raw( (int) $row->meta_id, (int) $row->post_id, $updated );
                    $affected++;
                }
            }

            $option_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options}
                     WHERE option_value LIKE %s
                     AND option_name NOT LIKE %s
                     AND option_name NOT LIKE %s",
                    $like,
                    $wpdb->esc_like( '_transient' ) . '%',
                    $wpdb->esc_like( '_site_transient' ) . '%'
                )
            );
            foreach ( (array) $option_rows as $row ) {
                $updated = TSOIMMA_Optimizer::replace_in_stored_value( (string) $row->option_value, $old, $new );
                if ( $updated !== (string) $row->option_value ) {
                    update_option( (string) $row->option_name, TSOIMMA_Optimizer::unwrap_serialized_for_write( $updated ) );
                    $affected++;
                }
            }
        }
        // phpcs:enable

        return $affected;
    }

    /**
     * Map src URL => attachment ID for <img> tags that carry a wp-image-ID class.
     *
     * @param string $html Content fragment.
     * @return array<string,int>
     */
    private static function img_attachment_ids_from_html( $html ) {
        $found = array();
        if ( ! preg_match_all( '/<img\b[^>]*>/i', (string) $html, $tags ) ) {
            return $found;
        }
        foreach ( $tags[0] as $tag ) {
            if ( ! preg_match( '/\bwp-image-(\d+)\b/', $tag, $id_match ) ) {
                continue;
            }
            if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $tag, $src_match ) ) {
                continue;
            }
            $found[ $src_match[2] ] = (int) $id_match[1];
        }
        return $found;
    }

    /**
     * Current URL of an attachment that matches a stale (renamed) URL, keeping the
     * requested -WxH size when the old URL had one.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $stale_url     URL found in the content.
     * @return string|null
     */
    private static function current_url_for_attachment( $attachment_id, $stale_url ) {
        if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
            return null;
        }

        $full_url  = wp_get_attachment_url( $attachment_id );
        $full_path = get_attached_file( $attachment_id );
        if ( ! $full_url || ! $full_path || ! file_exists( $full_path ) || ! self::is_uploads_media_url( $full_url ) ) {
            return null;
        }

        if ( ! preg_match( '/-(\d+)x(\d+)\.[a-z0-9]+$/i', rawurldecode( (string) $stale_url ), $dims ) ) {
            return $full_url;
        }

        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
            return $full_url;
        }

        $dir_path = trailingslashit( dirname( $full_path ) );
        $dir_url  = trailingslashit( dirname( $full_url ) );
        $best     = null;
        $best_gap = PHP_INT_MAX;
        foreach ( $meta['sizes'] as $size_data ) {
            if ( empty( $size_data['file'] ) || empty( $size_data['width'] ) || empty( $size_data['height'] ) ) {
                continue;
            }
            if ( ! is_file( $dir_path . $size_data['file'] ) ) {
                continue;
            }
            $gap = abs( (int) $size_data['width'] - (int) $dims[1] ) + abs( (int) $size_data['height'] - (int) $dims[2] );
            if ( $gap < $best_gap ) {
                $best_gap = $gap;
                $best     = $dir_url . self::encode_rel_path_for_url( $size_data['file'] );
            }
        }

        return $best ? $best : $full_url;
    }

    /**
     * Whether any current file (full size or generated sizes) of the attachment is
     * already referenced in the given content.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $text          Post content (and other scanned sources).
     * @return bool
     */
    private static function attachment_already_in_text( $attachment_id, $text ) {
        $files = array();
        $main  = get_attached_file( $attachment_id );
        if ( $main ) {
            $files[] = basename( $main );
        }
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $files[] = (string) $size_data['file'];
                }
            }
        }
        $decoded = rawurldecode( (string) $text );
        foreach ( array_unique( $files ) as $file ) {
            if ( '' !== $file && ( false !== strpos( (string) $text, '/' . $file ) || false !== strpos( $decoded, '/' . $file ) ) ) {
                return true;
            }
        }
        return false;
    }

    // ----------------------------------------------------------------
    // Rename map (old relative path => new relative path)
    // ----------------------------------------------------------------

    /**
     * Remember renamed uploads files so a later scan can repair links that still
     * point to the old name instead of offering to delete them.
     *
     * @param array<int, array{0:string,1:string}> $pairs Old/new absolute URLs.
     * @return void
     */
    public static function record_renames( $pairs ) {
        $map     = tsoimma_get_renamed_map();
        $changed = false;

        foreach ( (array) $pairs as $pair ) {
            if ( empty( $pair[0] ) || empty( $pair[1] ) ) {
                continue;
            }
            $old = self::uploads_rel_path_from_url( (string) $pair[0] );
            $new = self::uploads_rel_path_from_url( (string) $pair[1] );
            if ( empty( $old ) || empty( $new ) || $old === $new ) {
                continue;
            }
            foreach ( $map as $key => $target ) {
                if ( $target === $old ) {
                    $map[ $key ] = $new;
                }
            }
            $map[ $old ] = $new;
            unset( $map[ $new ] );
            $changed = true;
        }

        if ( $changed ) {
            if ( count( $map ) > 20000 ) {
                $map = array_slice( $map, -20000, null, true );
            }
            tsoimma_set_renamed_map( $map );
        }
    }

    /**
     * Follow the rename map from a missing relative path to an existing file.
     *
     * @param string               $rel_path Missing relative path inside uploads.
     * @param array<string,string> $map      Rename map.
     * @param array                $base     uploads_base() result.
     * @return string|null Existing relative path, or null.
     */
    private static function resolve_renamed_rel( $rel_path, $map, $base ) {
        $rel_path = ltrim( (string) $rel_path, '/' );
        if ( '' === $rel_path || empty( $map ) ) {
            return null;
        }

        $follow = static function ( $start ) use ( $map, $base ) {
            $cur = $start;
            for ( $hop = 0; $hop < 6; $hop++ ) {
                if ( ! isset( $map[ $cur ] ) ) {
                    return null;
                }
                $cur = $map[ $cur ];
                if ( file_exists( wp_normalize_path( $base['dir'] . $cur ) ) ) {
                    return $cur;
                }
            }
            return null;
        };

        $hit = $follow( $rel_path );
        if ( null !== $hit ) {
            return $hit;
        }

        $pi = pathinfo( $rel_path );

        // Same name with another extension was renamed (e.g. old .png link, image converted to .webp and renamed later).
        if ( isset( $pi['extension'] ) ) {
            $dir_prefix = ( '.' === $pi['dirname'] ) ? '' : $pi['dirname'] . '/';
            foreach ( self::$img_exts as $try_ext ) {
                if ( strtolower( $try_ext ) === strtolower( $pi['extension'] ) ) {
                    continue;
                }
                $alt_hit = $follow( $dir_prefix . $pi['filename'] . '.' . $try_ext );
                if ( null !== $alt_hit ) {
                    return $alt_hit;
                }
            }
        }

        // Sized file (-WxH) that was not in the map: use the renamed main image's size or the main image.
        if ( isset( $pi['extension'] ) && preg_match( '/^(.+)-(\d+)x(\d+)$/', $pi['filename'], $m ) ) {
            $dir = ( '.' === $pi['dirname'] ) ? '' : $pi['dirname'] . '/';
            foreach ( self::$img_exts as $try_ext ) {
                $main = $follow( $dir . $m[1] . '.' . $try_ext );
                if ( null === $main ) {
                    continue;
                }
                $mp        = pathinfo( $main );
                $main_dir  = ( '.' === $mp['dirname'] ) ? '' : $mp['dirname'] . '/';
                $sized     = $main_dir . $mp['filename'] . '-' . $m[2] . 'x' . $m[3] . '.' . $pi['extension'];
                if ( file_exists( wp_normalize_path( $base['dir'] . $sized ) ) ) {
                    return $sized;
                }
                return $main;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------
    // Backups of "Remove from content"
    // ----------------------------------------------------------------

    /**
     * @return string Absolute directory (with trailing slash).
     */
    private static function removal_backup_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master/url-removal-backups/';
    }

    /**
     * @param string $path Absolute path.
     * @param string $data File contents.
     * @return bool
     */
    private static function write_file( $path, $data ) {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( empty( $wp_filesystem ) ) {
            return false;
        }
        return (bool) $wp_filesystem->put_contents( $path, $data, FS_CHMOD_FILE );
    }

    /**
     * @param string $path Absolute path.
     * @return string
     */
    private static function read_file( $path ) {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( empty( $wp_filesystem ) ) {
            return '';
        }
        $data = $wp_filesystem->get_contents( $path );
        return is_string( $data ) ? $data : '';
    }

    /**
     * Write the snapshot of the rows about to be changed.
     *
     * @param string[] $urls URLs being removed.
     * @param array    $snap Original rows.
     * @return string Backup file name, or '' on failure.
     */
    private static function write_removal_backup( $urls, $snap ) {
        $dir = self::removal_backup_dir();
        if ( ! wp_mkdir_p( $dir ) ) {
            return '';
        }

        foreach ( array(
            $dir . 'index.html' => '',
            $dir . '.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
        ) as $path => $content ) {
            if ( ! file_exists( $path ) ) {
                self::write_file( $path, $content );
            }
        }

        $payload = array(
            'version' => 1,
            'created' => time(),
            'urls'    => array_values( $urls ),
            'posts'   => $snap['posts'],
            'meta'    => $snap['meta'],
            'options' => $snap['options'],
        );
        $json = wp_json_encode( $payload );
        if ( ! is_string( $json ) || '' === $json ) {
            return '';
        }

        $name = 'url-removal-' . wp_date( 'Y-m-d-His' ) . '-' . strtolower( wp_generate_password( 8, false, false ) ) . '.json';
        if ( ! self::write_file( $dir . $name, $json ) || ! file_exists( $dir . $name ) ) {
            return '';
        }

        return $name;
    }

    /**
     * Store a fingerprint of each row after the removal so a later restore never
     * overwrites content that was edited in the meantime.
     *
     * @param string $name Backup file name.
     * @return void
     */
    private static function finalize_removal_backup( $name ) {
        global $wpdb;
        $path = self::removal_backup_dir() . $name;
        $data = json_decode( self::read_file( $path ), true );
        if ( ! is_array( $data ) ) {
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        foreach ( (array) $data['posts'] as $id => $row ) {
            $now = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_excerpt FROM {$wpdb->posts} WHERE ID = %d", (int) $id ) );
            $data['posts'][ $id ]['after'] = $now ? md5( $now->post_content . "\0" . $now->post_excerpt ) : '';
        }
        foreach ( (array) $data['meta'] as $id => $row ) {
            $now = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", (int) $id ) );
            $data['meta'][ $id ]['after'] = is_string( $now ) ? md5( $now ) : '';
        }
        foreach ( (array) $data['options'] as $opt => $row ) {
            $now = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", (string) $opt ) );
            $data['options'][ $opt ]['after'] = is_string( $now ) ? md5( $now ) : '';
        }
        // phpcs:enable

        $json = wp_json_encode( $data );
        if ( is_string( $json ) && '' !== $json ) {
            self::write_file( $path, $json );
        }
    }

    /**
     * @param string $name Backup file name from the browser.
     * @return string Absolute path, or '' when invalid.
     */
    private static function resolve_removal_backup( $name ) {
        $name = basename( (string) $name );
        if ( ! preg_match( '/^url-removal-[a-z0-9-]+\.json$/', $name ) ) {
            return '';
        }
        $path = self::removal_backup_dir() . $name;
        return is_file( $path ) ? $path : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function list_removal_backups() {
        $dir   = self::removal_backup_dir();
        $items = array();
        if ( ! is_dir( $dir ) ) {
            return $items;
        }

        $files = glob( $dir . 'url-removal-*.json' );
        if ( ! is_array( $files ) ) {
            return $items;
        }
        rsort( $files );

        foreach ( array_slice( $files, 0, 50 ) as $path ) {
            $data = json_decode( self::read_file( $path ), true );
            if ( ! is_array( $data ) ) {
                continue;
            }
            $mtime   = (int) filemtime( $path );
            $items[] = array(
                'file'      => basename( $path ),
                'created'   => wp_date( 'd/m/Y H:i', isset( $data['created'] ) ? (int) $data['created'] : $mtime ),
                'urls'      => isset( $data['urls'] ) ? count( (array) $data['urls'] ) : 0,
                'posts'     => isset( $data['posts'] ) ? count( (array) $data['posts'] ) : 0,
                'other'     => ( isset( $data['meta'] ) ? count( (array) $data['meta'] ) : 0 ) + ( isset( $data['options'] ) ? count( (array) $data['options'] ) : 0 ),
                'size_h'    => size_format( (int) filesize( $path ) ),
                'restored'  => ! empty( $data['restored_at'] ),
            );
        }

        return $items;
    }

    /**
     * Put back the rows saved by a "Remove from content" action.
     *
     * Rows edited after the removal are skipped, never overwritten.
     *
     * @param string $name Backup file name.
     * @return array{restored:int,skipped:int,errors:string[]}|WP_Error
     */
    public static function restore_removal_backup( $name ) {
        global $wpdb;
        $path = self::resolve_removal_backup( $name );
        if ( '' === $path ) {
            return new WP_Error( 'invalid_backup', __( 'This does not look like a valid backup file.', 'tso-image-master' ) );
        }

        $data = json_decode( self::read_file( $path ), true );
        if ( ! is_array( $data ) || empty( $data['version'] ) ) {
            return new WP_Error( 'invalid_backup', __( 'This does not look like a valid backup file.', 'tso-image-master' ) );
        }

        $restored = 0;
        $skipped  = 0;
        $errors   = array();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        foreach ( (array) ( $data['posts'] ?? array() ) as $id => $row ) {
            $now = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_excerpt FROM {$wpdb->posts} WHERE ID = %d", (int) $id ) );
            if ( ! $now ) {
                $skipped++;
                continue;
            }
            if ( ! empty( $row['after'] ) && md5( $now->post_content . "\0" . $now->post_excerpt ) !== $row['after'] ) {
                $skipped++;
                /* translators: %d: post ID */
                $errors[] = sprintf( __( 'Post #%d was edited after the removal and was left untouched.', 'tso-image-master' ), (int) $id );
                continue;
            }
            $wpdb->update(
                $wpdb->posts,
                array(
                    'post_content' => (string) $row['content'],
                    'post_excerpt' => (string) $row['excerpt'],
                ),
                array( 'ID' => (int) $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            clean_post_cache( (int) $id );
            $restored++;
        }

        foreach ( (array) ( $data['meta'] ?? array() ) as $id => $row ) {
            $now = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", (int) $id ) );
            if ( ! is_string( $now ) || ( ! empty( $row['after'] ) && md5( $now ) !== $row['after'] ) ) {
                $skipped++;
                continue;
            }
            TSOIMMA_Optimizer::update_meta_row_raw( (int) $id, (int) $row['post_id'], (string) $row['value'] );
            $restored++;
        }

        foreach ( (array) ( $data['options'] ?? array() ) as $opt => $row ) {
            $now = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", (string) $opt ) );
            if ( ! is_string( $now ) || ( ! empty( $row['after'] ) && md5( $now ) !== $row['after'] ) ) {
                $skipped++;
                continue;
            }
            update_option( (string) $opt, TSOIMMA_Optimizer::unwrap_serialized_for_write( (string) $row['value'] ) );
            $restored++;
        }
        // phpcs:enable

        if ( $restored > 0 ) {
            $data['restored_at'] = time();
            $json                = wp_json_encode( $data );
            if ( is_string( $json ) && '' !== $json ) {
                self::write_file( $path, $json );
            }
            if ( function_exists( 'wp_cache_flush' ) ) {
                wp_cache_flush();
            }
            do_action( 'litespeed_purge_all' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
        }

        return array(
            'restored' => $restored,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );
    }

    /**
     * @param string $name Backup file name.
     * @return bool
     */
    public static function delete_removal_backup( $name ) {
        $path = self::resolve_removal_backup( $name );
        if ( '' === $path ) {
            return false;
        }
        wp_delete_file( $path );
        return ! file_exists( $path );
    }
}
