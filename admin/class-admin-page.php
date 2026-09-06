<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSOIMMA_Admin_Page {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function register_menu() {
        add_menu_page(
            'TSO Image Master',
            'Image Master',
            'manage_options',
            'tso-image-master',
            [ __CLASS__, 'render_page' ],
            'dashicons-images-alt2',
            75
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'tso-image-master' ) === false ) {
            return;
        }

        $css_file = TSOIMMA_PLUGIN_DIR . 'admin/css/admin.css';
        $js_file  = TSOIMMA_PLUGIN_DIR . 'admin/js/admin.js';
        $css_ver  = TSOIMMA_VERSION . '.' . ( file_exists( $css_file ) ? (string) filemtime( $css_file ) : '0' );
        $js_ver   = TSOIMMA_VERSION . '.' . ( file_exists( $js_file ) ? (string) filemtime( $js_file ) : '0' );

        wp_enqueue_style(
            'tso-im-admin-css',
            TSOIMMA_PLUGIN_URL . 'admin/css/admin.css',
            [],
            $css_ver
        );

        $inline_css  = '#wpwrap,#wpcontent,#wpbody,#wpbody-content{background:#0f1117 !important;}';
        $inline_css .= '#wpcontent{padding-left:0 !important;}';
        $inline_css .= '#wpbody-content>.wrap,#wpbody-content>div.wrap{';
        $inline_css .= 'max-width:none !important;padding:0 !important;margin:0 !important;}';
        $inline_css .= self::custom_select_inline_css();
        wp_add_inline_style( 'tso-im-admin-css', $inline_css );

        wp_enqueue_script(
            'tso-im-admin-js',
            TSOIMMA_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            $js_ver,
            true
        );

        wp_localize_script( 'tso-im-admin-js', 'TSOIMMA', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'tso_im_nonce' ),
            'site_url' => get_site_url(),
            'webp_ok'  => TSOIMMA_Optimizer::webp_supported() ? '1' : '0',
            // All UI strings are passed here so admin.js has no inline i18n data.
            // This complies with WP.org guidelines (no JSON.parse blocks in JS).
            'strings'  => array(
                'confirm_delete'      => __( 'Delete selected images? This cannot be undone.', 'tso-image-master' ),
                'confirm_del_backup'  => __( 'Delete backup? You will not be able to revert.', 'tso-image-master' ),
                'confirm_revert'      => __( 'Revert to original? Optimized version will be lost.', 'tso-image-master' ),
                'confirm_delete_rogue'=> __( 'Delete', 'tso-image-master' ),
                'no_selection'        => __( 'Select at least one image.', 'tso-image-master' ),
                'processing'          => __( 'Processing...', 'tso-image-master' ),
                'save_ok'             => __( 'Saved!', 'tso-image-master' ),
                'save_seo'            => __( 'Save SEO', 'tso-image-master' ),
                'edit_image'          => __( 'Edit', 'tso-image-master' ),
                'scan_rogue'          => __( 'Scan extra upload files', 'tso-image-master' ),
                'delete_rogue'        => __( 'Delete selected', 'tso-image-master' ),
                'scan_web'            => __( 'Scan entire site', 'tso-image-master' ),
                'fix_selected'        => __( 'Fix selected', 'tso-image-master' ),
                'optimize_now'        => __( 'Optimize now', 'tso-image-master' ),
                'rename_btn'          => __( 'Rename file', 'tso-image-master' ),
                'revert_btn'          => __( 'Revert to original', 'tso-image-master' ),
                'del_backup'          => __( 'Delete backup', 'tso-image-master' ),
                'backup_available'    => __( 'Backup available', 'tso-image-master' ),
                'repair_paths'        => __( 'Repair broken paths', 'tso-image-master' ),
                'loading_images'      => __( 'Loading images...', 'tso-image-master' ),
                'loading_pdfs'        => __( 'Loading PDFs...', 'tso-image-master' ),
                'loading_data'        => __( 'Loading...', 'tso-image-master' ),
                'loading_modal'       => __( 'Loading...', 'tso-image-master' ),
                'scanning_msg'        => __( 'Scanning...', 'tso-image-master' ),
                'no_images'           => __( 'No images found.', 'tso-image-master' ),
                'no_orphans'          => __( 'No orphaned images found.', 'tso-image-master' ),
                'no_rogue'            => __( 'No extra files found.', 'tso-image-master' ),
                'no_pdfs'             => __( 'No PDFs found.', 'tso-image-master' ),
                'no_auto_history'     => __( 'No auto-optimization entries.', 'tso-image-master' ),
                'auto_hist_error'     => __( 'Error loading history.', 'tso-image-master' ),
                'no_history'          => __( 'No entries found.', 'tso-image-master' ),
                'history_empty'       => __( 'History is empty.', 'tso-image-master' ),
                'url_all_ok'          => __( 'No broken URLs found. All correct!', 'tso-image-master' ),
                'url_no_results'      => __( 'No results.', 'tso-image-master' ),
                'url_click_select'    => __( 'Click to select', 'tso-image-master' ),
                'url_content_label'   => __( 'URL in content (obsolete)', 'tso-image-master' ),
                'url_correct_label'   => __( 'Correct URL (file exists)', 'tso-image-master' ),
                'url_no_fix_label'    => __( 'No alternative found. Select and remove the reference from content, or restore the file manually.', 'tso-image-master' ),
                'url_outdated_badge'  => __( 'Obsolete thumbnail (file exists in new format)', 'tso-image-master' ),
                'url_missing_badge'   => __( 'Missing file - alternative found', 'tso-image-master' ),
                'url_broken_badge'    => __( 'Missing file - no automatic fix', 'tso-image-master' ),
                'url_fixing'          => __( 'Fixing...', 'tso-image-master' ),
                'select_removable'    => __( 'Select removable', 'tso-image-master' ),
                'removable_label'     => __( 'removable', 'tso-image-master' ),
                'remove_selected'     => __( 'Remove from content', 'tso-image-master' ),
                'url_removing'        => __( 'Removing...', 'tso-image-master' ),
                'url_removed_ok'      => __( 'URL references removed from content.', 'tso-image-master' ),
                'confirm_remove_urls' => __( 'Remove selected broken URL references from posts and widgets? The image tag or link will be deleted from content.', 'tso-image-master' ),
                'url_click_select_remove' => __( 'Click to select for removal', 'tso-image-master' ),
                'posts_scanned'       => __( 'Content items scanned', 'tso-image-master' ),
                'broken_urls'         => __( 'Broken URLs', 'tso-image-master' ),
                'fixable_urls'        => __( 'Auto-fixable', 'tso-image-master' ),
                'used_in'             => __( 'Used in', 'tso-image-master' ),
                'orphan_confirmed'    => __( 'Confirmed orphan: not referenced anywhere.', 'tso-image-master' ),
                'not_in_content'      => __( 'Not found in post_content.', 'tso-image-master' ),
                'optimized_ok'        => __( 'Optimized!', 'tso-image-master' ),
                'optimized_no_replace'=> __( 'Optimized (not replaced).', 'tso-image-master' ),
                'converted_bigger'    => __( 'Converted but larger than original.', 'tso-image-master' ),
                'optimizing_thumbs'   => __( 'Optimizing thumbnails...', 'tso-image-master' ),
                'thumbs_done'         => __( 'Thumbnails processed.', 'tso-image-master' ),
                'reverted_ok'         => __( 'Reverted!', 'tso-image-master' ),
                'n_selected'          => __( 'selected', 'tso-image-master' ),
                'optimize_done'       => __( 'saved', 'tso-image-master' ),
                'bulk_done'           => __( 'Done', 'tso-image-master' ),
                'bulk_processing'     => __( 'Processing', 'tso-image-master' ),
                'pdf_compress_btn'    => __( 'Compress', 'tso-image-master' ),
                'pdf_timeout_msg'     => __( 'GhostScript timed out. Check FTP - file may already be compressed. Reload the page.', 'tso-image-master' ),
                'gs_available'        => __( 'GhostScript available', 'tso-image-master' ),
                'gs_none'             => __( 'No compression engine available. Install GhostScript.', 'tso-image-master' ),
                'auto_enabled'        => __( 'Auto-optimization ENABLED', 'tso-image-master' ),
                'auto_disabled'       => __( 'Auto-optimization disabled', 'tso-image-master' ),
                'auto_desc_enabled'   => __( 'New images will be converted automatically on upload.', 'tso-image-master' ),
                'auto_desc_disabled'  => __( 'Enable to optimize automatically.', 'tso-image-master' ),
                'repaired_msg'        => __( 'images repaired. Reload SEO & Names to see them.', 'tso-image-master' ),
                'confirm_clean_30'    => __( 'Delete entries older than 30 days?', 'tso-image-master' ),
                'confirm_clean_all'   => __( 'Delete ALL history? This is irreversible.', 'tso-image-master' ),
                'btn_scanning'        => __( 'Scanning...', 'tso-image-master' ),
                'btn_processing'      => __( 'Processing...', 'tso-image-master' ),
                'btn_deleting'        => __( 'Deleting...', 'tso-image-master' ),
                'btn_reverting'       => __( 'Reverting...', 'tso-image-master' ),
                'btn_repairing'       => __( 'Repairing...', 'tso-image-master' ),
                'webp_ok'             => __( 'Supported', 'tso-image-master' ),
                'webp_nok'            => __( 'Not available (GD without WebP)', 'tso-image-master' ),
                'enter_name'          => __( 'Enter the new name.', 'tso-image-master' ),
                'stat_total'          => __( 'Total operations', 'tso-image-master' ),
                'stat_saved'          => __( 'Space freed', 'tso-image-master' ),
                'stat_current_size'   => __( 'Current size', 'tso-image-master' ),
                'stat_real_format'    => __( 'Real format', 'tso-image-master' ),
                'images_deleted'      => __( 'images deleted.', 'tso-image-master' ),
                'all_rogue_deleted'   => __( 'All selected extra files deleted!', 'tso-image-master' ),
                'featured'            => __( 'Featured image', 'tso-image-master' ),
                'no_alt_text'         => __( 'No alt text', 'tso-image-master' ),
                'url_fixed_ok'        => __( 'URLs fixed correctly.', 'tso-image-master' ),
                'mime_fix_btn'        => __( '🔧 Fix incorrect mime types', 'tso-image-master' ),
                'mime_fixed'          => __( 'attachments repaired.', 'tso-image-master' ),
                'mime_no_issues'      => __( 'No issues found. All attachments are correct.', 'tso-image-master' ),
                'scan_ghosts'         => __( 'Scan ghost attachments', 'tso-image-master' ),
                'no_ghosts'           => __( 'No ghost attachments found.', 'tso-image-master' ),
                'delete_ghosts'       => __( 'Delete selected', 'tso-image-master' ),
                'confirm_delete_ghosts' => __( 'Delete', 'tso-image-master' ),
            ),
        ) );
    }

    /**
     * Critical CSS for custom dropdowns (native OS menus stay unreadable on Windows).
     *
     * @return string
     */
    private static function custom_select_inline_css() {
        $accent = '#6c63ff';
        return '.imp-csel{position:relative;display:block;width:100%;}'
            . '.imp-csel-native{position:absolute;width:1px;height:1px;margin:-1px;padding:0;'
            . 'overflow:hidden;clip:rect(0,0,0,0);border:0;opacity:0;pointer-events:none;}'
            . '.imp-wrap button.imp-csel-trigger{background:#fff!important;color:#1a1d27!important;'
            . 'border:1px solid #9ca3af!important;}'
            . '.imp-wrap .imp-csel.is-open button.imp-csel-trigger,'
            . '.imp-wrap button.imp-csel-trigger:hover{border:2px solid ' . $accent . '!important;'
            . 'padding:8px 11px!important;background:#fff!important;}'
            . '.imp-wrap .imp-csel-label{color:#1a1d27!important;}'
            . '.imp-wrap .imp-csel-list{background:#fff!important;border:2px solid ' . $accent . '!important;}'
            . '.imp-wrap .imp-csel-list [role=option]{color:#1a1d27!important;background:#fff!important;}'
            . '.imp-wrap .imp-csel-list [role=option].is-selected{background:' . $accent . '!important;'
            . 'color:#fff!important;}';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function format_output_options( $keep_label, $keep_i18n ) {
        $webp_ok = TSOIMMA_Optimizer::webp_supported();
        return array(
            array(
                'value'    => 'webp',
                'label'    => 'WebP (recomanat)',
                'i18n'     => 'fmt_webp',
                'disabled' => ! $webp_ok,
            ),
            array(
                'value' => 'jpg',
                'label' => 'JPG',
            ),
            array(
                'value' => 'original',
                'label' => $keep_label,
                'i18n'  => $keep_i18n,
            ),
        );
    }

    /**
     * @param array $args id, class, wrap_style, selected, options[].
     */
    public static function render_custom_select( $args ) {
        $args = wp_parse_args(
            $args,
            array(
                'id'         => '',
                'class'      => '',
                'wrap_style' => '',
                'selected'   => null,
                'options'    => array(),
            )
        );

        $selected_label = '';
        foreach ( $args['options'] as $opt ) {
            $val         = isset( $opt['value'] ) ? (string) $opt['value'] : '';
            $is_selected = ! empty( $opt['selected'] );
            if ( null !== $args['selected'] ) {
                $is_selected = ( (string) $args['selected'] === $val );
            }
            if ( $is_selected ) {
                $selected_label = $opt['label'];
                break;
            }
        }
        if ( '' === $selected_label && ! empty( $args['options'] ) ) {
            $selected_label = $args['options'][0]['label'];
        }

        $wrap_class = 'imp-csel';
        if ( ! empty( $args['class'] ) ) {
            $wrap_class .= ' ' . $args['class'];
        }

        echo '<div class="' . esc_attr( $wrap_class ) . '"';
        if ( ! empty( $args['wrap_style'] ) ) {
            echo ' style="' . esc_attr( $args['wrap_style'] ) . '"';
        }
        echo '>';

        echo '<select id="' . esc_attr( $args['id'] ) . '" class="imp-csel-native">';
        foreach ( $args['options'] as $opt ) {
            $val         = isset( $opt['value'] ) ? (string) $opt['value'] : '';
            $is_selected = ! empty( $opt['selected'] );
            if ( null !== $args['selected'] ) {
                $is_selected = ( (string) $args['selected'] === $val );
            }
            echo '<option value="' . esc_attr( $val ) . '"';
            if ( $is_selected ) {
                echo ' selected';
            }
            if ( ! empty( $opt['disabled'] ) ) {
                echo ' disabled';
            }
            if ( ! empty( $opt['i18n'] ) ) {
                echo ' data-i18n="' . esc_attr( $opt['i18n'] ) . '"';
            }
            echo '>' . esc_html( $opt['label'] ) . '</option>';
        }
        echo '</select>';

        echo '<button type="button" class="imp-csel-trigger" aria-haspopup="listbox" aria-expanded="false">';
        echo '<span class="imp-csel-label">' . esc_html( $selected_label ) . '</span>';
        echo '<span class="imp-csel-chevron" aria-hidden="true">▾</span>';
        echo '</button>';

        echo '<ul class="imp-csel-list" role="listbox">';
        foreach ( $args['options'] as $opt ) {
            $val         = isset( $opt['value'] ) ? (string) $opt['value'] : '';
            $is_selected = ! empty( $opt['selected'] );
            if ( null !== $args['selected'] ) {
                $is_selected = ( (string) $args['selected'] === $val );
            }
            $li_class = 'imp-csel-option';
            if ( $is_selected ) {
                $li_class .= ' is-selected';
            }
            if ( ! empty( $opt['disabled'] ) ) {
                $li_class .= ' is-disabled';
            }
            echo '<li role="option" class="' . esc_attr( $li_class ) . '" data-value="' . esc_attr( $val ) . '" tabindex="-1">';
            echo esc_html( $opt['label'] );
            echo '</li>';
        }
        echo '</ul>';

        echo '</div>';
    }

    public static function render_page() {
        ?>
        <div id="imp-app" class="imp-wrap">

            <!-- HEADER -->
            <div class="imp-header">
                <div class="imp-header-brand">
                    <span class="imp-icon">⚡</span>
                    <h1>TSO Image Master</h1>
                    <span class="imp-badge">v<?php echo esc_html( TSOIMMA_VERSION ); ?></span>
                </div>
                <div class="imp-header-meta">
                    <span id="imp-webp-status"></span>
                    <a class="imp-donate-btn" href="https://ko-fi.com/deadko_cat" target="_blank" rel="noopener noreferrer">
                        <span data-i18n="donate_support">☕ Dona suport al plugin</span>
                    </a>
                    <div class="imp-lang-switcher" role="group" aria-label="Idioma">
                        <button class="imp-lang-btn active" data-lang="ca" title="Català">CA</button>
                        <button class="imp-lang-btn" data-lang="es" title="Español">ES</button>
                        <button class="imp-lang-btn" data-lang="en" title="English">EN</button>
                    </div>
                </div>
            </div>

            <!-- TABS -->
            <nav class="imp-tabs" role="tablist">
                <button class="imp-tab active" data-tab="optimize" role="tab">
                    <span>🔧</span> <span data-i18n="tab_optimize">Optimitzar</span>
                </button>
                <button class="imp-tab" data-tab="orphans" role="tab">
                    <span>🔍</span> <span data-i18n="tab_orphans">Imatges Òrfenes</span>
                </button>
                <button class="imp-tab" data-tab="seo" role="tab">
                    <span>✏️</span> <span data-i18n="tab_seo">SEO & Noms</span>
                </button>
                <button class="imp-tab" data-tab="pdf" role="tab">
                    <span>📄</span> <span data-i18n="tab_pdf">PDFs</span>
                </button>
                <button class="imp-tab" data-tab="auto" role="tab">
                    <span>🤖</span> <span data-i18n="tab_auto">Auto-optimització</span>
                </button>
                <button class="imp-tab" data-tab="history" role="tab">
                    <span>📋</span> <span data-i18n="tab_history">Historial</span>
                </button>
                <button class="imp-tab" data-tab="urlfixer" role="tab">
                    <span>🔗</span> <span data-i18n="tab_urls">URLs</span>
                </button>
            </nav>

            <!-- =====================================================
                 TAB: OPTIMITZAR
                 ===================================================== -->
            <div id="tab-optimize" class="imp-tab-content active">
                <div class="imp-panel">
                    <h2 class="imp-panel-title" data-i18n="opt_config_title">Configuració d'Optimització</h2>
                    <div class="imp-settings-grid">
                        <div class="imp-field">
                            <label for="imp-format" data-i18n="format_label">Format de sortida</label>
                            <?php self::render_custom_select( array( 'id' => 'imp-format', 'options' => self::format_output_options( 'Mantenir format original', 'fmt_keep' ) ) ); ?>
                        </div>
                        <div class="imp-field">
                            <label for="imp-quality"><span data-i18n="quality_label">Qualitat</span> <span id="imp-quality-val">82</span>%</label>
                            <input type="range" id="imp-quality" min="50" max="100" value="82">
                        </div>
                        <div class="imp-field imp-field-check">
                            <label>
                                <input type="checkbox" id="imp-replace" checked>
                                Reemplaça l'original i actualitza tots els links
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="imp-toolbar">
                    <div class="imp-toolbar-left">
                        <input type="text" id="imp-search-opt" class="imp-search" placeholder="🔎 Cercar imatge..." data-i18n-placeholder="search_image_ph">
                        <button id="imp-select-all" class="imp-btn imp-btn-ghost" data-i18n="select_all">Seleccionar tot</button>
                        <button id="imp-deselect-all" class="imp-btn imp-btn-ghost" data-i18n="deselect">Deseleccionar</button>
                    </div>
                    <div class="imp-toolbar-right">
                        <span id="imp-selected-count" class="imp-count-badge">0 seleccionades</span>
                        <button id="imp-bulk-optimize" class="imp-btn imp-btn-primary">
                            ⚡ <span data-i18n="bulk_optimize">Optimitzar seleccionades</span>
                        </button>
                    </div>
                </div>

                <!-- Paginació -->
                <div class="imp-pagination" id="imp-opt-pagination"></div>

                <!-- Grid d'imatges -->
                <div id="imp-images-grid" class="imp-images-grid">
                    <div class="imp-loading" data-i18n="loading_images">Carregant imatges...</div>
                </div>

                <!-- Barra de progrés bulk -->
                <div id="imp-bulk-progress" class="imp-bulk-progress" style="display:none;">
                    <div class="imp-progress-bar">
                        <div id="imp-progress-fill" class="imp-progress-fill"></div>
                    </div>
                    <p id="imp-progress-text"></p>
                </div>

                <!-- Log de resultats -->
                <div id="imp-opt-log" class="imp-log" style="display:none;">
                    <h3><span data-i18n="results_title">Resultats</span> <button id="imp-clear-log" class="imp-btn imp-btn-ghost imp-btn-sm" data-i18n="clear_btn">Netejar</button></h3>
                    <div id="imp-log-content"></div>
                </div>
            </div>

            <!-- =====================================================
                 TAB: IMATGES ÒRFENES
                 ===================================================== -->
            <div id="tab-orphans" class="imp-tab-content">
                <div class="imp-panel">
                    <h2 class="imp-panel-title" data-i18n="orphans_title">Trobar Imatges Òrfenes</h2>
                    <p class="imp-panel-desc" data-i18n-html="orphans_desc">Imatges de la biblioteca que <strong>no estan referenciades</strong> a cap article, pàgina, widget ni metadada.</p>
                    <div class="imp-settings-grid">
                        <div class="imp-field">
                            <label for="imp-orphan-limit" data-i18n="orphan_limit_label">Imatges a escanejar per lot</label>
                            <?php
                            self::render_custom_select(
                                array(
                                    'id'       => 'imp-orphan-limit',
                                    'selected' => '200',
                                    'options'  => array(
                                        array( 'value' => '100', 'label' => '100' ),
                                        array( 'value' => '200', 'label' => '200', 'selected' => true ),
                                        array( 'value' => '500', 'label' => '500' ),
                                        array( 'value' => '0', 'label' => 'Totes (lent)', 'i18n' => 'all_slow' ),
                                    ),
                                )
                            );
                            ?>
                        </div>
                    </div>
                    <button id="imp-scan-orphans" class="imp-btn imp-btn-primary" data-i18n="scan_now">🔍 Escanejar ara</button>
                </div>

                <div id="imp-orphans-result" style="display:none;">
                    <div class="imp-toolbar">
                        <div class="imp-toolbar-left">
                            <button id="imp-orphan-select-all" class="imp-btn imp-btn-ghost" data-i18n="select_all">Seleccionar tot</button>
                            <button id="imp-orphan-deselect" class="imp-btn imp-btn-ghost" data-i18n="deselect">Deseleccionar</button>
                        </div>
                        <div class="imp-toolbar-right">
                            <span id="imp-orphan-count" class="imp-count-badge"></span>
                            <button id="imp-delete-orphans" class="imp-btn imp-btn-danger">
                                🗑️ <span data-i18n="delete_selected">Eliminar seleccionades</span>
                            </button>
                        </div>
                    </div>
                    <div id="imp-orphans-grid" class="imp-images-grid"></div>
                </div>

                <div id="imp-orphans-loading" class="imp-loading-overlay" style="display:none;">
                    <div class="imp-spinner"></div>
                    <p id="imp-orphans-progress-text" data-i18n="scanning_msg">Escanejant...</p>
                </div>

                <!-- SECCIÓ: Fitxers extra a uploads -->
                <div class="imp-panel" style="margin-top:24px;">
                    <h2 class="imp-panel-title" data-i18n="rogue_title">🗂 Fitxers extra a uploads</h2>
                    <p class="imp-panel-desc" data-i18n-html="rogue_desc">Escaneja fitxers a <code>uploads/</code> que WordPress no té registrats: <strong>còpies de seguretat TSO</strong> (<code>_tso_im_backup</code>), temporals (<code>_tso_im_opt</code>), doble extensió (<code>.jpg.webp</code>), backups antics (<code>.bk</code>), etc. Revisa abans d'eliminar les còpies de seguretat.</p>
                    <button id="imp-scan-rogue" class="imp-btn imp-btn-primary" data-i18n="scan_rogue">🔍 Escanejar fitxers extra</button>
                </div>

                <div id="imp-rogue-result" style="display:none;">
                    <div class="imp-toolbar">
                        <div class="imp-toolbar-left">
                            <span id="imp-rogue-summary" class="imp-text-muted"></span>
                        </div>
                        <div class="imp-toolbar-right">
                            <button id="imp-rogue-select-all" class="imp-btn imp-btn-ghost" data-i18n="select_all">Seleccionar tot</button>
                            <button id="imp-rogue-deselect"   class="imp-btn imp-btn-ghost" data-i18n="deselect">Deseleccionar</button>
                            <button id="imp-delete-rogue"     class="imp-btn imp-btn-danger" data-i18n="delete_rogue">🗑️ Eliminar seleccionats</button>
                        </div>
                    </div>
                    <div id="imp-rogue-grid" class="imp-rogue-grid"></div>
                </div>

                <div id="imp-rogue-loading" class="imp-loading-overlay" style="display:none;">
                    <div class="imp-spinner"></div>
                    <p data-i18n="scanning_server_files">Escanejant fitxers del servidor...</p>
                </div>
            </div>

            <!-- =====================================================
                 TAB: SEO & NOMS
                 ===================================================== -->
            <div id="tab-seo" class="imp-tab-content">
                <div class="imp-toolbar">
                    <div class="imp-toolbar-left">
                        <input type="text" id="imp-search-seo" class="imp-search" placeholder="🔎 Cercar imatge..." data-i18n-placeholder="search_image_ph">
                        <?php
                        self::render_custom_select(
                            array(
                                'id'         => 'imp-seo-sort',
                                'class'      => 'imp-select',
                                'wrap_style' => 'width:auto;min-width:180px;',
                                'selected'   => 'filesize',
                                'options'    => array(
                                    array( 'value' => 'filesize', 'label' => '📦 Ordenar per pes (major primer)', 'i18n' => 'sort_size' ),
                                    array( 'value' => 'date', 'label' => '📅 Ordenar per data de creació', 'i18n' => 'sort_date' ),
                                    array( 'value' => 'modified', 'label' => '✏️ Ordenar per data de modificació', 'i18n' => 'sort_modified' ),
                                ),
                            )
                        );
                        ?>
                    </div>
                    <div class="imp-toolbar-right">
                        <span id="imp-seo-count" class="imp-count-badge"></span>
                    </div>
                </div>

                <div class="imp-pagination" id="imp-seo-pagination"></div>
                <div id="imp-seo-grid" class="imp-images-grid">
                    <div class="imp-loading" data-i18n="loading_images">Carregant imatges...</div>
                </div>
            </div>

            <!-- =====================================================
                 MODAL: Editor imatge
                 ===================================================== -->
            <div id="imp-modal" class="imp-modal" style="display:none;" role="dialog" aria-modal="true">
                <div class="imp-modal-overlay"></div>
                <div class="imp-modal-box">
                    <button class="imp-modal-close" aria-label="Tancar">✕</button>
                    <div class="imp-modal-body">
                        <div class="imp-modal-preview">
                            <img id="imp-modal-img" src="" alt="">
                            <div id="imp-modal-fileinfo"></div>
                        </div>
                        <div class="imp-modal-form">
                            <h3 id="imp-modal-title-head" data-i18n="edit_image_title">Editar imatge</h3>

                            <div class="imp-modal-tabs">
                                <button class="imp-mtab active" data-mtab="seo"><span data-i18n="modal_seo">SEO</span></button>
                                <button class="imp-mtab" data-mtab="rename"><span data-i18n="modal_rename">Reanomenar</span></button>
                                <button class="imp-mtab" data-mtab="optimize"><span data-i18n="modal_optimize">Optimitzar</span></button>
                            </div>

                            <!-- Modal: SEO -->
                            <div id="mtab-seo" class="imp-mtab-content active">
                                <input type="hidden" id="imp-modal-id">
                                <div class="imp-field">
                                    <label data-i18n="seo_title_label">Títol</label>
                                    <input type="text" id="imp-seo-title" placeholder="Títol descriptiu de la imatge" data-i18n-placeholder="seo_title_ph">
                                </div>
                                <div class="imp-field">
                                    <label data-i18n="seo_alt_label">Text alternatiu (Alt)</label>
                                    <input type="text" id="imp-seo-alt" placeholder="Descripció per a accessibilitat i SEO" data-i18n-placeholder="seo_alt_ph">
                                </div>
                                <div class="imp-field">
                                    <label data-i18n="seo_caption_label">Peu de foto (Caption)</label>
                                    <input type="text" id="imp-seo-caption" placeholder="Text visible sota la imatge" data-i18n-placeholder="seo_caption_ph">
                                </div>
                                <div class="imp-field">
                                    <label data-i18n="seo_desc_label">Descripció</label>
                                    <textarea id="imp-seo-description" rows="3" placeholder="Descripció llarga..." data-i18n-placeholder="seo_desc_ph"></textarea>
                                </div>
                                <button id="imp-save-seo" class="imp-btn imp-btn-primary"data-i18n="save_seo">💾 Guardar SEO</button>
                            </div>

                            <!-- Modal: Reanomenar -->
                            <div id="mtab-rename" class="imp-mtab-content">
                                <div class="imp-field">
                                    <label data-i18n="current_filename">Nom actual del fitxer</label>
                                    <input type="text" id="imp-current-filename" readonly class="imp-readonly">
                                </div>
                                <div class="imp-field">
                                    <label data-i18n="new_filename">Nou nom (sense extensió)</label>
                                    <input type="text" id="imp-new-filename" placeholder="p.ex: pastis-de-xocolata-recepta" data-i18n-placeholder="filename_ph">
                                    <span class="imp-hint" data-i18n="rename_hint">Es manté el text UTF-8 (p. ex. català) i només es netegen caràcters invàlids.</span>
                                </div>
                                <div class="imp-field">
                                    <label data-i18n="auto_suggest">Suggeriment automàtic</label>
                                    <div class="imp-suggest-row">
                                        <code id="imp-suggested-name"></code>
                                        <button id="imp-use-suggested" class="imp-btn imp-btn-ghost imp-btn-sm"data-i18n="use_btn">Usar</button>
                                    </div>
                                </div>
                                <button id="imp-save-rename" class="imp-btn imp-btn-primary"data-i18n="rename_btn">✏️ Reanomenar fitxer</button>
                            </div>

                            <!-- Modal: Optimitzar individual -->
                            <div id="mtab-optimize" class="imp-mtab-content">
                                <div class="imp-image-stats" id="imp-modal-stats"></div>

                                <!-- Secció: Format i qualitat -->
                                <div class="imp-opt-section">
                                    <div class="imp-opt-row">
                                        <div class="imp-opt-field">
                                            <label class="imp-opt-label" data-i18n="format_label">Format de sortida</label>
                                            <?php self::render_custom_select( array( 'id' => 'imp-modal-format', 'class' => 'imp-select', 'options' => self::format_output_options( 'Mantenir format actual', 'fmt_keep_current' ) ) ); ?>
                                        </div>
                                        <div class="imp-opt-field">
                                            <label class="imp-opt-label"><span data-i18n="quality_label">Qualitat</span>: <strong id="imp-modal-quality-val">82</strong>%</label>
                                            <input type="range" id="imp-modal-quality" min="50" max="100" value="82" class="imp-range">
                                        </div>
                                    </div>
                                </div>

                                <!-- Secció: Redimensionar -->
                                <div class="imp-opt-section">
                                    <div class="imp-opt-section-header" id="imp-resize-toggle">
                                        <span class="imp-opt-section-icon">📐</span>
                                        <div>
                                            <strong data-i18n="resize_title">Redimensionar imatge</strong>
                                            <small data-i18n="resize_desc">Opcional — redueix les mides en píxels</small>
                                        </div>
                                        <div class="imp-opt-toggle-wrap">
                                            <label class="imp-toggle-switch">
                                                <input type="checkbox" id="imp-modal-resize">
                                                <span class="imp-toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div id="imp-resize-options" style="display:none; padding-top:14px;">
                                        <div class="imp-resize-presets">
                                            <button class="imp-preset-btn" data-w="1920" data-h="0"><span data-i18n="preset_fhd">Full HD</span><small>1920px</small></button>
                                            <button class="imp-preset-btn" data-w="1280" data-h="0"><span data-i18n="preset_hd">HD</span><small>1280px</small></button>
                                            <button class="imp-preset-btn" data-w="1024" data-h="0"><span data-i18n="preset_web">Web</span><small>1024px</small></button>
                                            <button class="imp-preset-btn" data-w="800"  data-h="0"><span data-i18n="preset_medium">Mitjà</span><small>800px</small></button>
                                            <button class="imp-preset-btn" data-w="600"  data-h="0"><span data-i18n="preset_small">Petit</span><small>600px</small></button>
                                        </div>
                                        <div class="imp-resize-row">
                                            <div class="imp-resize-field">
                                                <label data-i18n="max_width">Ample màx</label>
                                                <div class="imp-resize-input-wrap">
                                                    <input type="number" id="imp-modal-width" min="50" max="8000" placeholder="px">
                                                </div>
                                            </div>
                                            <div class="imp-resize-sep">🔒</div>
                                            <div class="imp-resize-field">
                                                <label data-i18n="max_height">Alt màx</label>
                                                <div class="imp-resize-input-wrap">
                                                    <input type="number" id="imp-modal-height" min="50" max="8000" placeholder="px">
                                                </div>
                                            </div>
                                        </div>
                                        <p class="imp-hint" data-i18n="proportions_hint">Proporcions preservades. Deixa un camp buit per calcular-lo automàticament.</p>
                                    </div>
                                </div>

                                <!-- Secció: Opcions finals -->
                                <div class="imp-opt-section">
                                    <label class="imp-opt-checkbox-row">
                                        <input type="checkbox" id="imp-modal-replace" checked>
                                        <div>
                                            <strong data-i18n="replace_title">Reemplaçar l'original</strong>
                                            <small data-i18n="replace_desc">Actualitza automàticament tots els links a la web</small>
                                        </div>
                                    </label>
                                </div>

                                <button id="imp-optimize-single" class="imp-btn imp-btn-primary imp-btn-full"data-i18n="optimize_now">⚡ Optimitzar ara</button>
                                <div id="imp-modal-result" class="imp-result-box" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: PDF preview -->
            <div id="imp-pdf-preview-modal" class="imp-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="imp-pdf-preview-title">
                <div class="imp-modal-overlay"></div>
                <div class="imp-modal-box imp-pdf-preview-box">
                    <button type="button" class="imp-modal-close imp-pdf-preview-close" aria-label="Tancar">✕</button>
                    <div class="imp-pdf-preview-header">
                        <h3 id="imp-pdf-preview-title" data-i18n="pdf_preview_title">Previsualització PDF</h3>
                        <a id="imp-pdf-preview-open" class="imp-btn imp-btn-ghost imp-btn-sm" href="#" target="_blank" rel="noopener noreferrer" data-i18n="pdf_open_tab">Obrir en pestanya nova</a>
                    </div>
                    <iframe id="imp-pdf-preview-frame" class="imp-pdf-preview-frame" title="PDF preview"></iframe>
                    <p class="imp-pdf-preview-fallback" data-i18n="pdf_preview_fallback">Si la previsualització no es carrega al navegador, obre el PDF en una pestanya nova.</p>
                </div>
            </div>

            <!-- =====================================================
                 TAB: PDFs
                 ===================================================== -->
            <div id="tab-pdf" class="imp-tab-content">
                <div class="imp-panel">
                    <h2 class="imp-panel-title" data-i18n="pdf_title">Comprimir PDFs</h2>
                    <p class="imp-panel-desc" data-i18n-html="pdf_desc">Redueix el pes dels PDFs de la biblioteca sense canviar l'URL ni trencar cap enllaç.<br><strong>Requereix:</strong> GhostScript instal·lat al servidor (recomanat) o extensió Imagick de PHP.</p>
                    <div class="imp-settings-grid">
                        <div class="imp-field">
                            <label for="imp-pdf-quality" data-i18n="pdf_quality_label">Qualitat / DPI</label>
                            <?php
                            self::render_custom_select(
                                array(
                                    'id'       => 'imp-pdf-quality',
                                    'selected' => '96',
                                    'options'  => array(
                                        array( 'value' => '72', 'label' => '72 DPI — Molt lleuger (pantalla)', 'i18n' => 'dpi_72' ),
                                        array( 'value' => '96', 'label' => '96 DPI — Recomanat (web)', 'i18n' => 'dpi_96' ),
                                        array( 'value' => '150', 'label' => '150 DPI — Alta qualitat', 'i18n' => 'dpi_150' ),
                                        array( 'value' => '300', 'label' => '300 DPI — Impressió', 'i18n' => 'dpi_300' ),
                                    ),
                                )
                            );
                            ?>
                        </div>
                        <div class="imp-field imp-field-check">
                            <label>
                                <input type="checkbox" id="imp-pdf-replace" checked>
                                <span data-i18n="pdf_replace_label">Reemplaça l'original (l'URL no canvia)</span>
                            </label>
                        </div>
                    </div>
                    <div id="imp-pdf-engine-status" style="margin-top:12px;font-size:13px;"></div>
                </div>
                <div class="imp-toolbar">
                    <div class="imp-toolbar-left">
                        <input type="text" id="imp-search-pdf" class="imp-search" placeholder="🔎 Cercar PDF..." data-i18n-placeholder="search_pdf_ph">
                        <button id="imp-pdf-select-all" class="imp-btn imp-btn-ghost" data-i18n="select_all">Seleccionar tot</button>
                        <button id="imp-pdf-deselect" class="imp-btn imp-btn-ghost" data-i18n="deselect">Deseleccionar</button>
                    </div>
                    <div class="imp-toolbar-right">
                        <span id="imp-pdf-count" class="imp-count-badge">0 seleccionats</span>
                        <button id="imp-bulk-compress-pdf" class="imp-btn imp-btn-primary">📄 <span data-i18n="compress_selected">Comprimir seleccionats</span></button>
                    </div>
                </div>
                <div id="imp-pdf-grid" class="imp-pdf-list">
                    <div class="imp-loading" data-i18n="loading_pdfs">Carregant PDFs...</div>
                </div>
                <div id="imp-pdf-bulk-progress" class="imp-bulk-progress" style="display:none;">
                    <div class="imp-progress-bar"><div id="imp-pdf-progress-fill" class="imp-progress-fill"></div></div>
                    <p id="imp-pdf-progress-text"></p>
                </div>
            </div>

            <!-- =====================================================
                 TAB: AUTO-OPTIMITZACIÓ
                 ===================================================== -->
            <div id="tab-auto" class="imp-tab-content">
                <div class="imp-panel">
                    <h2 class="imp-panel-title" data-i18n="auto_title">Auto-optimització en pujar imatges</h2>
                    <p class="imp-panel-desc" data-i18n-html="auto_desc">Quan activis aquesta opció, <strong>cada imatge nova que pugis</strong> s'optimitzarà automàticament amb la configuració triada. No caldrà fer res manualment.</p>

                    <div class="imp-auto-toggle-row">
                        <label class="imp-toggle-switch">
                            <input type="checkbox" id="imp-auto-enabled">
                            <span class="imp-toggle-slider"></span>
                        </label>
                        <div>
                            <strong id="imp-auto-status-label" data-i18n="auto_disabled">Auto-optimització desactivada</strong>
                            <p id="imp-auto-status-desc" style="font-size:13px;color:var(--imp-text-muted);margin-top:2px;" data-i18n="auto_desc_disabled">Activa per optimitzar automàticament.</p>
                        </div>
                    </div>

                    <div class="imp-settings-grid" style="margin-top:20px;">
                        <div class="imp-field">
                            <label for="imp-auto-format" data-i18n="format_label">Format de sortida</label>
                            <?php self::render_custom_select( array( 'id' => 'imp-auto-format', 'options' => self::format_output_options( 'Mantenir format original', 'fmt_keep' ) ) ); ?>
                        </div>
                        <div class="imp-field">
                            <label for="imp-auto-quality"><span data-i18n="quality_label">Qualitat</span> <span id="imp-auto-quality-val">82</span>%</label>
                            <input type="range" id="imp-auto-quality" min="50" max="100" value="82">
                        </div>
                        <div class="imp-field" style="min-width:100%;">
                            <label data-i18n="auto_source_formats_label">Formats d'origen per auto-convertir</label>
                            <div class="imp-auto-src-grid">
                                <label class="imp-auto-src-item"><input type="checkbox" class="imp-auto-src-format" value="jpg" checked> <span data-i18n="auto_src_jpg">JPG/JPEG</span></label>
                                <label class="imp-auto-src-item"><input type="checkbox" class="imp-auto-src-format" value="png" checked> <span data-i18n="auto_src_png">PNG</span></label>
                                <label class="imp-auto-src-item"><input type="checkbox" class="imp-auto-src-format" value="webp" checked> <span data-i18n="auto_src_webp">WEBP</span></label>
                                <label class="imp-auto-src-item"><input type="checkbox" class="imp-auto-src-format" value="gif" checked> <span data-i18n="auto_src_gif">GIF (només estàtic)</span></label>
                                <label class="imp-auto-src-item"><input type="checkbox" class="imp-auto-src-format" value="bmp" checked> <span data-i18n="auto_src_bmp">BMP</span></label>
                                <label class="imp-auto-src-item"><input type="checkbox" class="imp-auto-src-format" value="tiff" checked> <span data-i18n="auto_src_tiff">TIFF</span></label>
                            </div>
                        </div>
                    </div>
                    <button id="imp-save-auto" class="imp-btn imp-btn-primary" style="margin-top:20px;" data-i18n="save_config">💾 Guardar configuració</button>
                    <span id="imp-auto-saved" style="display:none;margin-left:12px;color:var(--imp-success);font-size:13px;">✅ Guardat!</span>
                    <div style="margin-top:16px;padding:12px;background:var(--imp-surface2);border:1px solid var(--imp-border);border-radius:var(--imp-radius-sm);">
                        <p style="font-size:12px;color:var(--imp-text-muted);margin-bottom:8px;" data-i18n-html="repair_images_desc">🔧 <strong style="color:var(--imp-warn);">Reparació d'imatges</strong> — Si tens imatges auto-optimitzades a WebP que no apareixen a SEO &amp; Noms ni a Optimitzar, aquest botó repara el path a la base de dades.</p>
                        <button id="imp-fix-orphan-meta" class="imp-btn imp-btn-ghost" data-i18n="repair_paths">🔧 Reparar imatges amb path trencat</button>
                        <span id="imp-fix-orphan-result" style="display:none;margin-left:10px;font-size:13px;"></span>
                    </div>
                    <div style="margin-top:10px;padding:10px 14px;background:var(--imp-surface2);border:1px solid var(--imp-border);border-radius:var(--imp-radius-sm);">
                        <p style="font-size:12px;color:var(--imp-text-muted);margin-bottom:8px;">🔧 <strong style="color:var(--imp-warn);" data-i18n="mime_fix_title">Reparació de mime type</strong> — <span data-i18n="mime_fix_desc">Detecta i repara adjunts amb extensió .webp però mime type incorrecte (image/jpeg). Soluciona imatges invisibles a la biblioteca de medis.</span></p>
                        <button id="imp-fix-mime-mismatch" class="imp-btn imp-btn-ghost" data-i18n="mime_fix_btn">🔧 Reparar mime types incorrectes</button>
                        <span id="imp-fix-mime-result" style="display:none;margin-left:10px;font-size:13px;"></span>
                    </div>
                    <div style="margin-top:10px;padding:10px 14px;background:var(--imp-surface2);border:1px solid var(--imp-border);border-radius:var(--imp-radius-sm);">
                        <p style="font-size:12px;color:var(--imp-text-muted);margin-bottom:8px;" data-i18n-html="ghost_delete_desc">🗑️ <strong style="color:var(--imp-danger);">Eliminar adjunts fantasma</strong> — Detecta registres a la BD que apunten a fitxers que <strong>no existeixen físicament</strong> al disc (per exemple: apareix "gastos.webp" a la biblioteca però el fitxer no hi és). Els elimina completament: post, metadata i thumbnails de BD. <em>No toca cap fitxer físic.</em></p>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <button id="imp-scan-ghosts" class="imp-btn imp-btn-ghost" data-i18n="scan_ghosts">🔍 Escanejar adjunts fantasma</button>
                            <span id="imp-ghost-scan-result" style="display:none;font-size:13px;"></span>
                        </div>
                        <div id="imp-ghost-list" style="display:none;margin-top:12px;"></div>
                        <div id="imp-ghost-actions" style="display:none;margin-top:10px;display:none;gap:10px;align-items:center;flex-wrap:wrap;">
                            <button id="imp-ghost-select-all" class="imp-btn imp-btn-ghost imp-btn-sm" data-i18n="select_all">Seleccionar tots</button>
                            <button id="imp-ghost-deselect" class="imp-btn imp-btn-ghost imp-btn-sm">Deseleccionar</button>
                            <button id="imp-delete-ghosts" class="imp-btn imp-btn-danger imp-btn-sm">🗑️ <span data-i18n="delete_selected">Eliminar seleccionats</span></button>
                            <span id="imp-ghost-delete-result" style="display:none;font-size:13px;margin-left:8px;"></span>
                        </div>
                    </div>
                </div>

                <div class="imp-panel">
                    <h2 class="imp-panel-title" data-i18n="auto_stats_title">Estadístiques d'auto-optimització</h2>
                    <div id="imp-auto-stats" class="imp-stats-grid">
                        <div class="imp-loading" data-i18n="loading_stats">Carregant estadístiques...</div>
                    </div>
                </div>

                <div class="imp-panel">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
                        <h2 class="imp-panel-title" style="margin-bottom:0;" data-i18n="auto_history_title">Historial d'auto-optimització</h2>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button id="imp-auto-history-clear-30" class="imp-btn imp-btn-ghost" data-i18n="clear_30">🗑️ Netejar &gt;30 dies</button>
                            <button id="imp-auto-history-clear-all" class="imp-btn imp-btn-danger" data-i18n="clear_all">🗑️ Netejar tot</button>
                        </div>
                    </div>
                    <div id="imp-auto-history-wrap">
                        <div class="imp-loading" data-i18n="loading_data">Carregant...</div>
                    </div>
                    <div class="imp-pagination" id="imp-auto-history-pagination"></div>
                </div>
            </div>

            <!-- =====================================================
                 TAB: HISTORIAL
                 ===================================================== -->
            <div id="tab-history" class="imp-tab-content">
                <div class="imp-panel">
                    <h2 class="imp-panel-title" data-i18n="history_title">Historial de canvis</h2>
                    <div class="imp-settings-grid">
                        <div class="imp-field">
                            <label data-i18n="history_filter_label">Filtrar per acció</label>
                            <?php
                            self::render_custom_select(
                                array(
                                    'id'       => 'imp-history-filter-type',
                                    'selected' => '',
                                    'options'  => array(
                                        array( 'value' => '', 'label' => 'Totes les accions', 'i18n' => 'all_actions' ),
                                        array( 'value' => 'optimize', 'label' => '⚡ Optimitzades', 'i18n' => 'filter_optimize' ),
                                        array( 'value' => 'auto_optimize', 'label' => '🤖 Auto-optimitzades', 'i18n' => 'filter_auto' ),
                                        array( 'value' => 'rename', 'label' => '✏️ Reanomenades', 'i18n' => 'filter_rename' ),
                                        array( 'value' => 'seo_update', 'label' => '🏷️ SEO actualitzat', 'i18n' => 'filter_seo' ),
                                        array( 'value' => 'delete', 'label' => '🗑️ Eliminades', 'i18n' => 'filter_delete' ),
                                        array( 'value' => 'pdf_compress', 'label' => '📄 PDFs comprimits', 'i18n' => 'filter_pdf' ),
                                    ),
                                )
                            );
                            ?>
                        </div>
                        <div class="imp-field">
                            <label data-i18n="date_from">Des de</label>
                            <input type="date" id="imp-history-date-from">
                        </div>
                        <div class="imp-field">
                            <label data-i18n="date_to">Fins a</label>
                            <input type="date" id="imp-history-date-to">
                        </div>
                        <div class="imp-field">
                            <label data-i18n="search_file">Cercar fitxer</label>
                            <input type="text" id="imp-history-search" placeholder="nom del fitxer..." data-i18n-placeholder="search_file_ph">
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button id="imp-history-load" class="imp-btn imp-btn-primary"data-i18n="history_load">🔄 Carregar / filtrar</button>
                        <button type="button" id="imp-history-clear-dates" class="imp-btn imp-btn-ghost" data-i18n="history_clear_dates">📅 Totes les dates</button>
                        <button id="imp-history-clear-30" class="imp-btn imp-btn-ghost" data-i18n="clear_30">🗑️ Netejar >30 dies</button>
                        <button id="imp-history-clear-all" class="imp-btn imp-btn-danger" data-i18n="clear_all">🗑️ Netejar tot</button>
                    </div>
                    <div style="margin-top:14px;padding:12px 16px;background:var(--imp-surface2);border-radius:8px;border:1px solid var(--imp-border);">
                        <div style="color:var(--imp-text-muted);font-size:13px;margin-bottom:12px;font-weight:600;" data-i18n="auto_clean_title">🕐 Neteja automàtica de l'historial</div>
                        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <span style="color:var(--imp-text-muted);font-size:13px;" data-i18n="retention_days_label">Conservar:</span>
                                <input type="number" id="imp-history-retention-days" min="0" max="3650" step="1" value="90"
                                       style="width:80px;padding:4px 8px;border-radius:6px;border:1px solid var(--imp-border);background:var(--imp-surface);color:var(--imp-text);font-size:13px;">
                                <span style="color:var(--imp-text-muted);font-size:13px;" data-i18n="retention_days_unit">dies</span>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <span style="color:var(--imp-text-muted);font-size:13px;" data-i18n="purge_interval_label">Comprovació:</span>
                                <?php
                                self::render_custom_select(
                                    array(
                                        'id'         => 'imp-history-purge-interval',
                                        'wrap_style' => 'min-width:170px;width:auto;',
                                        'selected'   => 'weekly',
                                        'options'    => array(
                                            array( 'value' => 'daily', 'label' => 'Cada dia', 'i18n' => 'interval_daily' ),
                                            array( 'value' => 'weekly', 'label' => 'Cada setmana', 'i18n' => 'interval_weekly' ),
                                            array( 'value' => 'monthly', 'label' => 'Cada mes', 'i18n' => 'interval_monthly' ),
                                        ),
                                    )
                                );
                                ?>
                            </div>
                            <span style="color:var(--imp-text-muted);font-size:12px;" data-i18n="retention_zero_hint">0 dies = desactivat</span>
                            <button id="imp-history-retention-save" class="imp-btn imp-btn-ghost" style="font-size:12px;padding:4px 12px;" data-i18n="save_btn">💾 Desar</button>
                            <span id="imp-retention-saved" style="display:none;color:var(--imp-success);font-size:12px;" data-i18n="retention_saved">Desat!</span>
                            <span id="imp-retention-error" style="display:none;color:#f87171;font-size:12px;"></span>
                        </div>
                    </div>
                </div>

                <!-- Estadístiques globals -->
                <div id="imp-history-stats" class="imp-stats-grid" style="margin-bottom:20px;"></div>

                <!-- Taula d'entrades -->
                <div id="imp-history-table-wrap">
                    <div class="imp-loading" data-i18n="history_prompt_load">Carrega el historial prement "Carregar".</div>
                </div>
                <div class="imp-pagination" id="imp-history-pagination"></div>
            </div>

            <!-- =====================================================
                 TAB: URLs INCONSISTENTS
                 ===================================================== -->
            <div id="tab-urlfixer" class="imp-tab-content">
                <div class="imp-panel">
                    <h2 class="imp-panel-title" data-i18n="url_title">🔗 Detector d'URLs Inconsistents</h2>
                    <p class="imp-panel-desc" data-i18n-html="url_desc">Detecta URLs d'imatges als articles i pàgines que <strong>apunten a fitxers que ja no existeixen</strong> al servidor — habitualment perquè van ser convertits de JPG a WebP. El plugin troba l'alternativa correcta i pot actualitzar els links automàticament a tota la base de dades.</p>
                    <div class="imp-urlfixer-examples">
                        <div class="imp-urlfixer-ex">
                            <span class="imp-urlfixer-ex-bad">imatge-1024x340<strong>.jpg</strong></span>
                            <span class="imp-urlfixer-ex-arrow">→</span>
                            <span class="imp-urlfixer-ex-good">imatge-1024x340<strong>.webp</strong></span>
                            <span class="imp-urlfixer-ex-label" data-i18n="url_obsolete">URL obsoleta → nova correcta</span>
                        </div>
                    </div>
                    <button id="imp-scan-urls" class="imp-btn imp-btn-primary"data-i18n="scan_web">🔍 Escanejar tota la web</button>
                </div>

                <div id="imp-url-loading" class="imp-loading-overlay" style="display:none;">
                    <div class="imp-spinner"></div>
                    <p data-i18n="scanning_posts_pages">Escanejant continguts... pot trigar uns segons.</p>
                </div>

                <div id="imp-url-result" style="display:none;">
                    <!-- Resum -->
                    <div id="imp-url-summary" class="imp-stats-grid" style="margin-bottom:16px;"></div>

                    <!-- Toolbar -->
                    <div class="imp-toolbar" id="imp-url-toolbar" style="display:none;">
                        <div class="imp-toolbar-left">
                            <button id="imp-url-select-all"      class="imp-btn imp-btn-ghost" data-i18n="select_fixable">Seleccionar reparables</button>
                            <button id="imp-url-select-removable" class="imp-btn imp-btn-ghost" data-i18n="select_removable">Seleccionar eliminables</button>
                            <button id="imp-url-deselect"        class="imp-btn imp-btn-ghost" data-i18n="deselect">Deseleccionar</button>
                        </div>
                        <div class="imp-toolbar-right">
                            <span id="imp-url-count" class="imp-count-badge"></span>
                            <button id="imp-remove-urls" class="imp-btn imp-btn-danger" data-i18n="remove_selected">🗑 Eliminar del contingut</button>
                            <button id="imp-fix-urls"    class="imp-btn imp-btn-primary" data-i18n="fix_selected">✅ Reparar seleccionades</button>
                        </div>
                    </div>

                    <div id="imp-url-list"></div>
                </div>
            </div>

        </div><!-- /#imp-app -->
        <?php
    }
}
