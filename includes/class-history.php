<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_History {

    const TABLE         = 'tsoimma_history';
    const TABLE_LEGACY  = 'tso_im_history';
    const DB_VER        = '1.2';
    const OPT_VER       = 'tsoimma_db_version';
    const OPT_LEGACY_MERGED = 'tsoimma_history_legacy_merged';

    /**
     * Per-request cache for SHOW TABLES discovery.
     *
     * @var array{canonical: string, canonical_exists: bool, legacy: string[]}|null
     */
    private static $discovered_tables = null;

    /**
     * Per-request table existence cache.
     *
     * @var array<string, bool>
     */
    private static $table_exists_cache = array();

    /**
     * @var bool
     */
    private static $maybe_install_ran = false;

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
        self::set_named_table_exists( $table, true );
        self::reset_table_discovery_cache();
        update_option( self::OPT_VER, self::DB_VER );
    }

    public static function maybe_install() {
        if ( self::$maybe_install_ran ) {
            return;
        }
        self::$maybe_install_ran = true;

        try {
            self::migrate_history_tables();

            if ( get_option( self::OPT_VER ) !== self::DB_VER || ! self::table_exists() ) {
                self::install();
            }
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'TSOIMMA_History::maybe_install: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Rename, merge, and drop legacy history tables into the canonical table.
     *
     * @return void
     */
    private static function migrate_history_tables() {
        if ( '1' === get_option( self::OPT_LEGACY_MERGED, '' ) ) {
            return;
        }

        global $wpdb;

        $discovered       = self::discover_history_tables();
        $new_table        = $discovered['canonical'];
        $legacy           = $discovered['legacy'];
        $canonical_exists = ! empty( $discovered['canonical_exists'] );

        if ( ! $canonical_exists && ! empty( $legacy ) ) {
            $old_table = self::sanitize_history_table_name( array_shift( $legacy ) );
            $new_table = self::sanitize_history_table_name( $new_table );
            if ( '' !== $old_table && '' !== $new_table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old_table, $new_table ) );
            }
            self::reset_table_discovery_cache();
            $discovered       = self::discover_history_tables();
            $new_table        = $discovered['canonical'];
            $legacy           = $discovered['legacy'];
            $canonical_exists = ! empty( $discovered['canonical_exists'] );
        }

        if ( ! $canonical_exists ) {
            return;
        }

        foreach ( $legacy as $old_table ) {
            if ( $old_table === $new_table ) {
                continue;
            }
            if ( ! self::copy_history_rows( $old_table, $new_table ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( 'TSOIMMA_History merge failed (' . $old_table . '): ' . $wpdb->last_error );
                }
                continue;
            }
            self::drop_named_table( $old_table );
            self::reset_table_discovery_cache();
        }

        $discovered = self::discover_history_tables();
        if ( empty( $discovered['legacy'] ) ) {
            update_option( self::OPT_LEGACY_MERGED, '1' );
        }
    }

    /**
     * Discover canonical and legacy history tables with one SHOW TABLES query.
     *
     * @return array{canonical: string, canonical_exists: bool, legacy: string[]}
     */
    private static function discover_history_tables() {
        if ( null !== self::$discovered_tables ) {
            return self::$discovered_tables;
        }

        global $wpdb;

        $canonical_suffix = self::TABLE;
        $legacy_suffixes  = array(
            'imp_history',
            'tso_history',
            self::TABLE_LEGACY,
        );
        $expected_canonical = $wpdb->prefix . self::TABLE;

        $result = array(
            'canonical'        => $expected_canonical,
            'canonical_exists' => false,
            'legacy'           => array(),
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $tables = $wpdb->get_col(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $wpdb->prefix ) . '%'
            )
        );

        foreach ( (array) $tables as $table ) {
            if ( ! is_string( $table ) || '' === $table ) {
                continue;
            }

            self::$table_exists_cache[ $table ] = true;

            if ( self::table_name_ends_with( $table, $canonical_suffix ) ) {
                $result['canonical']        = $table;
                $result['canonical_exists'] = true;
                continue;
            }

            foreach ( $legacy_suffixes as $suffix ) {
                if ( self::table_name_ends_with( $table, $suffix ) ) {
                    $result['legacy'][] = $table;
                    break;
                }
            }
        }

        self::$discovered_tables = $result;

        return $result;
    }

    /**
     * @return void
     */
    private static function reset_table_discovery_cache() {
        self::$discovered_tables = null;
    }

    /**
     * Mark a table as present or absent after DDL in the same request.
     *
     * @param string $table_name Fully qualified table name.
     * @param bool   $exists     Whether the table exists.
     * @return void
     */
    private static function set_named_table_exists( $table_name, $exists ) {
        self::$table_exists_cache[ (string) $table_name ] = (bool) $exists;
    }

    /**
     * @param string $table_name Table name.
     * @param string $suffix     Suffix to match.
     * @return bool
     */
    private static function table_name_ends_with( $table_name, $suffix ) {
        $suffix = (string) $suffix;
        if ( '' === $suffix ) {
            return false;
        }

        return substr( (string) $table_name, -strlen( $suffix ) ) === $suffix;
    }

    /**
     * Allowlist a history table identifier (canonical or known legacy suffixes only).
     * Returns the raw name for use with $wpdb->prepare( '%i', ... ) (WP 6.2+).
     *
     * @param string $table_name Candidate fully-qualified table name.
     * @return string Table name, or empty string if not allowed.
     */
    private static function sanitize_history_table_name( $table_name ) {
        global $wpdb;

        $table_name = (string) $table_name;
        if ( '' === $table_name ) {
            return '';
        }

        // Only tables under this site prefix.
        if ( 0 !== strpos( $table_name, $wpdb->prefix ) ) {
            return '';
        }

        $allowed_suffixes = array(
            self::TABLE,
            self::TABLE_LEGACY,
            'imp_history',
            'tso_history',
        );

        $allowed = false;
        foreach ( $allowed_suffixes as $suffix ) {
            if ( self::table_name_ends_with( $table_name, $suffix ) ) {
                $allowed = true;
                break;
            }
        }
        if ( ! $allowed ) {
            return '';
        }

        // Identifiers must not contain backticks; %i will quote safely.
        return str_replace( '`', '', $table_name );
    }

    /**
     * @param string $from_table Fully qualified table name.
     * @param string $to_table   Fully qualified table name.
     * @return bool
     */
    private static function copy_history_rows( $from_table, $to_table ) {
        global $wpdb;

        $from_table = self::sanitize_history_table_name( $from_table );
        $to_table   = self::sanitize_history_table_name( $to_table );
        if ( '' === $from_table || '' === $to_table ) {
            return false;
        }

        $wpdb->last_error = '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (attachment_id, action_type, user_id, created_at, details)
                 SELECT o.attachment_id, o.action_type, o.user_id, o.created_at, o.details
                 FROM %i o
                 WHERE NOT EXISTS (
                    SELECT 1 FROM %i n
                    WHERE n.attachment_id = o.attachment_id
                      AND n.action_type = o.action_type
                      AND n.user_id = o.user_id
                      AND n.created_at = o.created_at
                 )',
                $to_table,
                $from_table,
                $to_table
            )
        );

        if ( '' === $wpdb->last_error ) {
            return true;
        }

        $wpdb->last_error = '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT attachment_id, action_type, user_id, created_at, details FROM %i',
                $from_table
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(1) FROM %i WHERE attachment_id = %d AND action_type = %s AND user_id = %d AND created_at = %s',
                    $to_table,
                    (int) $row['attachment_id'],
                    (string) $row['action_type'],
                    (int) $row['user_id'],
                    (string) $row['created_at']
                )
            );

            if ( $exists > 0 ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $to_table,
                array(
                    'attachment_id' => (int) $row['attachment_id'],
                    'action_type'   => (string) $row['action_type'],
                    'user_id'       => (int) $row['user_id'],
                    'created_at'    => (string) $row['created_at'],
                    'details'       => (string) $row['details'],
                ),
                array( '%d', '%s', '%d', '%s', '%s' )
            );

            if ( '' !== $wpdb->last_error ) {
                return false;
            }
        }

        return '' === $wpdb->last_error;
    }

    /**
     * @param string $table_name Fully qualified table name.
     * @return void
     */
    private static function drop_named_table( $table_name ) {
        global $wpdb;

        $table_name = self::sanitize_history_table_name( $table_name );
        if ( '' === $table_name ) {
            return;
        }

        // Intentional DDL after a successful legacy→canonical merge.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
        self::set_named_table_exists( $table_name, false );
    }

    /**
     * @return string
     */
    private static function get_canonical_table_name() {
        $discovered = self::discover_history_tables();

        return $discovered['canonical'];
    }

    /**
     * @param int                  $attachment_id Attachment ID.
     * @param string               $action_type   Action key.
     * @param array<string, mixed> $details       Details payload.
     * @param string|null          $created_at    Optional MySQL datetime (local WP time); null = now.
     * @return bool True when a row was inserted.
     */
    public static function log( $attachment_id, $action_type, $details = array(), $created_at = null ) {
        global $wpdb;
        try {
            if ( ! self::table_exists() ) {
                self::maybe_install();
            }

            if ( ! self::table_exists() ) {
                return false;
            }

            $file = get_attached_file( $attachment_id );
            if ( empty( $details['filename'] ) ) {
                $details['filename'] = $file ? basename( $file ) : '';
            }
            // Use attachment_title — never 'title', which collides with SEO seo_title display.
            if ( empty( $details['attachment_title'] ) ) {
                $details['attachment_title'] = get_the_title( $attachment_id );
            }

            $when = is_string( $created_at ) ? trim( $created_at ) : '';
            if ( '' === $when || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $when ) ) {
                $when = current_time( 'mysql' );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $inserted = $wpdb->insert(
                self::get_canonical_table_name(),
                array(
                    'attachment_id' => absint( $attachment_id ),
                    'action_type'   => sanitize_key( $action_type ),
                    'user_id'       => get_current_user_id(),
                    'created_at'    => $when,
                    'details'       => wp_json_encode( $details ),
                ),
                array( '%d', '%s', '%d', '%s', '%s' )
            );
            if ( false === $inserted || $wpdb->last_error ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( 'TSOIMMA_History::log DB error: ' . $wpdb->last_error );
                }
                return false;
            }
            return true;
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'TSOIMMA_History::log: ' . $e->getMessage() );
            }
            return false;
        }
    }

    /**
     * Stash optimize history (legacy / recovery path). Prefer log() immediately after convert.
     *
     * @param int                  $attachment_id Attachment ID.
     * @param string               $action_type   Action key (optimize, etc.).
     * @param array<string, mixed> $details       History details.
     * @return void
     */
    public static function stash_pending( $attachment_id, $action_type, $details = array() ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 ) {
            return;
        }
        tsoimma_update_attachment_meta(
            $attachment_id,
            'pending_history',
            array(
                'action'  => sanitize_key( $action_type ),
                'details' => is_array( $details ) ? $details : array(),
            )
        );
    }

    /**
     * Write any stashed optimize history (call after thumbnails succeed).
     *
     * @param int $attachment_id Attachment ID.
     * @return bool True if a pending entry was logged.
     */
    public static function flush_pending( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 ) {
            return false;
        }
        $pending = tsoimma_get_attachment_meta( $attachment_id, 'pending_history' );
        if ( ! is_array( $pending ) || empty( $pending['action'] ) ) {
            tsoimma_delete_attachment_meta( $attachment_id, 'pending_history' );
            return false;
        }
        $details = isset( $pending['details'] ) && is_array( $pending['details'] ) ? $pending['details'] : array();
        // Log first — only drop the stash after a successful insert (avoids silent data loss).
        if ( ! self::log( $attachment_id, (string) $pending['action'], $details ) ) {
            return false;
        }
        tsoimma_delete_attachment_meta( $attachment_id, 'pending_history' );
        return true;
    }

    /**
     * Drop stashed history without writing (rollback / hard failure).
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public static function clear_pending( $attachment_id ) {
        tsoimma_delete_attachment_meta( absint( $attachment_id ), 'pending_history' );
    }

    /**
     * Flush every stashed pending_history meta (recovers entries lost when thumbs never ran).
     *
     * @return int Number of entries written.
     */
    public static function flush_all_pending() {
        global $wpdb;

        $key = tsoimma_get_attachment_meta_key( 'pending_history' );
        if ( '' === $key ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT %d",
                $key,
                200
            )
        );

        $count = 0;
        foreach ( (array) $ids as $attachment_id ) {
            if ( self::flush_pending( absint( $attachment_id ) ) ) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * Recover missing optimize history rows from existing TSO backup meta/files.
     *
     * Covers the 2.0.0 bug where pending_history was deleted before a successful INSERT
     * (or never flushed when thumbnails AJAX/cron never ran).
     *
     * @return int Number of rows inserted.
     */
    public static function backfill_optimize_from_backups() {
        global $wpdb;

        if ( ! self::table_exists() ) {
            self::maybe_install();
        }
        $table = self::sanitize_history_table_name( self::get_canonical_table_name() );
        if ( ! self::table_exists() || '' === $table ) {
            return 0;
        }

        $canonical_key = tsoimma_get_attachment_meta_key( 'backup_file' );
        $legacy_key    = tsoimma_get_attachment_meta_key_legacy( 'backup_file' );
        if ( '' === $canonical_key ) {
            return 0;
        }

        // Fixed placeholders only (Plugin Check: no interpolated IN lists).
        if ( '' !== $legacy_key && $legacy_key !== $canonical_key ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key IN ( %s, %s )
                     ORDER BY meta_id DESC
                     LIMIT %d",
                    $canonical_key,
                    $legacy_key,
                    150
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = %s
                     ORDER BY meta_id DESC
                     LIMIT %d",
                    $canonical_key,
                    150
                )
            );
        }

        $count     = 0;
        $seen_ids  = array();
        $tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string' );
        try {
            $tz = ( is_string( $tz_string ) && '' !== $tz_string ) ? new \DateTimeZone( $tz_string ) : wp_timezone();
        } catch ( \Exception $e ) {
            $tz = wp_timezone();
        }

        foreach ( (array) $rows as $row ) {
            $attachment_id = absint( $row->post_id );
            if ( $attachment_id <= 0 || isset( $seen_ids[ $attachment_id ] ) ) {
                continue;
            }
            $seen_ids[ $attachment_id ] = true;

            $post = get_post( $attachment_id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                continue;
            }

            $backup_path = '';
            if ( class_exists( 'TSOIMMA_Optimizer' ) ) {
                $status = TSOIMMA_Optimizer::get_backup_status( $attachment_id, false );
                if ( ! empty( $status['has_backup'] ) && ! empty( $status['backup_path'] ) ) {
                    $backup_path = (string) $status['backup_path'];
                }
            }
            if ( '' === $backup_path ) {
                $raw = is_string( $row->meta_value ) ? $row->meta_value : '';
                if ( $raw && file_exists( $raw ) ) {
                    $backup_path = $raw;
                }
            }
            if ( '' === $backup_path || ! file_exists( $backup_path ) ) {
                continue;
            }

            $mtime = @filemtime( $backup_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( ! $mtime ) {
                continue;
            }

            $dt = new \DateTime( '@' . (int) $mtime );
            $dt->setTimezone( $tz );
            $created_at   = $dt->format( 'Y-m-d H:i:s' );
            $window_start = ( clone $dt )->modify( '-2 minutes' )->format( 'Y-m-d H:i:s' );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $already = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1) FROM %i
                     WHERE attachment_id = %d
                       AND action_type IN ('optimize','auto_optimize')
                       AND created_at >= %s",
                    $table,
                    $attachment_id,
                    $window_start
                )
            );
            if ( $already > 0 ) {
                continue;
            }

            $file = get_attached_file( $attachment_id );
            $ext  = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
            if ( 'jpeg' === $ext ) {
                $ext = 'jpg';
            }

            $orig_size = (int) @filesize( $backup_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $new_size  = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
            $savings   = max( 0, $orig_size - $new_size );
            $pct       = $orig_size > 0 ? round( ( 1 - $new_size / $orig_size ) * 100, 1 ) : 0;

            $ok = self::log(
                $attachment_id,
                'optimize',
                array(
                    'filename'      => $file ? basename( $file ) : basename( $backup_path ),
                    'format'        => $ext,
                    'original_size' => $orig_size,
                    'new_size'      => $new_size,
                    'savings_bytes' => $savings,
                    'savings_pct'   => $pct,
                    'replaced'      => true,
                    'recovered'     => true,
                ),
                $created_at
            );
            if ( $ok ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Run all history recovery helpers once per request.
     *
     * @return int Total rows written.
     */
    public static function recover_missing_optimize_entries() {
        static $ran = false;
        if ( $ran ) {
            return 0;
        }
        $ran = true;

        return self::flush_all_pending() + self::backfill_optimize_from_backups();
    }

    public static function get_entries( $args = array() ) {
        global $wpdb;

        // Recover optimize rows lost when thumbs never ran / pending was dropped early.
        self::recover_missing_optimize_entries();

        $table = self::sanitize_history_table_name( self::get_canonical_table_name() );

        if ( ! self::table_exists() || '' === $table ) {
            self::maybe_install();
            $table = self::sanitize_history_table_name( self::get_canonical_table_name() );
            if ( ! self::table_exists() || '' === $table ) {
                return array( 'items' => array(), 'total' => 0, 'total_pages' => 1, 'page' => 1 );
            }
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

        // Fixed placeholder SQL only (no concatenated fragments) for Plugin Check.
        $attachment_id = absint( $args['attachment_id'] );
        $action_type   = sanitize_key( (string) $args['action_type'] );
        $date_from     = sanitize_text_field( (string) $args['date_from'] );
        $date_to       = sanitize_text_field( (string) $args['date_to'] );

        if ( $search !== '' ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT h.*, u.display_name as user_name FROM %i h LEFT JOIN %i u ON u.ID = h.user_id
                     WHERE ( %d = 0 OR h.attachment_id = %d )
                       AND ( %s = '' OR h.action_type = %s )
                       AND ( %s = '' OR DATE(h.created_at) >= %s )
                       AND ( %s = '' OR DATE(h.created_at) <= %s )
                     ORDER BY h.created_at DESC",
                    $table,
                    $wpdb->users,
                    $attachment_id,
                    $attachment_id,
                    $action_type,
                    $action_type,
                    $date_from,
                    $date_from,
                    $date_to,
                    $date_to
                )
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i h
                 WHERE ( %d = 0 OR h.attachment_id = %d )
                   AND ( %s = '' OR h.action_type = %s )
                   AND ( %s = '' OR DATE(h.created_at) >= %s )
                   AND ( %s = '' OR DATE(h.created_at) <= %s )",
                $table,
                $attachment_id,
                $attachment_id,
                $action_type,
                $action_type,
                $date_from,
                $date_from,
                $date_to,
                $date_to
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.*, u.display_name as user_name FROM %i h LEFT JOIN %i u ON u.ID = h.user_id
                 WHERE ( %d = 0 OR h.attachment_id = %d )
                   AND ( %s = '' OR h.action_type = %s )
                   AND ( %s = '' OR DATE(h.created_at) >= %s )
                   AND ( %s = '' OR DATE(h.created_at) <= %s )
                 ORDER BY h.created_at DESC
                 LIMIT %d OFFSET %d",
                $table,
                $wpdb->users,
                $attachment_id,
                $attachment_id,
                $action_type,
                $action_type,
                $date_from,
                $date_from,
                $date_to,
                $date_to,
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
            'full_url'      => wp_get_attachment_image_url( (int) $row->attachment_id, 'full' ) ?: ( wp_get_attachment_url( (int) $row->attachment_id ) ?: '' ),
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

        self::recover_missing_optimize_entries();

        $table = self::sanitize_history_table_name( self::get_canonical_table_name() );

        $stats = array(
            'total_operations' => 0,
            'total_saved_bytes' => 0,
            'total_saved_h'     => '0 B',
            'by_type'           => array(),
        );
        if ( ! self::table_exists() || '' === $table ) {
            return $stats;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT action_type, COUNT(*) as cnt FROM %i GROUP BY action_type',
                $table
            )
        );
        foreach ( (array) $rows as $r ) {
            $stats['by_type'][ $r->action_type ] = (int) $r->cnt;
            $stats['total_operations'] += (int) $r->cnt;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $details_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT details FROM %i WHERE action_type IN ('optimize','auto_optimize','pdf_compress')",
                $table
            )
        );

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
        $table = self::sanitize_history_table_name( self::get_canonical_table_name() );
        if ( ! self::table_exists() || '' === $table ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $table,
            array( 'attachment_id' => absint( $attachment_id ) ),
            array( '%d' )
        );
    }

    /**
     * Remove the newest history row for an attachment + action (e.g. after rollback).
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $action_type   Action key.
     * @return bool True when a row was deleted.
     */
    public static function delete_latest_for_attachment( $attachment_id, $action_type ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );
        $action_type   = sanitize_key( (string) $action_type );
        $table         = self::sanitize_history_table_name( self::get_canonical_table_name() );
        if ( $attachment_id <= 0 || '' === $action_type || ! self::table_exists() || '' === $table ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE attachment_id = %d AND action_type = %s ORDER BY id DESC LIMIT 1',
                $table,
                $attachment_id,
                $action_type
            )
        );
        if ( $row_id <= 0 ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete( $table, array( 'id' => $row_id ), array( '%d' ) );
        return false !== $deleted && $deleted > 0;
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
        $table = self::sanitize_history_table_name( self::get_canonical_table_name() );
        if ( ! self::table_exists() || '' === $table ) {
            return;
        }

        $days = absint( $days );
        $type = sanitize_key( (string) $type );

        // Compare against WP local time (same clock as History::log current_time( 'mysql' )).
        // Avoid MySQL NOW() which follows the DB server timezone.
        if ( $days > 0 ) {
            try {
                $cutoff = ( new \DateTimeImmutable( 'now', wp_timezone() ) )
                    ->modify( '-' . $days . ' days' )
                    ->format( 'Y-m-d H:i:s' );
            } catch ( \Exception $e ) {
                $cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM %i
                     WHERE created_at < %s
                       AND ( %s = '' OR action_type = %s )",
                    $table,
                    $cutoff,
                    $type,
                    $type
                )
            );
            return;
        }

        // days=0 → delete all rows matching type (or everything when type is empty).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i
                 WHERE ( %s = '' OR action_type = %s )",
                $table,
                $type,
                $type
            )
        );
    }

    private static function table_exists() {
        $discovered = self::discover_history_tables();

        return ! empty( $discovered['canonical_exists'] );
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
