<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_History {

    const TABLE   = 'tso_im_history';
    const DB_VER  = '1.0';
    const OPT_VER = 'tsoimma_db_version';

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

        // Patró canònic WP per a WHERE dinàmics:
        // Cada condició es prepara individualment → $where ja conté SQL segur.
        // La query final no necessita un segon prepare() per als valors de filtre.
        $where = '1=1';

        if ( $args['attachment_id'] ) {
            $where .= $wpdb->prepare( ' AND h.attachment_id = %d', (int) $args['attachment_id'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( $args['action_type'] ) {
            $where .= $wpdb->prepare( ' AND h.action_type = %s', $args['action_type'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( $args['search'] ) {
            $where .= $wpdb->prepare( ' AND h.details LIKE %s', '%' . $wpdb->esc_like( $args['search'] ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( $args['date_from'] ) {
            $where .= $wpdb->prepare( ' AND DATE(h.created_at) >= %s', $args['date_from'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( $args['date_to'] ) {
            $where .= $wpdb->prepare( ' AND DATE(h.created_at) <= %s', $args['date_to'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $offset   = ( (int) $args['page'] - 1 ) * (int) $args['per_page'];
        $per_page = (int) $args['per_page'];

        // $where ja és segur (cada condició preparada individualment).
        // $table és trusted (prefix WP + constant, no input d'usuari).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' h WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            'SELECT h.*, u.display_name as user_name FROM ' . $table . ' h LEFT JOIN ' . $wpdb->users . ' u ON u.ID = h.user_id WHERE ' . $where . ' ORDER BY h.created_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $per_page,
            $offset
        ) );

        $items = array();
        foreach ( (array) $rows as $row ) {
            $d = json_decode( isset( $row->details ) ? $row->details : '{}', true );
            if ( ! is_array( $d ) ) $d = array();
            $items[] = array(
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

        return array(
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 1,
            'page'        => (int) $args['page'],
        );
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
     * Neteja automàtica: elimina entrades més antigues que tso_history_retention_days dies.
     * Cridat pel WP-Cron setmanal. Per defecte: 90 dies.
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
        if ( ! self::table_exists() ) return;

        // Patró canònic WP: cada condició preparada individualment.
        $where = '1=1';
        if ( $days > 0 ) {
            $where .= $wpdb->prepare( ' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $days ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ( $type !== '' ) {
            $where .= $wpdb->prepare( ' AND action_type = %s', $type ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // $where ja és segur. $table és trusted (prefix WP + constant).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . $table . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
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
