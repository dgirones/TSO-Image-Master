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
        if ( strpos( $hook, 'tso-image-master' ) === false ) return;

        // wp_enqueue_style must come BEFORE wp_add_inline_style for the handle to exist.
        wp_enqueue_style(
            'tso-im-admin-css',
            TSOIMMA_PLUGIN_URL . 'admin/css/admin.css',
            [],
            TSOIMMA_VERSION
        );

        // Eliminate the white bars shown by WordPress admin around the plugin page.
        // These styles are only output on this specific admin page (hook check above),
        // so simple selectors without body-class prefix are safe and correct.
        // wp_add_inline_style() is used instead of echo '<style>' to comply with WP.org guidelines.
        $inline_css  = '#wpwrap,#wpcontent,#wpbody,#wpbody-content{background:#0f1117 !important;}';
        $inline_css .= '#wpcontent{padding-left:0 !important;}';
        $inline_css .= '#wpbody-content>.wrap,#wpbody-content>div.wrap{';
        $inline_css .= 'max-width:none !important;padding:0 !important;margin:0 !important;}';
        wp_add_inline_style( 'tso-im-admin-css', $inline_css );
        wp_enqueue_script(
            'tso-im-admin-js',
            TSOIMMA_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            TSOIMMA_VERSION,
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
                'scan_rogue'          => __( 'Scan problem files', 'tso-image-master' ),
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
                'no_rogue'            => __( 'No problem files found.', 'tso-image-master' ),
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
                'url_no_fix_label'    => __( 'No alternative found. Manual restore required.', 'tso-image-master' ),
                'url_outdated_badge'  => __( 'Obsolete thumbnail (file exists in new format)', 'tso-image-master' ),
                'url_missing_badge'   => __( 'Missing file - alternative found', 'tso-image-master' ),
                'url_broken_badge'    => __( 'Missing file - no automatic fix', 'tso-image-master' ),
                'url_fixing'          => __( 'Fixing...', 'tso-image-master' ),
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
                'all_rogue_deleted'   => __( 'All problem files deleted!', 'tso-image-master' ),
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
                            <select id="imp-format">
                                <option value="webp" data-i18n="fmt_webp">WebP (recomanat)</option>
                                <option value="jpg">JPG</option>
                                <option value="original" data-i18n="fmt_keep">Mantenir format original</option>
                            </select>
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
                            <select id="imp-orphan-limit">
                                <option value="100">100</option>
                                <option value="200" selected>200</option>
                                <option value="500">500</option>
                                <option value="0" data-i18n="all_slow">Totes (lent)</option>
                            </select>
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

                <!-- SECCIÓ: Fitxers físics problemàtics -->
                <div class="imp-panel" style="margin-top:24px;">
                    <h2 class="imp-panel-title" data-i18n="rogue_title">🗂 Fitxers Físics Problemàtics</h2>
                    <p class="imp-panel-desc" data-i18n-html="rogue_desc">Detecta fitxers al disc que <strong>no estan registrats a WordPress</strong> o que tenen patrons problemàtics: doble extensió (<code>.jpg.webp</code>), backups antics (<code>.bk</code>), temporals, etc. Aquests fitxers ocupen espai però WordPress no els coneix.</p>
                    <button id="imp-scan-rogue" class="imp-btn imp-btn-primary" data-i18n="scan_rogue">🔍 Escanejar fitxers problemàtics</button>
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
                        <select id="imp-seo-sort" class="imp-select" style="width:auto;min-width:180px;">
                            <option value="filesize" data-i18n="sort_size">📦 Ordenar per pes (major primer)</option>
                            <option value="date" data-i18n="sort_date">📅 Ordenar per data de creació</option>
                            <option value="modified" data-i18n="sort_modified">✏️ Ordenar per data de modificació</option>
                        </select>
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
                                            <select id="imp-modal-format" class="imp-select">
                                                <option value="webp" data-i18n="fmt_webp">WebP (recomanat)</option>
                                                <option value="jpg">JPG</option>
                                                <option value="original" data-i18n="fmt_keep_current">Mantenir format actual</option>
                                            </select>
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
                            <select id="imp-pdf-quality">
                                <option value="72" data-i18n="dpi_72">72 DPI — Molt lleuger (pantalla)</option>
                                <option value="96" selected data-i18n="dpi_96">96 DPI — Recomanat (web)</option>
                                <option value="150" data-i18n="dpi_150">150 DPI — Alta qualitat</option>
                                <option value="300" data-i18n="dpi_300">300 DPI — Impressió</option>
                            </select>
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
                            <select id="imp-auto-format">
                                <option value="webp" data-i18n="fmt_webp">WebP (recomanat)</option>
                                <option value="jpg">JPG</option>
                                <option value="original" data-i18n="fmt_keep">Mantenir format original</option>
                            </select>
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
                            <select id="imp-history-filter-type">
                                <option value="" data-i18n="all_actions">Totes les accions</option>
                                <option value="optimize" data-i18n="filter_optimize">⚡ Optimitzades</option>
                                <option value="auto_optimize" data-i18n="filter_auto">🤖 Auto-optimitzades</option>
                                <option value="rename" data-i18n="filter_rename">✏️ Reanomenades</option>
                                <option value="seo_update" data-i18n="filter_seo">🏷️ SEO actualitzat</option>
                                <option value="delete" data-i18n="filter_delete">🗑️ Eliminades</option>
                                <option value="pdf_compress" data-i18n="filter_pdf">📄 PDFs comprimits</option>
                            </select>
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
                        <button id="imp-history-clear-30" class="imp-btn imp-btn-ghost" data-i18n="clear_30">🗑️ Netejar >30 dies</button>
                        <button id="imp-history-clear-all" class="imp-btn imp-btn-danger" data-i18n="clear_all">🗑️ Netejar tot</button>
                    </div>
                    <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 16px;background:var(--imp-surface2);border-radius:8px;border:1px solid var(--imp-border);">
                        <span style="color:var(--imp-text-muted);font-size:13px;" data-i18n="auto_clean_label">🕐 Neteja automàtica (setmanal):</span>
                        <input type="number" id="imp-history-retention-days" min="0" max="3650" step="1" value="90"
                               style="width:80px;padding:4px 8px;border-radius:6px;border:1px solid var(--imp-border);background:var(--imp-surface);color:var(--imp-text);font-size:13px;"
                               title="Dies de retenció. 0 = desactivat.">
                        <span style="color:var(--imp-text-muted);font-size:13px;" data-i18n="days_hint">dies &nbsp;(0 = desactivat)</span>
                        <button id="imp-history-retention-save" class="imp-btn imp-btn-ghost" style="font-size:12px;padding:4px 12px;" data-i18n="save_btn">💾 Desar</button>
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
                            <button id="imp-url-select-all"   class="imp-btn imp-btn-ghost"data-i18n="select_fixable">Seleccionar reparables</button>
                            <button id="imp-url-deselect"     class="imp-btn imp-btn-ghost" data-i18n="deselect">Deseleccionar</button>
                        </div>
                        <div class="imp-toolbar-right">
                            <span id="imp-url-count" class="imp-count-badge"></span>
                            <button id="imp-fix-urls" class="imp-btn imp-btn-primary"data-i18n="fix_selected">✅ Reparar seleccionades</button>
                        </div>
                    </div>

                    <div id="imp-url-list"></div>
                </div>
            </div>

        </div><!-- /#imp-app -->
        <?php
    }
}
