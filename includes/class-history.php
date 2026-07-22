<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_History {

    const TABLE   = 'tso_im_history';
    const DB_VER  = '1.0';
    const OPT_VER = 'tsoimma_db_version';

    /** @var string[] Allowed WP-Cron intervals for history auto-purge. */
    const PURGE_INTERVALS = array( 'daily', 'weekly', 'monthly' );

    /**
     * Register weekly/monthly schedules if the host WordPress build lacks them.
     *
     * @param array $schedules Existing cron schedules.
     * @return array
     */
    public static function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'tso-image-master' ),
            );
        }
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'tso-image-master' ),
            );
        }
        return $schedules;
    }

    /**
     * @return string
     */
    public static function get_purge_interval() {
        $interval = (string) get_option( 'tsoimma_history_purge_interval', 'weekly' );
        return in_array( $interval, self::PURGE_INTERVALS, true ) ? $interval : 'weekly';
    }

    /**
     * (Re)schedule history purge cron. Cleared when retention days is 0.
     *
     * @param string|null $interval Optional interval override.
     */
    public static function schedule_purge_cron( $interval = null ) {
        wp_clear_scheduled_hook( 'tsoimma_history_purge' );

        $days = (int) get_option( 'tsoimma_history_retention_days', 90 );
        if ( $days <= 0 ) {
            return;
        }

        if ( null === $interval ) {
            $interval = self::get_purge_interval();
        }
        if ( ! in_array( $interval, self::PURGE_INTERVALS, true ) ) {
            $interval = 'weekly';
        }

        wp_schedule_event( time(), $interval, 'tsoimma_history_purge' );
    }

    public static function install() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action_type   VARCHAR(50)     NOT NULL DEFAULT '',
            user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL,
            details       LONGTEXT,
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id),
            KEY action_type   (action_type),
            KEY created_at    (created_at)
        ) {$charset};";
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( $sql );
        update_option( self::OPT_VER, self::DB_VER );
    }

    public static function maybe_install() {
        global $wpdb;
        try {
            // Migració: renomenar taules antigues → wp_tso_im_history (una sola vegada)
            $new_table       = $wpdb->prefix . self::TABLE;
            $old_table_names = array(
                $wpdb->prefix . 'imp_history',  // nom original
                $wpdb->prefix . 'tso_history',  // nom intermedi v1
            );
            // Només intentar migrar si la taula destí NO existeix encara
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $new_exists = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s LIMIT 1',
                DB_NAME,
                $new_table
            ) );

            if ( ! $new_exists ) {
                foreach ( $old_table_names as $old_table ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $old_exists = (int) $wpdb->get_var( $wpdb->prepare(
                        'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s LIMIT 1',
                        DB_NAME,
                        $old_table
                    ) );
                    if ( $old_exists ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepareReplacement
                        $wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" ); // phpcs:ignore
                        break;
                    }
                }
            }

            // Instal·lar si la versió no coincideix O si la taula no existeix
            if ( get_option( self::OPT_VER ) !== self::DB_VER || ! self::table_exists() ) {
                self::install();
            }
        } catch ( \Throwable $e ) {
        }
    }

    public static function log( $attachment_id, $action_type, $details = array() ) {
        global $wpdb;
        try {
            // Assegurar que la taula existeix sempre abans d'inserir
            // (cobreix el cas de plugin actualitzat sense desactivar/reactivar)
            if ( ! self::table_exists() ) {
                self::install();
            }

            $file = get_attached_file( $attachment_id );
            if ( empty( $details['filename'] ) ) $details['filename'] = $file ? basename( $file ) : '';
            if ( empty( $details['title'] ) )    $details['title']    = get_the_title( $attachment_id );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $wpdb->prefix . self::TABLE,
                array(
                    'attachment_id' => absint( $attachment_id ),
                    'action_type'   => sanitize_key( $action_type ),
                    'user_id'       => get_current_user_id(),
                    'created_at'    => current_time( 'mysql' ),
                    'details'       => wp_json_encode( $details ),
                ),
                array( '%d', '%s', '%d', '%s', '%s' )
            );
            if ( $wpdb->last_error ) {
            }
        } catch ( \Throwable $e ) {
        }
    }

    public static function get_entries( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ( ! self::table_exists() ) {
            self::install();
            return array( 'items' => array(), 'total' => 0, 'total_pages' => 1, 'page' => 1 );
        }

        $defaults = array(
            'page'          => 1,
            'per_page'      => 50,
            'attachment_id' => 0,
            'action_type'   => '',
            'search'        => '',
            'date_from'     => '',
            'date_to'       => '',
        );
        $args = wp_parse_args( $args, $defaults );
        $search    = trim( (string) $args['search'] );
        $page      = max( 1, (int) $args['page'] );
        $per_page  = max( 1, (int) $args['per_page'] );
        $order_sql = ' ORDER BY h.created_at DESC';

        // Each filter uses $wpdb->prepare(); fragments are concatenated into $where (canonical WP pattern).
        $where = '1=1';
        if ( ! empty( $args['attachment_id'] ) ) {
            $where .= $wpdb->prepare( ' AND h.attachment_id = %d', (int) $args['attachment_id'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( ! empty( $args['action_type'] ) ) {
            $where .= $wpdb->prepare( ' AND h.action_type = %s', (string) $args['action_type'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where .= $wpdb->prepare( ' AND DATE(h.created_at) >= %s', (string) $args['date_from'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where .= $wpdb->prepare( ' AND DATE(h.created_at) <= %s', (string) $args['date_to'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        if ( $search !== '' ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results(
                'SELECT h.*, u.display_name as user_name FROM ' . $table . ' h LEFT JOIN ' . $wpdb->users . ' u ON u.ID = h.user_id WHERE ' . $where . $order_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );

            $items = array();
            foreach ( (array) $rows as $row ) {
                $item = self::format_history_row( $row );
                if ( self::history_entry_matches_search( $item['details'], $search ) ) {
                    $items[] = $item;
                }
            }

            $total       = count( $items );
            $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
            $offset      = ( $page - 1 ) * $per_page;
            $items       = array_slice( $items, $offset, $per_page );

            return array(
                'items'       => $items,
                'total'       => $total,
                'total_pages' => $total_pages,
                'page'        => $page,
            );
        }

        $offset = ( $page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' h WHERE ' . $where );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT h.*, u.display_name as user_name FROM ' . $table . ' h LEFT JOIN ' . $wpdb->users . ' u ON u.ID = h.user_id WHERE ' . $where . $order_sql . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $per_page,
                $offset
            )
        );

        $items = array();
        foreach ( (array) $rows as $row ) {
            $items[] = self::format_history_row( $row );
        }

        return array(
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 1,
            'page'        => $page,
        );
    }

    /**
     * @param object $row DB row.
     * @return array
     */
    private static function format_history_row( $row ) {
        $d = json_decode( isset( $row->details ) ? $row->details : '{}', true );
        if ( ! is_array( $d ) ) {
            $d = array();
        }

        return array(
            'id'            => (int) $row->id,
            'attachment_id' => (int) $row->attachment_id,
            'action_type'   => $row->action_type,
            'action_label'  => self::action_label( $row->action_type ),
            'user_name'     => ! empty( $row->user_name ) ? $row->user_name : 'Sistema',
            'created_at'    => $row->created_at,
            'created_at_h'  => date_i18n( 'd/m/Y H:i', strtotime( $row->created_at ) ),
            'details'       => $d,
            'thumb'         => wp_get_attachment_image_url( (int) $row->attachment_id, 'thumbnail' ) ?: '',
        );
    }

    /**
     * Prefix search on filename fields stored in history details JSON.
     *
     * @param array  $details Decoded details.
     * @param string $search  Search term.
     * @return bool
     */
    private static function history_entry_matches_search( array $details, $search ) {
        $search = trim( (string) $search );
        if ( $search === '' ) {
            return true;
        }

        foreach ( array( 'filename', 'old_filename', 'new_filename' ) as $key ) {
            if ( empty( $details[ $key ] ) ) {
                continue;
            }
            $base = pathinfo( (string) $details[ $key ], PATHINFO_FILENAME );
            if ( $base !== '' && self::starts_with_utf8( $base, $search ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case-insensitive UTF-8 prefix check without accent folding.
     *
     * @param string $haystack Filename base.
     * @param string $needle   Search prefix.
     * @return bool
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

    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $stats = array(
            'total_operations' => 0,
            'total_saved_bytes' => 0,
            'total_saved_h'     => '0 B',
            'by_type'           => array(),
        );
        if ( ! self::table_exists() ) return $stats;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT action_type, COUNT(*) as cnt FROM {$table} GROUP BY action_type" );
        foreach ( (array) $rows as $r ) {
            $stats['by_type'][ $r->action_type ] = (int) $r->cnt;
            $stats['total_operations'] += (int) $r->cnt;
        }

        $details_rows = $wpdb->get_col(
            "SELECT details FROM {$table} WHERE action_type IN ('optimize','auto_optimize','pdf_compress')"
        );
        // phpcs:enable

        $total_saved = 0;
        foreach ( (array) $details_rows as $json ) {
            $d = json_decode( $json ?: '{}', true );
            if ( is_array( $d ) && isset( $d['savings_bytes'] ) ) {
                $total_saved += (int) $d['savings_bytes'];
            }
        }
        $stats['total_saved_bytes'] = $total_saved;
        $stats['total_saved_h']     = size_format( $total_saved );
        return $stats;
    }


    /**
     * Elimina totes les entrades de l'historial d'un attachment concret.
     * Cridat per TSOIMMA_Image_Manager::delete() per deixar zero rastre a la taula custom.
     *
     * @param int $attachment_id
     */
    public static function delete_by_attachment( $attachment_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ( ! self::table_exists() ) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $table,
            array( 'attachment_id' => absint( $attachment_id ) ),
            array( '%d' )
        );
    }

    /**
     * Neteja automàtica (WP-Cron setmanal): elimina entrades més antigues que N dies.
     * Per defecte: conservar 90 dies; la comprovació s'executa un cop per setmana.
     */
    public static function auto_purge() {
        $days = (int) get_option( 'tsoimma_history_retention_days', 90 );
        if ( $days > 0 ) {
            self::clear( $days );
        }
    }

    public static function clear( $days = 0, $type = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ( ! self::table_exists() ) {
            return;
        }

        $where = '1=1';
        if ( $days > 0 ) {
            $where .= $wpdb->prepare( ' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $days ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( $type !== '' ) {
            $where .= $wpdb->prepare( ' AND action_type = %s', (string) $type ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( 'DELETE FROM ' . $table . ' WHERE ' . $where );
    }

    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s LIMIT 1',
            DB_NAME,
            $table
        ) ) > 0;
    }

    private static function action_label( $type ) {
        $map = array(
            'optimize'      => 'Optimitzada',
            'auto_optimize' => 'Auto-optimitzada',
            'rename'        => 'Reanomenada',
            'seo_update'    => 'SEO actualitzat',
            'delete'        => 'Eliminada',
            'pdf_compress'  => 'PDF comprimit',
            'revert'        => 'Revertida',
        );
        return isset( $map[ $type ] ) ? $map[ $type ] : $type;
    }
}
