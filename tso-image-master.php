<?php
/**
 * Plugin Name:       TSO Image Master
 * Description:       Complete image optimization suite for WordPress: convert to WebP/JPG, resize, compress PDFs, find orphaned images, scan rogue files, fix broken image URLs, and manage SEO fields. Requires PHP GD library.
 * Version:           1.5.8
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Tested up to:      6.9
 * Author:            Tu Soporte Online
 * Author URI:        https://tusoporteonline.es/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tso-image-master
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ────────────────────────────────────────────────────────
define( 'TSOIMMA_VERSION',    '1.5.8' );
define( 'TSOIMMA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TSOIMMA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ── Load all classes ──────────────────────────────────────────────────
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-history.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-optimizer.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-image-manager.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-orphan-finder.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-rogue-scanner.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-url-fixer.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-pdf-compressor.php';
require_once TSOIMMA_PLUGIN_DIR . 'includes/class-auto-optimizer.php';
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

    // WP-Cron: thumbnail processing in background after optimize
    add_action(
        'tsoimma_process_thumbnails',
        array( 'TSOIMMA_Ajax_Handler', 'process_thumbnails_cron' ),
        10,
        3
    );

    // WP-Cron: weekly history auto-purge
    add_action( 'tsoimma_history_purge', array( 'TSOIMMA_History', 'auto_purge' ) );
}
add_action( 'plugins_loaded', 'tsoimma_init' );

/**
 * Load bundled translations for the site locale (Plugins screen headers and admin UI).
 */
function tsoimma_load_textdomain() {
    load_plugin_textdomain(
        'tso-image-master',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
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

    if ( ! wp_next_scheduled( 'tsoimma_history_purge' ) ) {
        wp_schedule_event( time(), 'weekly', 'tsoimma_history_purge' );
    }
}
register_activation_hook( __FILE__, 'tsoimma_activate' );

/**
 * Deactivation: remove scheduled events (data is preserved).
 */
function tsoimma_deactivate() {
    wp_clear_scheduled_hook( 'tsoimma_history_purge' );
    wp_clear_scheduled_hook( 'tsoimma_process_thumbnails' );
}
register_deactivation_hook( __FILE__, 'tsoimma_deactivate' );

