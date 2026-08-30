<?php
/**
 * Plugin Name:       TSO Image Master
 * Description:       Complete image optimization suite for WordPress: convert to WebP/JPG, resize, compress PDFs, find orphaned images, scan rogue files, fix broken image URLs, and manage SEO fields. Requires PHP GD library.
 * Version:           1.9.8
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Author:            Tu Soporte Online
 * Author URI:        https://www.tusoporteonline.es/blog
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tso-image-master
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ────────────────────────────────────────────────────────
define( 'TSOIMMA_VERSION',    '1.9.8' );
define( 'TSOIMMA_FILE',       __FILE__ );
define( 'TSOIMMA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TSOIMMA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TSOIMMA_PATH',       TSOIMMA_PLUGIN_DIR );
define( 'TSOIMMA_URL',        TSOIMMA_PLUGIN_URL );

require_once TSOIMMA_PLUGIN_DIR . 'includes/tsoimma-storage.php';

// ── Load all classes ──────────────────────────────────────────────────
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-history.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-cache-helper.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-optimizer.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-image-manager.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-orphan-finder.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-rogue-scanner.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-url-fixer.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-pdf-compressor.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-auto-optimizer.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-dashboard.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-queue.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-backup-manager.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-duplicate-finder.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-post-editor-highlight.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-media-library.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once TSOIMMA_PLUGIN_DIR . 'admin/class-admin-page.php';

// ── Bootstrap ─────────────────────────────────────────────────────────
/**
 * Initialize the plugin on plugins_loaded.
 */
function tsoimma_init() {
    TSOIMMA_Admin_Page::init();
    TSOIMMA_Ajax_Handler::init();
    TSOIMMA_Auto_Optimizer::init();
    TSOIMMA_Queue::init();
    TSOIMMA_Backup_Manager::init();
    TSOIMMA_Media_Library::init();
    TSOIMMA_Post_Editor_Highlight::init();

    // WP-Cron: thumbnail processing in background after optimize
    add_action(
        'tsoimma_process_thumbnails',
        array( 'TSOIMMA_Ajax_Handler', 'process_thumbnails_cron' ),
        10,
        3
    );

    // WP-Cron: history auto-purge (interval configurable in admin)
    add_action( 'tsoimma_history_purge', array( 'TSOIMMA_History', 'auto_purge' ) );
    add_filter( 'cron_schedules', array( 'TSOIMMA_History', 'register_cron_schedules' ) );
}
add_action( 'plugins_loaded', 'tsoimma_init' );

/**
 * Load translations for the site locale (bundled `.mo` or WP language packs).
 * Uses load_textdomain() explicitly: JIT loading from wp.org happens too late for the Plugins screen headers.
 *
 * @return void
 */
function tsoimma_load_textdomain() {
    static $did_load = false;
    if ( $did_load ) {
        return;
    }

    $domain     = 'tso-image-master';
    $locale     = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    $candidates = array( (string) $locale );

    if ( false !== strpos( (string) $locale, '_' ) ) {
        $candidates[] = substr( (string) $locale, 0, strpos( (string) $locale, '_' ) );
    }
    if ( 0 === strpos( (string) $locale, 'es' ) ) {
        $candidates[] = 'es_ES';
    }
    if ( 0 === strpos( (string) $locale, 'ca' ) ) {
        $candidates[] = 'ca';
    }

    foreach ( array_unique( array_filter( $candidates ) ) as $candidate ) {
        $mofile = WP_LANG_DIR . '/plugins/' . $domain . '-' . $candidate . '.mo';
        if ( file_exists( $mofile ) ) {
            load_textdomain( $domain, $mofile );
            $did_load = true;
            return;
        }
        $mofile = TSOIMMA_PLUGIN_DIR . 'languages/' . $domain . '-' . $candidate . '.mo';
        if ( file_exists( $mofile ) ) {
            load_textdomain( $domain, $mofile );
            $did_load = true;
            return;
        }
    }

    $did_load = true;
}
add_action( 'plugins_loaded', 'tsoimma_load_textdomain', 0 );

/**
 * Ensure plugin Name/Description use the site locale on the Plugins screen.
 *
 * @param array<string, array<string, string>> $plugins All plugins.
 * @return array<string, array<string, string>>
 */
function tsoimma_translate_plugin_list_headers( $plugins ) {
    $basename = plugin_basename( __FILE__ );
    if ( ! isset( $plugins[ $basename ] ) ) {
        return $plugins;
    }

    tsoimma_load_textdomain();

    $plugins[ $basename ]['Name']        = __( 'TSO Image Master', 'tso-image-master' );
    $plugins[ $basename ]['Description'] = __(
        'Complete image optimization suite for WordPress: convert to WebP/JPG, resize, compress PDFs, find orphaned images, scan rogue files, fix broken image URLs, and manage SEO fields. Requires PHP GD library.',
        'tso-image-master'
    );

    return $plugins;
}
add_filter( 'all_plugins', 'tsoimma_translate_plugin_list_headers' );

/**
 * Activation: create/upgrade custom DB table and schedule cron.
 */
function tsoimma_activate() {
    TSOIMMA_History::maybe_install();

    TSOIMMA_History::schedule_purge_cron();
    TSOIMMA_Backup_Manager::schedule_purge_cron();
    update_option( 'tsoimma_version', TSOIMMA_VERSION );
}
register_activation_hook( __FILE__, 'tsoimma_activate' );

/**
 * Run upgrade tasks when the plugin version changes (without reactivation).
 */
function tsoimma_maybe_upgrade() {
    $stored = get_option( 'tsoimma_version', '' );
    if ( is_string( $stored ) && version_compare( $stored, TSOIMMA_VERSION, '>=' ) ) {
        return;
    }

    TSOIMMA_History::schedule_purge_cron();
    TSOIMMA_Backup_Manager::schedule_purge_cron();
    update_option( 'tsoimma_version', TSOIMMA_VERSION );
}
add_action( 'plugins_loaded', 'tsoimma_maybe_upgrade', 20 );

/**
 * Run DB migrations on admin requests (merge legacy history tables, etc.).
 *
 * @return void
 */
function tsoimma_admin_db_upgrade() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    TSOIMMA_History::maybe_install();
}
add_action( 'admin_init', 'tsoimma_admin_db_upgrade', 5 );

/**
 * Deactivation: remove scheduled events (data is preserved).
 */
function tsoimma_deactivate() {
    wp_clear_scheduled_hook( 'tsoimma_history_purge' );
    wp_clear_scheduled_hook( 'tsoimma_process_thumbnails' );
    wp_clear_scheduled_hook( 'tsoimma_process_queue' );
    wp_clear_scheduled_hook( 'tsoimma_backup_purge' );
    delete_option( 'tsoimma_queue_lock' );
}
register_deactivation_hook( __FILE__, 'tsoimma_deactivate' );
