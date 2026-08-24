/* global TSOIMMA, jQuery */
(function($) {
    'use strict';

    // All i18n strings come from TSOIMMA.strings (passed by wp_localize_script in PHP).
    // This replaces the previous JSON.parse approach to comply with WP.org guidelines.
    var L = TSOIMMA.strings || {};
    var BASE_L = $.extend({}, L);
    var CA_ORIGINAL = {
        text: {},
        placeholder: {},
        html: {}
    };

    // ================================================================
    // State
    // ================================================================
    var state = {
        opt: {
            page: 1, perPage: 35, search: '', totalPages: 1,
            selected: new Set()
        },
        seo: {
            page: 1, perPage: 35, search: '', totalPages: 1
        },
        orphans: { found: [] },
        orphanSelected: new Set(),
        dashboard: { altPage: 1, altSelected: new Set() },
        currentModalId: null,
        imgCacheTs: {}
    };
    var refreshUrlFixerUi = null;

    // ================================================================
    // Init
    // ================================================================
    $(function() {
        initImpCustomSelects();
        initLanguageSwitcher();
        initTabs();
        initDashboard();
        initQualitySliders();
        initSearch();
        initOptimizeTab();
        initOrphansTab();
        initSeoTab();
        initModal();
        initPdfTab();
        initAutoTab();
        initHistoryTab();
        initUrlFixer();
        initHoverPreview();
        checkWebP();
        loadOptImages();
        loadSeoImages();
        loadAutoSettings();
        var deepId = parseInt(new URLSearchParams(window.location.search).get('tsoimma_id') || '0', 10);
        if (deepId > 0) {
            $('.imp-tab[data-tab="optimize"]').trigger('click');
            setTimeout(function() { openModal(deepId, 'optimize'); }, 400);
        }
    });

    // ================================================================
    // Language switcher (CA / ES / EN)
    // ================================================================
    var I18N_STATIC = {
        ca: {
            scan_rogue: '🔍 Escanejar fitxers extra',
            rogue_title: '🗂 Fitxers extra a uploads',
            rogue_desc: 'Escaneja fitxers a <code>uploads/</code> que WordPress no té registrats: <strong>còpies de seguretat TSO</strong> (<code>_tso_im_backup</code>), temporals (<code>_tso_im_opt</code>), doble extensió (<code>.jpg.webp</code>), backups antics (<code>.bk</code>), etc. Revisa abans d\'eliminar les còpies de seguretat.',
            rename_btn: '✏️ Reanomenar fitxer',
            repair_paths: '🔧 Reparar imatges amb path trencat',
            mime_fix_btn: '🔧 Reparar mime types incorrectes',
            scan_ghosts: 'Escanejar adjunts fantasma',
            delete_ghosts: 'Eliminar seleccionats',
            ghost_found_singular: 'adjunt fantasma trobat.',
            ghost_found_plural: 'adjunts fantasma trobats.',
            selected_of: 'seleccionats de',
            fixable_label: 'reparables',
            appears_in: 'Apareix a',
            occurrence: 'ocurrència',
            occurrences: 'ocurrències',
            url_items_suffix: 'URL(s)?',
            skipped_suffix: 'omeses',
            compressed_tag: 'Comprimit',
            pdf_preview_btn: '👁 Veure',
            pdf_preview_title: 'Previsualització PDF',
            pdf_open_tab: 'Obrir en pestanya nova',
            pdf_preview_fallback: 'Si la previsualització no es carrega al navegador, obre el PDF en una pestanya nova.',
            non_compressible_tag: 'No comprimible',
            webp_label: 'WebP',
            file_unit_suffix: 'fitxer(s)?',
            hdr_file: 'Fitxer',
            hdr_size: 'Mida',
            hdr_savings: 'Estalvi',
            hdr_date: 'Data',
            hdr_image: 'Imatge',
            hdr_action: 'Acció',
            hdr_details: 'Detalls',
            hdr_user: 'Usuari',
            system_user: 'Sistema',
            action_optimize: 'Optimitzada',
            action_auto_optimize: 'Auto-optimitzada',
            action_rename: 'Reanomenada',
            action_seo_update: 'SEO actualitzat',
            action_delete: 'Eliminada',
            action_pdf_compress: 'PDF comprimit',
            donate_support: '☕ Dona suport al plugin',
            auto_source_formats_label: 'Formats d\'origen per auto-convertir',
            auto_src_jpg: 'JPG/JPEG',
            auto_src_png: 'PNG',
            auto_src_webp: 'WEBP',
            auto_src_gif: 'GIF (només estàtic)',
            auto_src_bmp: 'BMP',
            auto_src_tiff: 'TIFF',
            modal_seo: 'SEO',
            preset_fhd: 'Full HD',
            preset_hd: 'HD',
            preset_web: 'Web',
            preset_medium: 'Mitjà',
            preset_small: 'Petit',
            rogue_reason_double_extension: 'Doble extensió (.jpg.webp)',
            rogue_reason_tso_backup: 'Backup TSO',
            rogue_reason_tso_temp: 'Temporal TSO',
            rogue_reason_tso_pdf_compressed: 'PDF comprimit TSO',
            rogue_reason_generic_backup: 'Backup genèric (.bk)',
            rogue_reason_unregistered_wp_db: 'Fitxer no registrat a la BD de WordPress',
            auto_clean_title: '🕐 Neteja automàtica de l\'historial',
            retention_days_label: 'Conservar:',
            retention_days_unit: 'dies',
            purge_interval_label: 'Comprovació:',
            interval_daily: 'Cada dia',
            interval_weekly: 'Cada setmana',
            interval_monthly: 'Cada mes',
            retention_zero_hint: '0 dies = desactivat',
            retention_saved: 'Desat!',
            retention_invalid: 'Valor invàlid. Usa 0 per desactivar, o entre 1 i 3650 dies.',
            tab_dashboard: 'Resum',
            dash_title: 'Resum del lloc',
            dash_desc: 'Visió ràpida de la salut de les imatges, motors disponibles i accions pendents.',
            dash_total_images: 'Imatges a la biblioteca',
            dash_missing_alt: 'Alt text absent',
            dash_backups: 'Còpies de seguretat TSO',
            dash_saved: 'Espai estalviat',
            dash_operations: 'operacions del plugin',
            dash_auto_on: 'Auto-optimitzar ACTIVAT',
            dash_auto_off: 'Auto-optimitzar DESACTIVAT',
            dash_alt_title: 'Imatges sense alt (o alt genèric)',
            dash_alt_desc: 'Suggereix alt des del títol o nom de fitxer. No sobreescriu un alt ja definit i útil.',
            dash_alt_used_only: 'Només usades en contingut',
            dash_alt_fill: 'Omplir alt seleccionades',
            dash_alt_suggested: 'Alt suggerit',
            dash_alt_used_in: 'Usada a',
            dash_alt_updated: 'texts alt actualitzats.',
            dash_alt_skipped: 'omeses (ja tenien alt).',
            dash_engine_gd: 'GD WebP',
            dash_engine_avif: 'GD AVIF',
            dash_engine_gs: 'GhostScript',
            dash_engine_imagick: 'Imagick',
            dash_go_orphans: 'Trobar òrfenes',
            dash_go_urls: 'Reparar URLs',
            dash_alt_all_ok: 'Totes les imatges tenen un alt útil.',
            dash_queue_title: 'Cua en segon pla',
            dash_queue_desc: 'Les optimitzacions massives s\'executen en segon pla via WP-Cron (5 imatges per lot).',
            dash_queue_cancel: 'Cancel·lar jobs pendents',
            dash_queue_empty: 'La cua és buida.',
            dash_queue_done: 'fetes',
            dash_queue_pending: 'pendents',
            dash_queue_errors: 'errors',
            dash_queue_queued: 'En cua per processar en segon pla...',
            dash_queue_queued_n: 'imatges en cua.',
            dash_backup_title: 'Retenció de còpies de seguretat',
            dash_backup_desc: 'Elimina automàticament les còpies TSO a uploads/tso-image-master/ (0 = desactivat).',
            dash_backup_days: 'Conservar còpies (dies)',
            dash_backup_max: 'Mida màxima total (MB, 0 = il·limitat)',
            dash_backup_purge: 'Purga ara',
            dash_dup_title: 'Imatges duplicades',
            dash_dup_scan: 'Escanejar duplicats',
            dash_dup_none: 'No s\'han trobat grups de duplicats.',
            dash_dup_groups: 'grups de duplicats',
            dash_dup_wasted: 'Espai malgastat',
            dash_heavy_btn: 'Imatges més pesades',
            auto_skip_kb_label: 'Ometre auto-optimitzar si WebP/AVIF ≤ (KB, 0 = off)',
            auto_fill_alt_label: 'Omplir alt absent en pujar',
            fmt_avif: 'AVIF'
        },
        es: {
            tab_optimize: 'Optimizar',
            tab_orphans: 'Imágenes Huérfanas',
            tab_seo: 'SEO y Nombres',
            tab_pdf: 'PDFs',
            tab_auto: 'Auto-optimización',
            tab_history: 'Historial',
            tab_urls: 'URLs',
            opt_config_title: 'Configuración de Optimización',
            format_label: 'Formato de salida',
            fmt_webp: 'WebP (recomendado)',
            fmt_keep: 'Mantener formato original',
            fmt_keep_current: 'Mantener formato actual',
            quality_label: 'Calidad',
            search_image_ph: '🔎 Buscar imagen...',
            select_all: 'Seleccionar todo',
            deselect: 'Deseleccionar',
            bulk_optimize: 'Optimizar seleccionadas',
            orphans_title: 'Buscar Imágenes Huérfanas',
            orphans_desc: 'Imágenes de la biblioteca que <strong>no están referenciadas</strong> en ninguna entrada, página, widget ni metadato.',
            orphan_limit_label: 'Imágenes a escanear por lote',
            all_slow: 'Todas (lento)',
            scan_now: '🔍 Escanear ahora',
            delete_selected: 'Eliminar seleccionadas',
            rogue_title: '🗂 Archivos extra en uploads',
            rogue_desc: 'Escanea archivos en <code>uploads/</code> que WordPress no tiene registrados: <strong>copias de seguridad TSO</strong> (<code>_tso_im_backup</code>), temporales (<code>_tso_im_opt</code>), doble extensión (<code>.jpg.webp</code>), copias antiguas (<code>.bk</code>), etc. Revisa antes de eliminar las copias de seguridad.',
            sort_size: '📦 Ordenar por peso (mayor primero)',
            sort_date: '📅 Ordenar por fecha de creación',
            sort_modified: '✏️ Ordenar por fecha de modificación',
            modal_rename: 'Renombrar',
            modal_optimize: 'Optimizar',
            seo_title_label: 'Título',
            seo_alt_label: 'Texto alternativo (Alt)',
            seo_caption_label: 'Pie de foto (Caption)',
            seo_desc_label: 'Descripción',
            seo_title_ph: 'Título descriptivo de la imagen',
            seo_alt_ph: 'Descripción para accesibilidad y SEO',
            seo_caption_ph: 'Texto visible bajo la imagen',
            seo_desc_ph: 'Descripción larga...',
            current_filename: 'Nombre actual del archivo',
            new_filename: 'Nuevo nombre (sin extensión)',
            filename_ph: 'p.ej: pastel-de-chocolate-receta',
            rename_hint: 'Se aplicará automáticamente formato SEO: minúsculas, sin acentos y guiones en lugar de espacios.',
            auto_suggest: 'Sugerencia automática',
            use_btn: 'Usar',
            resize_title: 'Redimensionar imagen',
            resize_desc: 'Opcional — reduce el tamaño en píxeles',
            max_width: 'Ancho máx',
            max_height: 'Alto máx',
            proportions_hint: 'Proporciones preservadas. Deja un campo vacío para calcularlo automáticamente.',
            replace_title: 'Reemplazar original',
            replace_desc: 'Actualiza automáticamente todos los enlaces de la web',
            optimize_now: '⚡ Optimizar ahora',
            pdf_title: 'Comprimir PDFs',
            pdf_desc: 'Reduce el peso de los PDFs de la biblioteca sin cambiar la URL ni romper enlaces.<br><strong>Requiere:</strong> GhostScript en el servidor (recomendado) o extensión Imagick de PHP.',
            pdf_quality_label: 'Calidad / DPI',
            dpi_72: '72 DPI — Muy ligero (pantalla)',
            dpi_96: '96 DPI — Recomendado (web)',
            dpi_150: '150 DPI — Alta calidad',
            dpi_300: '300 DPI — Impresión',
            pdf_replace_label: 'Reemplaza el original (la URL no cambia)',
            search_pdf_ph: '🔎 Buscar PDF...',
            compress_selected: 'Comprimir seleccionados',
            auto_title: 'Auto-optimización al subir imágenes',
            auto_desc: 'Al activar esta opción, <strong>cada imagen nueva que subas</strong> se optimizará automáticamente con la configuración elegida.',
            save_config: '💾 Guardar configuración',
            repair_paths: '🔧 Reparar imágenes con ruta rota',
            mime_fix_title: 'Reparación de tipo mime',
            mime_fix_desc: 'Detecta y repara adjuntos con extensión .webp pero mime type incorrecto (image/jpeg). Soluciona imágenes invisibles en la biblioteca de medios.',
            auto_stats_title: 'Estadísticas de auto-optimización',
            auto_history_title: 'Historial de auto-optimización',
            history_title: 'Historial de cambios',
            history_filter_label: 'Filtrar por acción',
            all_actions: 'Todas las acciones',
            filter_optimize: '⚡ Optimizadas',
            filter_auto: '🤖 Auto-optimizadas',
            filter_rename: '✏️ Renombradas',
            filter_seo: '🏷️ SEO actualizado',
            filter_delete: '🗑️ Eliminadas',
            filter_pdf: '📄 PDFs comprimidos',
            date_from: 'Desde',
            date_to: 'Hasta',
            search_file: 'Buscar archivo',
            search_file_ph: 'nombre del archivo...',
            history_load: '🔄 Cargar / filtrar',
            clear_30: '🗑️ Limpiar >30 días',
            clear_all: '🗑️ Limpiar todo',
            auto_clean_title: '🕐 Limpieza automática del historial',
            retention_days_label: 'Conservar:',
            retention_days_unit: 'días',
            purge_interval_label: 'Comprobación:',
            interval_daily: 'Cada día',
            interval_weekly: 'Cada semana',
            interval_monthly: 'Cada mes',
            retention_zero_hint: '0 días = desactivado',
            retention_saved: '¡Guardado!',
            retention_invalid: 'Valor inválido. Usa 0 para desactivar, o entre 1 y 3650 días.',
            save_btn: '💾 Guardar',
            url_title: '🔗 Detector de URLs Inconsistentes',
            url_desc: 'Detecta URLs de imágenes en entradas y páginas que <strong>apuntan a archivos que ya no existen</strong> en el servidor — normalmente por conversiones de JPG a WebP. Puede actualizarlas automáticamente en toda la base de datos.',
            url_obsolete: 'URL obsoleta → nueva correcta',
            scan_web: '🔍 Escanear toda la web',
            select_fixable: 'Seleccionar reparables',
            fix_selected: '✅ Reparar seleccionadas',
            scan_rogue: '🔍 Escanear archivos extra',
            rename_btn: '✏️ Renombrar archivo',
            files_label: 'archivos',
            remaining_label: 'restantes',
            open_title: 'Abrir',
            compressed_tag: 'Comprimido',
            pdf_preview_btn: '👁 Ver',
            pdf_preview_title: 'Vista previa PDF',
            pdf_open_tab: 'Abrir en pestaña nueva',
            pdf_preview_fallback: 'Si la vista previa no se carga en el navegador, abre el PDF en una pestaña nueva.',
            non_compressible_tag: 'No comprimible',
            webp_label: 'WebP',
            file_unit_suffix: 'archivo(s)?',
            hdr_file: 'Archivo',
            hdr_size: 'Tamaño',
            hdr_savings: 'Ahorro',
            hdr_date: 'Fecha',
            hdr_image: 'Imagen',
            hdr_action: 'Acción',
            hdr_details: 'Detalles',
            hdr_user: 'Usuario',
            system_user: 'Sistema',
            action_optimize: 'Optimizada',
            action_auto_optimize: 'Auto-optimizada',
            action_rename: 'Renombrada',
            action_seo_update: 'SEO actualizado',
            action_delete: 'Eliminada',
            action_pdf_compress: 'PDF comprimido',
            selected_of: 'seleccionados de',
            fixable_label: 'reparables',
            appears_in: 'Aparece en',
            occurrence: 'ocurrencia',
            occurrences: 'ocurrencias',
            url_items_suffix: 'URL(s)?',
            skipped_suffix: 'omitidas',
            error_prefix: 'Error: ',
            ghost_found_singular: 'adjunto fantasma encontrado.',
            ghost_found_plural: 'adjuntos fantasma encontrados.',
            ghost_confirm_suffix: 'adjunto(s) fantasma? Esto elimina los registros de la base de datos.',
            post_label: 'Entrada',
            page_label: 'Página'
            ,results_title: 'Resultados'
            ,clear_btn: 'Limpiar'
            ,scanning_server_files: 'Escaneando archivos del servidor...'
            ,edit_image_title: 'Editar imagen'
            ,repair_images_desc: '🔧 <strong style="color:var(--imp-warn);">Reparación de imágenes</strong> — Si tienes imágenes auto-optimizadas a WebP que no aparecen en SEO y Nombres ni en Optimizar, este botón repara la ruta en la base de datos.'
            ,ghost_delete_desc: '🗑️ <strong style="color:var(--imp-danger);">Eliminar adjuntos fantasma</strong> — Detecta registros en la BD que apuntan a archivos que <strong>no existen físicamente</strong> en disco. Los elimina por completo: post, metadatos y miniaturas de BD. <em>No toca ningún archivo físico.</em>'
            ,loading_stats: 'Cargando estadísticas...'
            ,history_prompt_load: 'Carga el historial pulsando "Cargar".'
            ,scanning_posts_pages: 'Escaneando contenidos... puede tardar unos segundos.'
            ,donate_support: '☕ Apoya este plugin'
            ,auto_source_formats_label: 'Formatos de origen para auto-convertir'
            ,auto_src_jpg: 'JPG/JPEG'
            ,auto_src_png: 'PNG'
            ,auto_src_webp: 'WEBP'
            ,auto_src_gif: 'GIF (solo estático)'
            ,auto_src_bmp: 'BMP'
            ,auto_src_tiff: 'TIFF'
            ,modal_seo: 'SEO'
            ,preset_fhd: 'Full HD'
            ,preset_hd: 'HD'
            ,preset_web: 'Web'
            ,preset_medium: 'Medio'
            ,preset_small: 'Pequeño'
            ,rogue_reason_double_extension: 'Doble extensión (.jpg.webp)'
            ,rogue_reason_tso_backup: 'Backup TSO'
            ,rogue_reason_tso_temp: 'Temporal TSO'
            ,rogue_reason_tso_pdf_compressed: 'PDF comprimido TSO'
            ,rogue_reason_generic_backup: 'Backup genérico (.bk)'
            ,rogue_reason_unregistered_wp_db: 'Archivo no registrado en la BD de WordPress'
            ,tab_dashboard: 'Resumen'
            ,dash_title: 'Resumen del sitio'
            ,dash_desc: 'Vista rápida de la salud de las imágenes, motores disponibles y acciones pendientes.'
            ,dash_total_images: 'Imágenes en la biblioteca'
            ,dash_missing_alt: 'Texto alt ausente'
            ,dash_backups: 'Copias de seguridad TSO'
            ,dash_saved: 'Espacio ahorrado'
            ,dash_operations: 'operaciones del plugin'
            ,dash_auto_on: 'Auto-optimizar ACTIVADO'
            ,dash_auto_off: 'Auto-optimizar DESACTIVADO'
            ,dash_alt_title: 'Imágenes sin alt (o alt genérico)'
            ,dash_alt_desc: 'Sugiere alt desde el título o nombre de archivo. No sobrescribe un alt útil existente.'
            ,dash_alt_used_only: 'Solo usadas en contenido'
            ,dash_alt_fill: 'Rellenar alt seleccionadas'
            ,dash_alt_suggested: 'Alt sugerido'
            ,dash_alt_used_in: 'Usada en'
            ,dash_alt_updated: 'textos alt actualizados.'
            ,dash_alt_skipped: 'omitidas (ya tenían alt).'
            ,dash_engine_gd: 'GD WebP'
            ,dash_engine_avif: 'GD AVIF'
            ,dash_engine_gs: 'GhostScript'
            ,dash_engine_imagick: 'Imagick'
            ,dash_go_orphans: 'Buscar huérfanas'
            ,dash_go_urls: 'Reparar URLs'
            ,dash_alt_all_ok: 'Todas las imágenes tienen un alt útil.'
            ,dash_queue_title: 'Cola en segundo plano'
            ,dash_queue_desc: 'Las optimizaciones masivas se ejecutan en segundo plano vía WP-Cron (5 imágenes por lote).'
            ,dash_queue_cancel: 'Cancelar trabajos pendientes'
            ,dash_queue_empty: 'La cola está vacía.'
            ,dash_queue_done: 'hechas'
            ,dash_queue_pending: 'pendientes'
            ,dash_queue_errors: 'errores'
            ,dash_queue_queued: 'En cola para procesar en segundo plano...'
            ,dash_queue_queued_n: 'imágenes en cola.'
            ,dash_backup_title: 'Retención de copias de seguridad'
            ,dash_backup_desc: 'Elimina automáticamente las copias TSO en uploads/tso-image-master/ (0 = desactivado).'
            ,dash_backup_days: 'Conservar copias (días)'
            ,dash_backup_max: 'Tamaño máximo total (MB, 0 = ilimitado)'
            ,dash_backup_purge: 'Purgar ahora'
            ,dash_dup_title: 'Imágenes duplicadas'
            ,dash_dup_scan: 'Escanear duplicados'
            ,dash_dup_none: 'No se han encontrado grupos de duplicados.'
            ,dash_dup_groups: 'grupos de duplicados'
            ,dash_dup_wasted: 'Espacio desperdiciado'
            ,dash_heavy_btn: 'Imágenes más pesadas'
            ,auto_skip_kb_label: 'Omitir auto-optimizar si WebP/AVIF ≤ (KB, 0 = off)'
            ,auto_fill_alt_label: 'Rellenar alt ausente al subir'
            ,fmt_avif: 'AVIF'
        },
        en: {
            tab_optimize: 'Optimize',
            tab_orphans: 'Orphan Images',
            tab_seo: 'SEO & Names',
            tab_pdf: 'PDFs',
            tab_auto: 'Auto-optimization',
            tab_history: 'History',
            tab_urls: 'URLs',
            opt_config_title: 'Optimization Settings',
            format_label: 'Output format',
            fmt_webp: 'WebP (recommended)',
            fmt_keep: 'Keep original format',
            fmt_keep_current: 'Keep current format',
            quality_label: 'Quality',
            search_image_ph: '🔎 Search image...',
            select_all: 'Select all',
            deselect: 'Deselect',
            bulk_optimize: 'Optimize selected',
            orphans_title: 'Find Orphan Images',
            orphans_desc: 'Images in the media library that are <strong>not referenced</strong> in any post, page, widget or metadata.',
            orphan_limit_label: 'Images to scan per batch',
            all_slow: 'All (slow)',
            scan_now: '🔍 Scan now',
            delete_selected: 'Delete selected',
            rogue_title: '🗂 Extra files in uploads',
            rogue_desc: 'Scans files in <code>uploads/</code> not registered in WordPress: <strong>TSO backup copies</strong> (<code>_tso_im_backup</code>), temp files (<code>_tso_im_opt</code>), double extensions (<code>.jpg.webp</code>), old backups (<code>.bk</code>), etc. Review before deleting backup copies.',
            sort_size: '📦 Sort by size (largest first)',
            sort_date: '📅 Sort by creation date',
            sort_modified: '✏️ Sort by modification date',
            modal_rename: 'Rename',
            modal_optimize: 'Optimize',
            seo_title_label: 'Title',
            seo_alt_label: 'Alt text',
            seo_caption_label: 'Caption',
            seo_desc_label: 'Description',
            seo_title_ph: 'Descriptive image title',
            seo_alt_ph: 'Accessibility and SEO description',
            seo_caption_ph: 'Visible text below the image',
            seo_desc_ph: 'Long description...',
            current_filename: 'Current filename',
            new_filename: 'New name (without extension)',
            filename_ph: 'e.g: chocolate-cake-recipe',
            rename_hint: 'SEO format is applied automatically: lowercase, no accents, hyphens instead of spaces.',
            auto_suggest: 'Automatic suggestion',
            use_btn: 'Use',
            resize_title: 'Resize image',
            resize_desc: 'Optional — reduce pixel dimensions',
            max_width: 'Max width',
            max_height: 'Max height',
            proportions_hint: 'Proportions are preserved. Leave one field empty to auto-calculate.',
            replace_title: 'Replace original',
            replace_desc: 'Automatically updates all links on the website',
            optimize_now: '⚡ Optimize now',
            pdf_title: 'Compress PDFs',
            pdf_desc: 'Reduce PDF file size in the media library without changing URLs or breaking links.<br><strong>Requires:</strong> GhostScript on server (recommended) or PHP Imagick extension.',
            pdf_quality_label: 'Quality / DPI',
            dpi_72: '72 DPI — Very light (screen)',
            dpi_96: '96 DPI — Recommended (web)',
            dpi_150: '150 DPI — High quality',
            dpi_300: '300 DPI — Print',
            pdf_replace_label: 'Replace original (URL unchanged)',
            search_pdf_ph: '🔎 Search PDF...',
            compress_selected: 'Compress selected',
            auto_title: 'Auto-optimization on upload',
            auto_desc: 'When enabled, <strong>every new uploaded image</strong> is optimized automatically with the selected settings.',
            save_config: '💾 Save settings',
            repair_paths: '🔧 Repair images with broken path',
            mime_fix_title: 'Mime type repair',
            mime_fix_desc: 'Detect and repair attachments with .webp extension but incorrect mime type (image/jpeg). Fixes invisible images in Media Library.',
            auto_stats_title: 'Auto-optimization statistics',
            auto_history_title: 'Auto-optimization history',
            history_title: 'Change history',
            history_filter_label: 'Filter by action',
            all_actions: 'All actions',
            filter_optimize: '⚡ Optimized',
            filter_auto: '🤖 Auto-optimized',
            filter_rename: '✏️ Renamed',
            filter_seo: '🏷️ SEO updated',
            filter_delete: '🗑️ Deleted',
            filter_pdf: '📄 Compressed PDFs',
            date_from: 'From',
            date_to: 'To',
            search_file: 'Search file',
            search_file_ph: 'filename...',
            history_load: '🔄 Load / filter',
            clear_30: '🗑️ Clean >30 days',
            clear_all: '🗑️ Clean all',
            auto_clean_title: '🕐 Automatic history cleanup',
            retention_days_label: 'Keep:',
            retention_days_unit: 'days',
            purge_interval_label: 'Check:',
            interval_daily: 'Daily',
            interval_weekly: 'Weekly',
            interval_monthly: 'Monthly',
            retention_zero_hint: '0 days = disabled',
            retention_saved: 'Saved!',
            retention_invalid: 'Invalid value. Use 0 to disable, or between 1 and 3650 days.',
            save_btn: '💾 Save',
            url_title: '🔗 Inconsistent URL Detector',
            url_desc: 'Detects image URLs in posts/pages that <strong>point to files that no longer exist</strong> on the server — typically after JPG to WebP conversions. It can update links automatically across the database.',
            url_obsolete: 'Obsolete URL → corrected URL',
            scan_web: '🔍 Scan entire website',
            select_fixable: 'Select fixable',
            fix_selected: '✅ Fix selected',
            scan_rogue: '🔍 Scan extra upload files',
            rename_btn: '✏️ Rename file',
            files_label: 'files',
            remaining_label: 'remaining',
            open_title: 'Open',
            compressed_tag: 'Compressed',
            pdf_preview_btn: '👁 View',
            pdf_preview_title: 'PDF preview',
            pdf_open_tab: 'Open in new tab',
            pdf_preview_fallback: 'If the preview does not load in your browser, open the PDF in a new tab.',
            non_compressible_tag: 'Not compressible',
            webp_label: 'WebP',
            file_unit_suffix: 'file(s)?',
            hdr_file: 'File',
            hdr_size: 'Size',
            hdr_savings: 'Savings',
            hdr_date: 'Date',
            hdr_image: 'Image',
            hdr_action: 'Action',
            hdr_details: 'Details',
            hdr_user: 'User',
            system_user: 'System',
            action_optimize: 'Optimized',
            action_auto_optimize: 'Auto-optimized',
            action_rename: 'Renamed',
            action_seo_update: 'SEO updated',
            action_delete: 'Deleted',
            action_pdf_compress: 'PDF compressed',
            selected_of: 'selected of',
            fixable_label: 'fixable',
            appears_in: 'Appears in',
            occurrence: 'occurrence',
            occurrences: 'occurrences',
            url_items_suffix: 'URL(s)?',
            skipped_suffix: 'skipped',
            error_prefix: 'Error: ',
            ghost_found_singular: 'ghost attachment found.',
            ghost_found_plural: 'ghost attachments found.',
            ghost_confirm_suffix: 'ghost attachment(s)? This removes database records.',
            post_label: 'Post',
            page_label: 'Page'
            ,results_title: 'Results'
            ,clear_btn: 'Clear'
            ,scanning_server_files: 'Scanning server files...'
            ,edit_image_title: 'Edit image'
            ,repair_images_desc: '🔧 <strong style="color:var(--imp-warn);">Image repair</strong> — If you have auto-optimized WebP images that do not appear in SEO & Names or Optimize, this button repairs their database path.'
            ,ghost_delete_desc: '🗑️ <strong style="color:var(--imp-danger);">Delete ghost attachments</strong> — Detects DB records that point to files that <strong>do not physically exist</strong> on disk. Deletes post, metadata and DB thumbnails. <em>No physical files are touched.</em>'
            ,loading_stats: 'Loading statistics...'
            ,history_prompt_load: 'Load history by clicking "Load".'
            ,scanning_posts_pages: 'Scanning content items... this may take a few seconds.'
            ,donate_support: '☕ Support this plugin'
            ,auto_source_formats_label: 'Source formats for auto-conversion'
            ,auto_src_jpg: 'JPG/JPEG'
            ,auto_src_png: 'PNG'
            ,auto_src_webp: 'WEBP'
            ,auto_src_gif: 'GIF (static only)'
            ,auto_src_bmp: 'BMP'
            ,auto_src_tiff: 'TIFF'
            ,modal_seo: 'SEO'
            ,preset_fhd: 'Full HD'
            ,preset_hd: 'HD'
            ,preset_web: 'Web'
            ,preset_medium: 'Medium'
            ,preset_small: 'Small'
            ,rogue_reason_double_extension: 'Double extension (.jpg.webp)'
            ,rogue_reason_tso_backup: 'TSO backup'
            ,rogue_reason_tso_temp: 'TSO temporary file'
            ,rogue_reason_tso_pdf_compressed: 'TSO compressed PDF'
            ,rogue_reason_generic_backup: 'Generic backup (.bk)'
            ,rogue_reason_unregistered_wp_db: 'File not registered in WordPress DB'
            ,tab_dashboard: 'Overview'
            ,dash_title: 'Site overview'
            ,dash_desc: 'Quick view of image health, available engines, and pending actions.'
            ,dash_total_images: 'Images in library'
            ,dash_missing_alt: 'Missing alt text'
            ,dash_backups: 'TSO backups'
            ,dash_saved: 'Space saved'
            ,dash_operations: 'operations'
            ,dash_auto_on: 'Auto-optimize ON'
            ,dash_auto_off: 'Auto-optimize OFF'
            ,dash_alt_title: 'Images without alt (or generic alt)'
            ,dash_alt_desc: 'Suggests alt from title or filename. Does not overwrite useful existing alt text.'
            ,dash_alt_used_only: 'Only used in content'
            ,dash_alt_fill: 'Fill alt for selected'
            ,dash_alt_suggested: 'Suggested alt'
            ,dash_alt_used_in: 'Used in'
            ,dash_alt_updated: 'alt texts updated.'
            ,dash_alt_skipped: 'skipped (already had alt).'
            ,dash_engine_gd: 'GD WebP'
            ,dash_engine_avif: 'GD AVIF'
            ,dash_engine_gs: 'GhostScript'
            ,dash_engine_imagick: 'Imagick'
            ,dash_go_orphans: 'Find orphans'
            ,dash_go_urls: 'Fix URLs'
            ,dash_alt_all_ok: 'All images have useful alt text.'
            ,dash_queue_title: 'Background queue'
            ,dash_queue_desc: 'Bulk optimize jobs run in the background via WP-Cron (5 images per batch).'
            ,dash_queue_cancel: 'Cancel pending jobs'
            ,dash_queue_empty: 'Queue is empty.'
            ,dash_queue_done: 'done'
            ,dash_queue_pending: 'pending'
            ,dash_queue_errors: 'errors'
            ,dash_queue_queued: 'Queued for background processing...'
            ,dash_queue_queued_n: 'images queued.'
            ,dash_backup_title: 'Backup retention'
            ,dash_backup_desc: 'Auto-delete TSO backups under uploads/tso-image-master/ (0 = disabled).'
            ,dash_backup_days: 'Keep backups (days)'
            ,dash_backup_max: 'Max total size (MB, 0 = unlimited)'
            ,dash_backup_purge: 'Purge now'
            ,dash_dup_title: 'Duplicate images'
            ,dash_dup_scan: 'Scan duplicates'
            ,dash_dup_none: 'No duplicate groups found.'
            ,dash_dup_groups: 'duplicate groups'
            ,dash_dup_wasted: 'Wasted space'
            ,dash_heavy_btn: 'Largest images'
            ,auto_skip_kb_label: 'Skip auto-optimize if WebP/AVIF ≤ (KB, 0 = off)'
            ,auto_fill_alt_label: 'Fill missing alt text on upload'
            ,fmt_avif: 'AVIF'
        }
    };

    // Strings from wp_localize_script (WordPress locale). Merged so plugin UI language overrides WP admin language.
    (function mergeLocalizedStringsIntoI18n() {
        var WP = {
            es: {
                confirm_delete: '¿Eliminar las imágenes seleccionadas? Esta acción es irreversible.',
                confirm_del_backup: '¿Eliminar el backup? No podrás revertir la imagen.',
                confirm_revert: '¿Revertir al original? La versión optimizada se perderá.',
                confirm_delete_rogue: 'Eliminar',
                no_selection: 'Selecciona al menos una imagen.',
                processing: 'Procesando...',
                save_ok: '¡Guardado!',
                save_seo: 'Guardar SEO',
                edit_image: 'Editar',
                scan_rogue: 'Escanear archivos extra',
                delete_rogue: 'Eliminar seleccionados',
                scan_web: 'Escanear todo el sitio',
                fix_selected: 'Reparar seleccionadas',
                optimize_now: 'Optimizar ahora',
                rename_btn: 'Renombrar archivo',
                revert_btn: 'Revertir al original',
                del_backup: 'Eliminar copia de seguridad',
                backup_available: 'Copia de seguridad disponible',
                repair_paths: 'Reparar rutas rotas',
                loading_images: 'Cargando imágenes...',
                loading_pdfs: 'Cargando PDFs...',
                loading_data: 'Cargando...',
                loading_modal: 'Cargando...',
                scanning_msg: 'Escaneando...',
                no_images: 'No se han encontrado imágenes.',
                no_orphans: 'No se han encontrado imágenes huérfanas.',
                no_rogue: 'No se han encontrado archivos extra.',
                no_pdfs: 'No se han encontrado PDFs.',
                no_auto_history: 'No hay entradas de auto-optimización.',
                auto_hist_error: 'Error al cargar el historial.',
                no_history: 'No se han encontrado entradas.',
                history_empty: 'El historial está vacío.',
                url_all_ok: 'Ninguna URL rota encontrada. ¡Todo correcto!',
                url_no_results: 'Sin resultados.',
                url_click_select: 'Haz clic para seleccionar',
                url_content_label: 'URL en el contenido (obsoleta)',
                url_correct_label: 'URL correcta (archivo existente)',

                url_outdated_badge: 'Miniatura obsoleta (archivo existente en nuevo formato)',
                url_missing_badge: 'Archivo ausente — alternativa encontrada',
                url_broken_badge: 'Archivo ausente — sin solución automática',
                url_fixing: 'Reparando...',
                select_removable: 'Seleccionar eliminables',
                removable_label: 'eliminables',
                remove_selected: 'Eliminar del contenido',
                url_removing: 'Eliminando...',
                url_removed_ok: 'Referencias URL eliminadas del contenido.',
                confirm_remove_urls: '¿Eliminar las referencias URL rotas del contenido? Se borrará la etiqueta img o el enlace.',
                url_click_select_remove: 'Clic para seleccionar y eliminar',
                url_no_fix_label: 'Sin alternativa. Selecciona y elimina la referencia del contenido, o restaura el archivo manualmente.',
                posts_scanned: 'Contenidos escaneados',
                broken_urls: 'URLs rotas',
                fixable_urls: 'Reparable automáticamente',
                used_in: 'Usada en',
                orphan_confirmed: 'Huérfana confirmada: no referenciada en ningún lugar.',
                not_in_content: 'No encontrado en post_content.',
                optimized_ok: '¡Optimizada!',
                optimized_no_replace: 'Optimizada (sin reemplazar).',
                converted_bigger: 'Convertida pero más grande que el original.',
                optimizing_thumbs: 'Optimizando miniaturas...',
                thumbs_done: 'Miniaturas procesadas.',
                reverted_ok: '¡Revertida!',
                n_selected: 'seleccionadas',
                optimize_done: 'ahorrado',
                bulk_done: 'Hecho',
                bulk_processing: 'Procesando',
                pdf_compress_btn: 'Comprimir',
                pdf_timeout_msg: 'GhostScript tardó demasiado. Comprueba el FTP — puede estar comprimido. Recarga la página.',
                gs_available: 'GhostScript disponible',
                gs_none: 'Ningún motor de compresión disponible. Instala GhostScript.',
                auto_enabled: 'Auto-optimización ACTIVADA',
                auto_disabled: 'Auto-optimización desactivada',
                auto_desc_enabled: 'Las nuevas imágenes se convertirán automáticamente al subirlas.',
                auto_desc_disabled: 'Activa para optimizar automáticamente.',
                repaired_msg: 'imágenes reparadas. Recarga SEO & Nombres para verlas.',
                confirm_clean_30: '¿Eliminar entradas de hace más de 30 días?',
                confirm_clean_all: '¿Eliminar TODO el historial? Esta acción es irreversible.',
                btn_scanning: 'Escaneando...',
                btn_processing: 'Procesando...',
                btn_deleting: 'Eliminando...',
                btn_reverting: 'Revirtiendo...',
                revert_rename_mismatch: 'No se puede revertir porque cambiaste el nombre del archivo después de optimizarlo. El backup sigue en el servidor pero ya no coincide con el nombre actual (por ejemplo, eliminaste el sufijo -e172…). Opciones: restaura el nombre original y entonces podrás revertir; elimina este backup y vuelve a optimizar para generar uno nuevo; o recupera el archivo manualmente desde Imágenes huérfanas → Archivos extra en uploads.',
                btn_repairing: 'Reparando...',
                webp_ok: 'Soportado',
                webp_nok: 'No disponible (GD sin WebP)',
                enter_name: 'Introduce el nuevo nombre.',
                stat_total: 'Total de operaciones',
                stat_saved: 'Espacio liberado',
                stat_current_size: 'Tamaño actual',
                stat_real_format: 'Formato real',
                images_deleted: 'imágenes eliminadas.',
                all_rogue_deleted: 'Archivos extra seleccionados eliminados.',
                featured: 'Imagen destacada',
                no_alt_text: 'Sin texto alternativo',
                url_fixed_ok: 'URLs reparadas correctamente.',
                mime_fix_btn: '🔧 Reparar tipos MIME incorrectos',
                mime_fixed: 'adjuntos reparados.',
                mime_no_issues: 'Sin discrepancias. Todos los adjuntos están correctos.',
                scan_ghosts: 'Escanear adjuntos fantasma',
                no_ghosts: 'No se han encontrado adjuntos fantasma.',
                delete_ghosts: 'Eliminar seleccionados',
                confirm_delete_ghosts: 'Eliminar',
                deleted_msg: 'Eliminados',
                ghost_deleted_ok: 'eliminados correctamente.',
                ghost_deleted_none: 'Ninguno eliminado.',
                errors_label: 'Errores:',
                pdf_engine_warn: '⚠ GhostScript no encontrado. Usando Imagick.',
                tab_dashboard: 'Resumen',
                dash_title: 'Resumen del sitio',
                dash_desc: 'Vista rápida de la salud de las imágenes, motores disponibles y acciones pendientes.',
                dash_total_images: 'Imágenes en la biblioteca',
                dash_missing_alt: 'Texto alt ausente',
                dash_backups: 'Copias de seguridad TSO',
                dash_saved: 'Espacio ahorrado',
                dash_operations: 'operaciones del plugin',
                dash_auto_on: 'Auto-optimizar ACTIVADO',
                dash_auto_off: 'Auto-optimizar DESACTIVADO',
                dash_alt_title: 'Imágenes sin alt (o alt genérico)',
                dash_alt_desc: 'Sugiere alt desde el título o nombre de archivo. No sobrescribe un alt útil existente.',
                dash_alt_used_only: 'Solo usadas en contenido',
                dash_alt_fill: 'Rellenar alt seleccionadas',
                dash_alt_suggested: 'Alt sugerido',
                dash_alt_used_in: 'Usada en',
                dash_alt_updated: 'textos alt actualizados.',
                dash_alt_skipped: 'omitidas (ya tenían alt).',
                dash_engine_gd: 'GD WebP',
                dash_engine_avif: 'GD AVIF',
                dash_engine_gs: 'GhostScript',
                dash_engine_imagick: 'Imagick',
                dash_go_orphans: 'Buscar huérfanas',
                dash_go_urls: 'Reparar URLs',
                dash_alt_all_ok: 'Todas las imágenes tienen un alt útil.',
                dash_queue_title: 'Cola en segundo plano',
                dash_queue_desc: 'Las optimizaciones masivas se ejecutan en segundo plano vía WP-Cron (5 imágenes por lote).',
                dash_queue_cancel: 'Cancelar trabajos pendientes',
                dash_queue_empty: 'La cola está vacía.',
                dash_queue_done: 'hechas',
                dash_queue_pending: 'pendientes',
                dash_queue_errors: 'errores',
                dash_queue_queued: 'En cola para procesar en segundo plano...',
                dash_queue_queued_n: 'imágenes en cola.',
                dash_backup_title: 'Retención de copias de seguridad',
                dash_backup_desc: 'Elimina automáticamente las copias TSO en uploads/tso-image-master/ (0 = desactivado).',
                dash_backup_days: 'Conservar copias (días)',
                dash_backup_max: 'Tamaño máximo total (MB, 0 = ilimitado)',
                dash_backup_purge: 'Purgar ahora',
                dash_dup_title: 'Imágenes duplicadas',
                dash_dup_scan: 'Escanear duplicados',
                dash_dup_none: 'No se han encontrado grupos de duplicados.',
                dash_dup_groups: 'grupos de duplicados',
                dash_dup_wasted: 'Espacio desperdiciado',
                dash_heavy_btn: 'Imágenes más pesadas',
                auto_skip_kb_label: 'Omitir auto-optimizar si WebP/AVIF ≤ (KB, 0 = off)',
                auto_fill_alt_label: 'Rellenar alt ausente al subir',
                fmt_avif: 'AVIF'
            },
            en: {
                confirm_delete: 'Delete selected images? This cannot be undone.',
                confirm_del_backup: 'Delete backup? You will not be able to revert.',
                confirm_revert: 'Revert to original? Optimized version will be lost.',
                confirm_delete_rogue: 'Delete',
                no_selection: 'Select at least one image.',
                processing: 'Processing...',
                save_ok: 'Saved!',
                save_seo: 'Save SEO',
                edit_image: 'Edit',
                scan_rogue: 'Scan extra upload files',
                delete_rogue: 'Delete selected',
                scan_web: 'Scan entire site',
                fix_selected: 'Fix selected',
                optimize_now: 'Optimize now',
                rename_btn: 'Rename file',
                revert_btn: 'Revert to original',
                del_backup: 'Delete backup',
                backup_available: 'Backup available',
                repair_paths: 'Repair broken paths',
                loading_images: 'Loading images...',
                loading_pdfs: 'Loading PDFs...',
                loading_data: 'Loading...',
                loading_modal: 'Loading...',
                scanning_msg: 'Scanning...',
                no_images: 'No images found.',
                no_orphans: 'No orphaned images found.',
                no_rogue: 'No extra files found.',
                no_pdfs: 'No PDFs found.',
                no_auto_history: 'No auto-optimization entries.',
                auto_hist_error: 'Error loading history.',
                no_history: 'No entries found.',
                history_empty: 'History is empty.',
                url_all_ok: 'No broken URLs found. All correct!',
                url_no_results: 'No results.',
                url_click_select: 'Click to select',
                url_content_label: 'URL in content (obsolete)',
                url_correct_label: 'Correct URL (file exists)',

                url_outdated_badge: 'Obsolete thumbnail (file exists in new format)',
                url_missing_badge: 'Missing file - alternative found',
                url_broken_badge: 'Missing file - no automatic fix',
                url_fixing: 'Fixing...',
                select_removable: 'Select removable',
                removable_label: 'removable',
                remove_selected: 'Remove from content',
                url_removing: 'Removing...',
                url_removed_ok: 'URL references removed from content.',
                confirm_remove_urls: 'Remove selected broken URL references from content? The image tag or link will be deleted.',
                url_click_select_remove: 'Click to select for removal',
                url_no_fix_label: 'No alternative found. Select and remove the reference from content, or restore the file manually.',
                posts_scanned: 'Content items scanned',
                broken_urls: 'Broken URLs',
                fixable_urls: 'Auto-fixable',
                used_in: 'Used in',
                orphan_confirmed: 'Confirmed orphan: not referenced anywhere.',
                not_in_content: 'Not found in post_content.',
                optimized_ok: 'Optimized!',
                optimized_no_replace: 'Optimized (not replaced).',
                converted_bigger: 'Converted but larger than original.',
                optimizing_thumbs: 'Optimizing thumbnails...',
                thumbs_done: 'Thumbnails processed.',
                reverted_ok: 'Reverted!',
                n_selected: 'selected',
                optimize_done: 'saved',
                bulk_done: 'Done',
                bulk_processing: 'Processing',
                pdf_compress_btn: 'Compress',
                pdf_timeout_msg: 'GhostScript timed out. Check FTP - file may already be compressed. Reload the page.',
                gs_available: 'GhostScript available',
                gs_none: 'No compression engine available. Install GhostScript.',
                auto_enabled: 'Auto-optimization ENABLED',
                auto_disabled: 'Auto-optimization disabled',
                auto_desc_enabled: 'New images will be converted automatically on upload.',
                auto_desc_disabled: 'Enable to optimize automatically.',
                repaired_msg: 'images repaired. Reload SEO & Names to see them.',
                confirm_clean_30: 'Delete entries older than 30 days?',
                confirm_clean_all: 'Delete ALL history? This is irreversible.',
                btn_scanning: 'Scanning...',
                btn_processing: 'Processing...',
                btn_deleting: 'Deleting...',
                btn_reverting: 'Reverting...',
                revert_rename_mismatch: 'Cannot revert because you renamed the file after optimizing it. The backup still exists on the server but no longer matches the current filename (e.g. you removed the -e172… suffix). Options: restore the original filename and revert; delete this backup and re-optimize to create a new one; or recover the file manually from Orphan Images → Extra upload files.',
                btn_repairing: 'Repairing...',
                webp_ok: 'Supported',
                webp_nok: 'Not available (GD without WebP)',
                enter_name: 'Enter the new name.',
                stat_total: 'Total operations',
                stat_saved: 'Space freed',
                stat_current_size: 'Current size',
                stat_real_format: 'Real format',
                images_deleted: 'images deleted.',
                all_rogue_deleted: 'All selected extra files deleted!',
                featured: 'Featured image',
                no_alt_text: 'No alt text',
                url_fixed_ok: 'URLs fixed correctly.',
                mime_fix_btn: '🔧 Fix incorrect mime types',
                mime_fixed: 'attachments repaired.',
                mime_no_issues: 'No issues found. All attachments are correct.',
                scan_ghosts: 'Scan ghost attachments',
                no_ghosts: 'No ghost attachments found.',
                delete_ghosts: 'Delete selected',
                confirm_delete_ghosts: 'Delete',
                deleted_msg: 'Deleted',
                ghost_deleted_ok: 'deleted successfully.',
                ghost_deleted_none: 'None deleted.',
                errors_label: 'Errors:',
                pdf_engine_warn: '⚠ GhostScript not found. Using Imagick.',
                tab_dashboard: 'Overview',
                dash_title: 'Site overview',
                dash_desc: 'Quick view of image health, available engines, and pending actions.',
                dash_total_images: 'Images in library',
                dash_missing_alt: 'Missing alt text',
                dash_backups: 'TSO backups',
                dash_saved: 'Space saved',
                dash_operations: 'operations',
                dash_auto_on: 'Auto-optimize ON',
                dash_auto_off: 'Auto-optimize OFF',
                dash_alt_title: 'Images without alt (or generic alt)',
                dash_alt_desc: 'Suggests alt from title or filename. Does not overwrite useful existing alt text.',
                dash_alt_used_only: 'Only used in content',
                dash_alt_fill: 'Fill alt for selected',
                dash_alt_suggested: 'Suggested alt',
                dash_alt_used_in: 'Used in',
                dash_alt_updated: 'alt texts updated.',
                dash_alt_skipped: 'skipped (already had alt).',
                dash_engine_gd: 'GD WebP',
                dash_engine_avif: 'GD AVIF',
                dash_engine_gs: 'GhostScript',
                dash_engine_imagick: 'Imagick',
                dash_go_orphans: 'Find orphans',
                dash_go_urls: 'Fix URLs',
                dash_alt_all_ok: 'All images have useful alt text.',
                dash_queue_title: 'Background queue',
                dash_queue_desc: 'Bulk optimize jobs run in the background via WP-Cron (5 images per batch).',
                dash_queue_cancel: 'Cancel pending jobs',
                dash_queue_empty: 'Queue is empty.',
                dash_queue_done: 'done',
                dash_queue_pending: 'pending',
                dash_queue_errors: 'errors',
                dash_queue_queued: 'Queued for background processing...',
                dash_queue_queued_n: 'images queued.',
                dash_backup_title: 'Backup retention',
                dash_backup_desc: 'Auto-delete TSO backups under uploads/tso-image-master/ (0 = disabled).',
                dash_backup_days: 'Keep backups (days)',
                dash_backup_max: 'Max total size (MB, 0 = unlimited)',
                dash_backup_purge: 'Purge now',
                dash_dup_title: 'Duplicate images',
                dash_dup_scan: 'Scan duplicates',
                dash_dup_none: 'No duplicate groups found.',
                dash_dup_groups: 'duplicate groups',
                dash_dup_wasted: 'Wasted space',
                dash_heavy_btn: 'Largest images',
                auto_skip_kb_label: 'Skip auto-optimize if WebP/AVIF ≤ (KB, 0 = off)',
                auto_fill_alt_label: 'Fill missing alt text on upload',
                fmt_avif: 'AVIF'
            },
            ca: {
                confirm_delete: 'Eliminar les imatges seleccionades? Aquesta acció és irreversible.',
                confirm_del_backup: 'Eliminar el backup? No podràs revertir la imatge.',
                confirm_revert: 'Revertir a l\'original? La versió optimitzada es perdrà.',
                confirm_delete_rogue: 'Eliminar',
                no_selection: 'Selecciona almenys una imatge.',
                processing: 'Processant...',
                save_ok: 'Guardat!',
                save_seo: 'Guardar SEO',
                edit_image: 'Editar',
                scan_rogue: 'Escanejar fitxers extra',
                delete_rogue: 'Eliminar seleccionats',
                scan_web: 'Escanejar tot el lloc',
                fix_selected: 'Reparar seleccionades',
                optimize_now: 'Optimitzar ara',
                rename_btn: 'Reanomenar fitxer',
                revert_btn: 'Revertir a l\'original',
                del_backup: 'Eliminar còpia de seguretat',
                backup_available: 'Còpia de seguretat disponible',
                repair_paths: 'Reparar paths trencats',
                loading_images: 'Carregant imatges...',
                loading_pdfs: 'Carregant PDFs...',
                loading_data: 'Carregant...',
                loading_modal: 'Carregant...',
                scanning_msg: 'Escanejant...',
                no_images: 'No s\'han trobat imatges.',
                no_orphans: 'No s\'han trobat imatges òrfenes.',
                no_rogue: 'No s\'han trobat fitxers extra.',
                no_pdfs: 'No s\'han trobat PDFs.',
                no_auto_history: 'No hi ha entrades d\'auto-optimització.',
                auto_hist_error: 'Error carregant l\'historial.',
                no_history: 'No s\'han trobat entrades.',
                history_empty: 'L\'historial és buit.',
                url_all_ok: 'Cap URL trencada trobada. Tot correcte!',
                url_no_results: 'Cap resultat.',
                url_click_select: 'Fes clic per seleccionar',
                url_content_label: 'URL al contingut (obsoleta)',
                url_correct_label: 'URL correcta (fitxer existent)',

                url_outdated_badge: 'Miniatura obsoleta (fitxer existent en nou format)',
                url_missing_badge: 'Fitxer absent — alternativa trobada',
                url_broken_badge: 'Fitxer absent — sense solució automàtica',
                url_fixing: 'Reparant...',
                select_removable: 'Seleccionar eliminables',
                removable_label: 'eliminables',
                remove_selected: 'Eliminar del contingut',
                url_removing: 'Eliminant...',
                url_removed_ok: 'Referències URL eliminades del contingut.',
                confirm_remove_urls: 'Eliminar les referències URL trencades del contingut? S\'esborrarà l\'etiqueta img o l\'enllaç.',
                url_click_select_remove: 'Fes clic per seleccionar i eliminar',
                url_no_fix_label: 'No s\'ha trobat cap alternativa. Selecciona i elimina la referència del contingut, o restaura el fitxer manualment.',
                posts_scanned: 'Continguts escanejats',
                broken_urls: 'URLs trencades',
                fixable_urls: 'Reparable automàticament',
                used_in: 'Usada a',
                orphan_confirmed: 'Orfe confirmat: no referenciat en cap lloc.',
                not_in_content: 'No trobat a post_content.',
                optimized_ok: 'Optimitzada!',
                optimized_no_replace: 'Optimitzada (sense reemplaçar).',
                converted_bigger: 'Convertida però més gran que l\'original.',
                optimizing_thumbs: 'Optimitzant miniatures...',
                thumbs_done: 'Miniatures processades.',
                reverted_ok: 'Revertida!',
                n_selected: 'seleccionades',
                optimize_done: 'estalviat',
                bulk_done: 'Fet',
                bulk_processing: 'Processant',
                pdf_compress_btn: 'Comprimir',
                pdf_timeout_msg: 'GhostScript ha trigat massa. Comprova el FTP — pot estar comprimit. Recarrega la pàgina.',
                gs_available: 'GhostScript disponible',
                gs_none: 'Cap motor de compressió disponible. Instal·la GhostScript.',
                auto_enabled: 'Auto-optimització ACTIVADA',
                auto_disabled: 'Auto-optimització desactivada',
                auto_desc_enabled: 'Les noves imatges es convertiran automàticament en pujar-les.',
                auto_desc_disabled: 'Activa per optimitzar automàticament.',
                repaired_msg: 'imatges reparades. Recarrega SEO & Noms per veure-les.',
                confirm_clean_30: 'Eliminar entrades de fa més de 30 dies?',
                confirm_clean_all: 'Eliminar TOT l\'historial? Aquesta acció és irreversible.',
                btn_scanning: 'Escanejant...',
                btn_processing: 'Processant...',
                btn_deleting: 'Eliminant...',
                btn_reverting: 'Revertint...',
                revert_rename_mismatch: 'No es pot revertir perquè has canviat el nom del fitxer després d\'optimitzar-lo. El backup encara existeix al servidor però ja no correspon al nom actual (per exemple, has eliminat el sufix -e172…). Solucions: torna a posar el nom original i llavors podràs revertir; elimina aquest backup i torna a optimitzar per generar-ne un de nou; o recupera el fitxer manualment des de la pestanya Imatges òrfenes → Fitxers extra a uploads.',
                btn_repairing: 'Reparant...',
                webp_ok: 'Suportat',
                webp_nok: 'No disponible (GD sense WebP)',
                enter_name: 'Introdueix el nou nom.',
                stat_total: 'Total d\'operacions',
                stat_saved: 'Espai alliberat',
                stat_current_size: 'Mida actual',
                stat_real_format: 'Format real',
                images_deleted: 'imatges eliminades.',
                all_rogue_deleted: 'Fitxers extra seleccionats eliminats!',
                featured: 'Imatge destacada',
                no_alt_text: 'Sense text alternatiu',
                url_fixed_ok: 'URLs reparades correctament.',
                mime_fix_btn: '🔧 Reparar tipus MIME incorrectes',
                mime_fixed: 'adjunts reparats.',
                mime_no_issues: 'Cap discrepància trobada. Tots els adjunts estan correctes.',
                scan_ghosts: 'Escanejar adjunts fantasma',
                no_ghosts: 'No s\'han trobat adjunts fantasma.',
                delete_ghosts: 'Eliminar seleccionats',
                confirm_delete_ghosts: 'Eliminar',
                deleted_msg: 'Eliminats',
                ghost_deleted_ok: 'eliminats correctament.',
                ghost_deleted_none: 'Cap eliminat.',
                errors_label: 'Errades:',
                pdf_engine_warn: '⚠ GhostScript no trobat. S\'està utilitzant Imagick.',
                tab_dashboard: 'Resum',
                dash_title: 'Resum del lloc',
                dash_desc: 'Visió ràpida de la salut de les imatges, motors disponibles i accions pendents.',
                dash_total_images: 'Imatges a la biblioteca',
                dash_missing_alt: 'Alt text absent',
                dash_backups: 'Còpies de seguretat TSO',
                dash_saved: 'Espai estalviat',
                dash_operations: 'operacions del plugin',
                dash_auto_on: 'Auto-optimitzar ACTIVAT',
                dash_auto_off: 'Auto-optimitzar DESACTIVAT',
                dash_alt_title: 'Imatges sense alt (o alt genèric)',
                dash_alt_desc: 'Suggereix alt des del títol o nom de fitxer. No sobreescriu un alt ja definit i útil.',
                dash_alt_used_only: 'Només usades en contingut',
                dash_alt_fill: 'Omplir alt seleccionades',
                dash_alt_suggested: 'Alt suggerit',
                dash_alt_used_in: 'Usada a',
                dash_alt_updated: 'texts alt actualitzats.',
                dash_alt_skipped: 'omeses (ja tenien alt).',
                dash_engine_gd: 'GD WebP',
                dash_engine_avif: 'GD AVIF',
                dash_engine_gs: 'GhostScript',
                dash_engine_imagick: 'Imagick',
                dash_go_orphans: 'Trobar òrfenes',
                dash_go_urls: 'Reparar URLs',
                dash_alt_all_ok: 'Totes les imatges tenen un alt útil.',
                dash_queue_title: 'Cua en segon pla',
                dash_queue_desc: 'Les optimitzacions massives s\'executen en segon pla via WP-Cron (5 imatges per lot).',
                dash_queue_cancel: 'Cancel·lar jobs pendents',
                dash_queue_empty: 'La cua és buida.',
                dash_queue_done: 'fetes',
                dash_queue_pending: 'pendents',
                dash_queue_errors: 'errors',
                dash_queue_queued: 'En cua per processar en segon pla...',
                dash_queue_queued_n: 'imatges en cua.',
                dash_backup_title: 'Retenció de còpies de seguretat',
                dash_backup_desc: 'Elimina automàticament les còpies TSO a uploads/tso-image-master/ (0 = desactivat).',
                dash_backup_days: 'Conservar còpies (dies)',
                dash_backup_max: 'Mida màxima total (MB, 0 = il·limitat)',
                dash_backup_purge: 'Purga ara',
                dash_dup_title: 'Imatges duplicades',
                dash_dup_scan: 'Escanejar duplicats',
                dash_dup_none: 'No s\'han trobat grups de duplicats.',
                dash_dup_groups: 'grups de duplicats',
                dash_dup_wasted: 'Espai malgastat',
                dash_heavy_btn: 'Imatges més pesades',
                auto_skip_kb_label: 'Ometre auto-optimitzar si WebP/AVIF ≤ (KB, 0 = off)',
                auto_fill_alt_label: 'Omplir alt absent en pujar',
                fmt_avif: 'AVIF'
            }
        };
        [ 'ca', 'es', 'en' ].forEach(function(l) {
            $.extend(I18N_STATIC[l], WP[l]);
        });
    })();

    function initLanguageSwitcher() {
        captureCatalanOriginals();
        var defaultLang = detectDefaultLanguage();
        var savedLang = localStorage.getItem('tsoimma_ui_lang');
        var lang = savedLang || defaultLang;
        setLanguage(lang);

        $(document).on('click', '.imp-lang-btn', function() {
            setLanguage($(this).data('lang'));
        });
    }

    function detectDefaultLanguage() {
        var wpLang = document.documentElement.getAttribute('lang') || '';
        wpLang = wpLang.toLowerCase();
        if (wpLang.indexOf('ca') === 0) return 'ca';
        if (wpLang.indexOf('es') === 0) return 'es';
        return 'en';
    }

    function setLanguage(lang) {
        if (['ca', 'es', 'en'].indexOf(lang) === -1) lang = 'ca';
        localStorage.setItem('tsoimma_ui_lang', lang);
        syncRuntimeStrings(lang);
        $('.imp-lang-btn').removeClass('active');
        $('.imp-lang-btn[data-lang="' + lang + '"]').addClass('active');
        applyTranslations(lang);
        refreshDynamicI18nBits();
        refreshImpCustomSelects();
    }

    function uiText(key, fallback) {
        var lang = localStorage.getItem('tsoimma_ui_lang') || detectDefaultLanguage();
        var dict = I18N_STATIC[lang] || {};
        if (dict[key]) return dict[key];
        if (lang === 'ca') return L[key] || fallback || ((I18N_STATIC.en && I18N_STATIC.en[key]) || '');
        return (I18N_STATIC.en && I18N_STATIC.en[key]) || L[key] || fallback;
    }

    function syncRuntimeStrings(lang) {
        var dict = I18N_STATIC[lang] || {};
        var key;
        for (key in L) {
            if (Object.prototype.hasOwnProperty.call(L, key)) delete L[key];
        }
        // BASE_L follows WordPress admin locale; dict (plugin UI language) must override every key we define.
        $.extend(L, BASE_L, dict);
    }

    function applyTranslations(lang) {
        var dict = I18N_STATIC[lang] || {};
        $('[data-i18n]').each(function() {
            var key = $(this).data('i18n');
            var text = (lang === 'ca')
                ? (CA_ORIGINAL.text[key] || dict[key] || L[key])
                : (dict[key] || L[key]);
            if (text) $(this).html(text);
        });
        $('[data-i18n-placeholder]').each(function() {
            var key = $(this).data('i18n-placeholder');
            var ph = (lang === 'ca')
                ? (CA_ORIGINAL.placeholder[key] || dict[key] || L[key])
                : (dict[key] || L[key]);
            if (ph) $(this).attr('placeholder', ph);
        });
        $('[data-i18n-html]').each(function() {
            var key = $(this).data('i18n-html');
            var html = (lang === 'ca')
                ? (CA_ORIGINAL.html[key] || dict[key] || L[key])
                : (dict[key] || L[key]);
            if (html) $(this).html(html);
        });
    }

    function captureCatalanOriginals() {
        $('[data-i18n]').each(function() {
            var key = $(this).data('i18n');
            if (!CA_ORIGINAL.text[key]) CA_ORIGINAL.text[key] = $(this).html();
        });
        $('[data-i18n-placeholder]').each(function() {
            var key = $(this).data('i18n-placeholder');
            if (!CA_ORIGINAL.placeholder[key]) CA_ORIGINAL.placeholder[key] = $(this).attr('placeholder') || '';
        });
        $('[data-i18n-html]').each(function() {
            var key = $(this).data('i18n-html');
            if (!CA_ORIGINAL.html[key]) CA_ORIGINAL.html[key] = $(this).html();
        });
    }

    function refreshDynamicI18nBits() {
        // Buttons whose text is mutated after async actions.
        $('#imp-fix-orphan-meta').text(uiText('repair_paths', '🔧 Repair broken paths'));
        $('#imp-fix-mime-mismatch').text(uiText('mime_fix_btn', '🔧 Fix incorrect mime types'));
        $('#imp-scan-ghosts').text('🔍 ' + uiText('scan_ghosts', 'Scan ghost attachments'));
        $('#imp-delete-ghosts').text('🗑 ' + uiText('delete_ghosts', 'Delete selected'));
        $('#imp-scan-rogue').text(uiText('scan_rogue', '🔍 Scan extra upload files'));
        $('#imp-save-rename').text(uiText('rename_btn', '✏️ Rename file'));

        // Re-render result messages if they are visible and previously stored.
        refreshStoredResult('#imp-fix-orphan-result');
        refreshStoredResult('#imp-fix-mime-result');
        refreshStoredResult('#imp-ghost-scan-result');
        refreshStoredResult('#imp-ghost-delete-result');
        if (typeof refreshUrlFixerUi === 'function') refreshUrlFixerUi();
        if (typeof window.tsoimmaRefreshRogueUi === 'function') window.tsoimmaRefreshRogueUi();
        // Re-render PDF rows/badges in the selected UI language.
        if ($('#tab-pdf').is(':visible')) loadPdfs();
        // Re-render history tables/stats in current UI language.
        if ($('#tab-history').is(':visible')) {
            loadHistoryStats('#imp-history-stats');
            loadHistory();
        }
        if ($('#tab-auto').is(':visible')) {
            loadHistoryStats('#imp-auto-stats');
            loadAutoHistory();
        }
    }

    function refreshStoredResult(selector) {
        var $el = $(selector);
        var kind = $el.data('kind');
        if (!kind) return;
        var count = parseInt($el.data('count') || 0, 10);

        if (kind === 'repaired') {
            $el.text('✓ ' + count + ' ' + uiText('repaired_msg', 'images repaired.'));
        } else if (kind === 'mime_fixed') {
            $el.text('✓ ' + count + ' ' + uiText('mime_fixed', 'attachments repaired.'));
        } else if (kind === 'mime_no_issues') {
            $el.text('✓ ' + uiText('mime_no_issues', 'No issues found. All attachments are correct.'));
        } else if (kind === 'ghost_none') {
            $el.text('✓ ' + uiText('no_ghosts', 'No ghost attachments found.'));
        } else if (kind === 'ghost_found') {
            $el.text(count + ' ' + (count > 1 ? uiText('ghost_found_plural', 'ghost attachments found.') : uiText('ghost_found_singular', 'ghost attachment found.')));
        } else if (kind === 'ghost_deleted') {
            $el.text('✓ ' + count + ' ' + uiText('ghost_deleted_ok', 'deleted successfully.'));
        }
    }

    // ================================================================
    // Hover Preview
    // ================================================================
    function initHoverPreview() {
        var $preview = $('<div id="imp-img-hover-preview"><img src="" alt=""><div class="imp-hover-label"></div></div>');
        $('body').append($preview);
        var $pImg  = $preview.find('img');
        var $label = $preview.find('.imp-hover-label');
        var MARGIN = 16;

        $(document).on('mouseenter', '#imp-modal-img', function(e) {
            var $modalImg = $(this);
            var fullUrl   = $modalImg.attr('data-full-url') || $modalImg.attr('src') || '';
            var fname     = $('#imp-modal-title-head .imp-modal-fname').text() || '';
            if (!fullUrl) return;
            var ts      = Date.now();
            var sep     = fullUrl.indexOf('?') === -1 ? '?' : '&';
            var noCache = fullUrl + sep + '_hov=' + ts;
            $preview.removeClass('visible').hide();
            $label.text(fname);
            var tmpImg = new Image();
            tmpImg.onload = function() {
                $pImg.attr('src', noCache);
                positionPreview(e);
                $preview.show();
                requestAnimationFrame(function() { $preview.addClass('visible'); });
            };
            tmpImg.src = noCache;
        });

        $(document).on('click', '.imp-modal-close, .imp-modal-overlay', function() {
            $preview.removeClass('visible').hide();
            $pImg.attr('src', '');
        });
        $(document).on('mouseleave', '#imp-modal-img', function() {
            $preview.removeClass('visible');
            setTimeout(function() { $preview.hide(); }, 150);
        });
        $(document).on('mousemove', '#imp-modal-img', function(e) {
            if ($preview.is(':visible')) positionPreview(e);
        });

        function positionPreview(e) {
            var pw = $preview.outerWidth()  || 300;
            var ph = $preview.outerHeight() || 300;
            var ww = $(window).width();
            var wh = $(window).height();
            var x  = e.clientX + MARGIN;
            var y  = e.clientY - ph / 2;
            if (x + pw > ww - MARGIN) x = e.clientX - pw - MARGIN;
            if (y < MARGIN) y = MARGIN;
            if (y + ph > wh - MARGIN) y = wh - ph - MARGIN;
            $preview.css({ left: Math.max(0, x) + 'px', top: Math.max(0, y) + 'px' });
        }
    }

    // ================================================================
    // WebP check
    // ================================================================
    function checkWebP() {
        if (TSOIMMA.webp_ok === '1') {
            $('#imp-webp-status').html(uiText('webp_label', 'WebP') + ': <span class="ok">✓ ' + uiText('webp_ok', 'Supported') + '</span>');
        } else {
            $('#imp-webp-status').html(uiText('webp_label', 'WebP') + ': <span class="nok">✗ ' + uiText('webp_nok', 'Not available') + '</span>');
            $('#imp-format, #imp-modal-format').find('option[value="webp"]').prop('disabled', true);
            refreshImpCustomSelects('#imp-format, #imp-modal-format');
        }
    }

    // Custom dropdowns (PHP markup + JS bind; native menus unreadable on Windows).
    function closeImpCustomSelects() {
        $('.imp-csel.is-open').removeClass('is-open')
            .find('.imp-csel-trigger').attr('aria-expanded', 'false');
    }

    function refreshImpCustomSelects(context) {
        var $wraps = context ? $(context).closest('.imp-csel').add($(context).filter('.imp-csel')) : $('.imp-csel');
        if (context && !$(context).closest('.imp-csel').length && $(context).hasClass('imp-csel')) {
            $wraps = $(context);
        }
        $wraps.each(function() {
            var $w = $(this);
            var api = $w.data('impCselApi');
            if (api) {
                api.rebuild();
                api.sync();
            } else {
                bindImpCustomSelect($w);
            }
        });
    }

    function initImpCustomSelects() {
        $('.imp-csel').each(function() {
            bindImpCustomSelect($(this));
        });
        $(document).on('click.impCsel', function() {
            closeImpCustomSelects();
        });
        $(document).on('keydown.impCsel', function(e) {
            if (e.key === 'Escape') {
                closeImpCustomSelects();
            }
        });
    }

    function bindImpCustomSelect($wrap) {
        if ($wrap.data('impCselBound')) {
            return;
        }
        var $select = $wrap.find('select.imp-csel-native');
        var $label  = $wrap.find('.imp-csel-label');
        var $list   = $wrap.find('.imp-csel-list');

        function rebuildList() {
            $list.empty();
            $select.find('option').each(function() {
                var $opt = $(this);
                var $li  = $('<li role="option" tabindex="-1"></li>');
                $li.text($opt.text()).attr('data-value', $opt.val());
                if ($opt.prop('disabled')) {
                    $li.addClass('is-disabled');
                }
                if ($opt.prop('selected')) {
                    $li.addClass('is-selected');
                }
                $list.append($li);
            });
        }

        function syncFromSelect() {
            $label.text($select.find('option:selected').first().text());
            $list.find('[role="option"]').removeClass('is-selected');
            $list.find('[role="option"]').each(function() {
                if (String($(this).attr('data-value')) === String($select.val())) {
                    $(this).addClass('is-selected');
                }
            });
        }

        $wrap.find('.imp-csel-trigger').on('click.impCsel', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var wasOpen = $wrap.hasClass('is-open');
            closeImpCustomSelects();
            if (!wasOpen) {
                $wrap.addClass('is-open');
                $(this).attr('aria-expanded', 'true');
            }
        });

        $list.on('click.impCsel', '[role="option"]:not(.is-disabled)', function(e) {
            e.stopPropagation();
            $select.val($(this).attr('data-value')).trigger('change');
            $wrap.removeClass('is-open');
            syncFromSelect();
        });

        $select.on('change.impCsel', syncFromSelect);

        $wrap.data('impCselBound', 1);
        $wrap.data('impCselApi', { rebuild: rebuildList, sync: syncFromSelect });

        rebuildList();
        syncFromSelect();
    }

    // ================================================================
    // Dashboard
    // ================================================================
    function initDashboard() {
        loadDashboardOverview();
        loadMissingAlt();

        $(document).on('click', '.imp-tab[data-tab="dashboard"]', function() {
            loadDashboardOverview();
            loadMissingAlt();
        });

        $('#imp-alt-used-only').on('change', function() {
            state.dashboard.altPage = 1;
            state.dashboard.altSelected.clear();
            loadMissingAlt();
        });

        $('#imp-alt-select-all').on('click', function() {
            $('#imp-alt-grid .imp-alt-check').prop('checked', true).each(function() {
                state.dashboard.altSelected.add(parseInt($(this).data('id'), 10));
            });
        });

        $('#imp-alt-deselect').on('click', function() {
            $('#imp-alt-grid .imp-alt-check').prop('checked', false);
            state.dashboard.altSelected.clear();
        });

        $(document).on('change', '.imp-alt-check', function() {
            var id = parseInt($(this).data('id'), 10);
            if ($(this).is(':checked')) state.dashboard.altSelected.add(id);
            else state.dashboard.altSelected.delete(id);
        });

        $('#imp-alt-bulk-fill').on('click', function() {
            if (!state.dashboard.altSelected.size) {
                alert(uiText('no_selection', 'Select at least one image.'));
                return;
            }
            var $btn = $(this);
            var $res = $('#imp-alt-bulk-result');
            $btn.prop('disabled', true).text(uiText('processing', 'Processing...'));
            ajax('tso_im_bulk_fill_alt', {
                ids: Array.from(state.dashboard.altSelected),
                source: 'suggested'
            }, function(data) {
                $btn.prop('disabled', false).text(uiText('dash_alt_fill', 'Fill alt for selected'));
                var msg = '✓ ' + (data.updated || 0) + ' ' + uiText('dash_alt_updated', 'alt texts updated.');
                if (data.skipped) {
                    msg += ' ' + data.skipped + ' ' + uiText('dash_alt_skipped', 'skipped (already had alt).');
                }
                $res.show().css('color', 'var(--imp-success)').text(msg);
                state.dashboard.altSelected.clear();
                loadDashboardOverview();
                loadMissingAlt();
            }, function(err) {
                $btn.prop('disabled', false).text(uiText('dash_alt_fill', 'Fill alt for selected'));
                $res.show().css('color', 'var(--imp-danger)').text(err);
            });
        });

        $(document).on('click', '.imp-dash-jump', function(e) {
            e.preventDefault();
            var tab = $(this).data('jump-tab');
            if (!tab) return;
            $('.imp-tab[data-tab="' + tab + '"]').trigger('click');
            if (tab === 'optimize') {
                state.opt.page = 1;
                state.opt.search = '';
                loadOptImages();
            }
        });

        $('#imp-queue-cancel').on('click', function() {
            ajax('tso_im_cancel_queue', {}, function(data) {
                renderQueueStatus(data);
            });
        });

        $('#imp-save-backup-retention').on('click', function() {
            ajax('tso_im_save_backup_retention', {
                days: $('#imp-backup-days').val(),
                max_mb: $('#imp-backup-max-mb').val()
            }, function() {
                $('#imp-backup-retention-msg').show().css('color', 'var(--imp-success)').text(uiText('save_ok', 'Saved!'));
            });
        });

        $('#imp-purge-backups-now').on('click', function() {
            ajax('tso_im_purge_backups_now', {}, function(data) {
                var msg = '✓ ' + (data.deleted || 0) + ' deleted, ' + (data.freed_h || '0 B') + ' freed.';
                $('#imp-backup-retention-msg').show().css('color', 'var(--imp-success)').text(msg);
                loadDashboardOverview();
            });
        });

        $('#imp-scan-duplicates').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#imp-duplicates-result').html('<div class="imp-loading">' + uiText('scanning_msg', 'Scanning...') + '</div>');
            $('#imp-duplicates-list').empty();
            ajax('tso_im_scan_duplicates', { limit: 500 }, function(data) {
                $btn.prop('disabled', false);
                if (!data.group_count) {
                    $('#imp-duplicates-result').html('<p style="color:var(--imp-success);">✓ ' + uiText('dash_dup_none', 'No duplicate groups found.') + '</p>');
                    return;
                }
                $('#imp-duplicates-result').html(
                    '<p>' + data.group_count + ' ' + uiText('dash_dup_groups', 'duplicate groups') +
                    ' · ' + uiText('dash_dup_wasted', 'Wasted space') + ': ' + escHtml(data.wasted_h || '0 B') + '</p>'
                );
                var $list = $('#imp-duplicates-list');
                (data.groups || []).slice(0, 20).forEach(function(group) {
                    var html = '<div class="imp-dup-group"><strong>Hash ' + escHtml(group.hash.slice(0, 8)) + '… (' + group.items.length + ')</strong><ul>';
                    group.items.forEach(function(item) {
                        html += '<li>#' + item.id + ' ' + escHtml(item.filename) + ' · ' + item.used_in + ' ' + uiText('dash_alt_used_in', 'Used in') + '</li>';
                    });
                    html += '</ul></div>';
                    $list.append(html);
                });
            }, function(err) {
                $btn.prop('disabled', false);
                $('#imp-duplicates-result').html('<div class="imp-error">' + escHtml(err) + '</div>');
            });
        });

        loadBackupRetention();
    }

    function renderQueueStatus(queue) {
        var $el = $('#imp-queue-status');
        if (!$el.length) return;
        if (!queue.total) {
            $el.html('<span style="color:var(--imp-text-muted);">' + uiText('dash_queue_empty', 'Queue is empty.') + '</span>');
            $('#imp-queue-cancel').prop('disabled', true);
            return;
        }
        $el.html(
            '<span>' + (queue.done || 0) + '/' + queue.total + ' ' + uiText('dash_queue_done', 'done') +
            ' · ' + (queue.pending || 0) + ' ' + uiText('dash_queue_pending', 'pending') +
            (queue.errors ? ' · ' + queue.errors + ' ' + uiText('dash_queue_errors', 'errors') : '') + '</span>'
        );
        $('#imp-queue-cancel').prop('disabled', !(queue.pending > 0));
    }

    function loadBackupRetention() {
        ajax('tso_im_get_backup_retention', {}, function(data) {
            $('#imp-backup-days').val(data.days || 0);
            $('#imp-backup-max-mb').val(data.max_mb || 0);
        });
    }

    function pollQueueStatus() {
        if (!$('#tab-dashboard').hasClass('active')) return;
        ajax('tso_im_get_queue_status', {}, function(data) {
            renderQueueStatus(data);
            if (data.running) {
                setTimeout(pollQueueStatus, 4000);
            } else if (data.total > 0) {
                loadDashboardOverview();
            }
        });
    }

    function loadDashboardOverview() {
        var $stats = $('#imp-dashboard-stats');
        var $engines = $('#imp-dashboard-engines');
        $stats.html('<div class="imp-loading">' + uiText('loading_data', 'Loading...') + '</div>');
        ajax('tso_im_get_dashboard_overview', {}, function(data) {
            var warnAlt = (data.missing_alt || 0) > 0;
            var cards = [
                { label: uiText('dash_total_images', 'Images in library'), val: data.total_images || 0, sub: '', cls: '' },
                { label: uiText('dash_missing_alt', 'Missing alt text'), val: data.missing_alt || 0, sub: warnAlt ? uiText('dash_alt_title', 'Review below') : '✓', cls: warnAlt ? 'is-warn is-clickable imp-dash-jump' : 'is-ok', jump: 'dashboard' },
                { label: uiText('dash_backups', 'TSO backups'), val: data.backup_count || 0, sub: data.backup_bytes_h || '0 B', cls: (data.backup_count || 0) > 0 ? 'is-warn' : 'is-ok' },
                { label: uiText('dash_saved', 'Space saved'), val: data.total_saved_h || '0 B', sub: (data.total_operations || 0) + ' ' + uiText('dash_operations', 'operations'), cls: 'is-ok' },
                { label: data.auto_enabled ? uiText('dash_auto_on', 'Auto-optimize ON') : uiText('dash_auto_off', 'Auto-optimize OFF'), val: (data.auto_format || 'webp').toUpperCase(), sub: '', cls: data.auto_enabled ? 'is-ok is-clickable imp-dash-jump' : '', jump: 'auto' }
            ];
            $stats.empty();
            cards.forEach(function(card) {
                var attrs = card.jump ? ' data-jump-tab="' + card.jump + '"' : '';
                $stats.append(
                    '<div class="imp-stat-card ' + (card.cls || '') + '"' + attrs + '>' +
                    '<span class="imp-stat-label">' + escHtml(card.label) + '</span>' +
                    '<span class="imp-stat-val">' + escHtml(String(card.val)) + '</span>' +
                    (card.sub ? '<span class="imp-stat-sub">' + escHtml(card.sub) + '</span>' : '') +
                    '</div>'
                );
            });

            var engines = data.engines || {};
            var pills = [
                { key: 'gd_webp', label: uiText('dash_engine_gd', 'GD WebP') },
                { key: 'gd_avif', label: uiText('dash_engine_avif', 'GD AVIF') },
                { key: 'ghostscript', label: uiText('dash_engine_gs', 'GhostScript') },
                { key: 'imagick', label: uiText('dash_engine_imagick', 'Imagick') }
            ];
            $engines.empty();
            pills.forEach(function(pill) {
                var ok = !!engines[pill.key];
                $engines.append('<span class="imp-engine-pill ' + (ok ? 'ok' : 'nok') + '">' + (ok ? '✓' : '✗') + ' ' + escHtml(pill.label) + '</span>');
            });

            renderQueueStatus(data.queue || {});
            if (data.queue && data.queue.running) {
                pollQueueStatus();
            }
        }, function(err) {
            $stats.html('<div class="imp-error">' + escHtml(err) + '</div>');
        });
    }

    function loadMissingAlt() {
        var $grid = $('#imp-alt-grid');
        $grid.html('<div class="imp-loading">' + uiText('loading_data', 'Loading...') + '</div>');
        ajax('tso_im_get_missing_alt', {
            page: state.dashboard.altPage,
            per_page: 35,
            used_only: $('#imp-alt-used-only').is(':checked') ? 1 : 0
        }, function(data) {
            $grid.empty();
            if (!data.items || !data.items.length) {
                $grid.html('<p style="color:var(--imp-success);padding:12px;">✓ ' + uiText('dash_alt_all_ok', 'All images have useful alt text.') + '</p>');
                $('#imp-alt-pagination').empty();
                return;
            }
            data.items.forEach(function(item) {
                var checked = state.dashboard.altSelected.has(item.id) ? ' checked' : '';
                $grid.append(
                    '<div class="imp-alt-row">' +
                    '<input type="checkbox" class="imp-alt-check" data-id="' + item.id + '"' + checked + '>' +
                    '<img src="' + escHtml(item.thumb || '') + '" alt="">' +
                    '<div><div class="imp-alt-filename">' + escHtml(item.filename || '') + '</div>' +
                    '<div>' + escHtml(item.title || '') + '</div></div>' +
                    '<div><span class="imp-alt-suggest">' + escHtml(item.suggested_alt || '') + '</span></div>' +
                    '<div class="imp-alt-used">' + (item.used_in_count || 0) + ' ' + uiText('dash_alt_used_in', 'Used in') + '</div>' +
                    '</div>'
                );
            });
            renderAltPagination(data.page, data.total_pages);
        }, function(err) {
            $grid.html('<div class="imp-error">' + escHtml(err) + '</div>');
        });
    }

    function renderAltPagination(page, totalPages) {
        var $p = $('#imp-alt-pagination');
        $p.empty();
        if (totalPages <= 1) return;
        for (var i = 1; i <= totalPages; i++) {
            var $btn = $('<button class="imp-btn imp-btn-ghost imp-btn-sm" data-page="' + i + '"></button>').text(i);
            if (i === page) $btn.addClass('active');
            $p.append($btn);
        }
        $p.off('click', 'button').on('click', 'button', function() {
            state.dashboard.altPage = parseInt($(this).attr('data-page'), 10);
            loadMissingAlt();
        });
    }

    // ================================================================
    // Tabs
    // ================================================================
    function initTabs() {
        $(document).on('click', '.imp-tab', function() {
            var tab = $(this).data('tab');
            $('.imp-tab').removeClass('active');
            $(this).addClass('active');
            $('.imp-tab-content').removeClass('active');
            $('#tab-' + tab).addClass('active');
        });
    }

    // ================================================================
    // Quality sliders
    // ================================================================
    function initQualitySliders() {
        $('#imp-quality').on('input', function() { $('#imp-quality-val').text($(this).val()); });
        $('#imp-modal-quality').on('input', function() { $('#imp-modal-quality-val').text($(this).val()); });
    }

    // ================================================================
    // Search with debounce
    // ================================================================
    function initSearch() {
        var timer;
        $('#imp-search-opt').on('input', function() {
            clearTimeout(timer);
            var val = $(this).val();
            timer = setTimeout(function() {
                state.opt.search = val;
                state.opt.page = 1;
                state.opt.selected.clear();
                loadOptImages();
            }, 400);
        });
        $('#imp-search-seo').on('input', function() {
            clearTimeout(timer);
            var val = $(this).val();
            timer = setTimeout(function() {
                state.seo.search = val;
                state.seo.page = 1;
                loadSeoImages();
            }, 400);
        });
    }

    // ================================================================
    // OPTIMIZE TAB
    // ================================================================
    function initOptimizeTab() {
        $('#imp-select-all').on('click', function() {
            $('#imp-images-grid .imp-image-card').each(function() {
                var id = $(this).data('id');
                state.opt.selected.add(id);
                $(this).addClass('selected').find('.imp-card-checkbox').addClass('checked');
            });
            updateSelectedCount();
        });
        $('#imp-deselect-all').on('click', function() {
            state.opt.selected.clear();
            $('#imp-images-grid .imp-image-card').removeClass('selected');
            $('#imp-images-grid .imp-card-checkbox').removeClass('checked');
            updateSelectedCount();
        });
        $(document).on('click', '#imp-images-grid .imp-image-card', function(e) {
            if ($(e.target).hasClass('imp-card-edit-btn')) return;
            var id = $(this).data('id');
            if (state.opt.selected.has(id)) {
                state.opt.selected.delete(id);
                $(this).removeClass('selected').find('.imp-card-checkbox').removeClass('checked');
            } else {
                state.opt.selected.add(id);
                $(this).addClass('selected').find('.imp-card-checkbox').addClass('checked');
            }
            updateSelectedCount();
        });
        $(document).on('click', '#imp-images-grid .imp-card-edit-btn', function(e) {
            e.stopPropagation();
            openModal($(this).data('id'), 'optimize');
        });
        $('#imp-bulk-optimize').on('click', function() {
            var ids = Array.from(state.opt.selected);
            if (!ids.length) { alert(L.no_selection || 'Select at least one image.'); return; }
            bulkOptimize(ids);
        });
        $(document).on('click', '#imp-clear-log', function() {
            $('#imp-log-content').empty();
            $('#imp-opt-log').hide();
        });
    }

    function updateSelectedCount() {
        var n = state.opt.selected.size;
        $('#imp-selected-count').text(n + ' ' + (L.n_selected || 'selected'));
        $('#imp-bulk-optimize').prop('disabled', n === 0);
    }

    function loadOptImages() {
        var grid = $('#imp-images-grid');
        grid.html('<div class="imp-loading">' + (L.loading_images || 'Loading images...') + '</div>');
        ajax('tso_im_get_images', {
            page: state.opt.page, per_page: state.opt.perPage,
            search: state.opt.search, sort: 'filesize'
        }, function(data) {
            state.opt.totalPages = data.total_pages;
            renderOptGrid(data.items);
            renderPagination('#imp-opt-pagination', state.opt.page, data.total_pages, function(p) {
                state.opt.page = p; loadOptImages();
            });
        }, function(err) {
            grid.html('<div class="imp-loading">' + (L.error_prefix || 'Error: ') + err + '</div>');
        });
    }

    function renderOptGrid(items) {
        var grid = $('#imp-images-grid');
        grid.empty();
        if (!items.length) {
            grid.html('<div class="imp-loading">' + (L.no_images || 'No images found.') + '</div>');
            return;
        }
        items.forEach(function(item) {
            var sel     = state.opt.selected.has(item.id) ? 'selected' : '';
            var checked = sel ? 'checked' : '';
            var ext     = item.mime.replace('image/', '').toUpperCase();
            var card    = $('<div class="imp-image-card ' + sel + '" data-id="' + item.id + '"></div>');
            card.append('<div class="imp-card-checkbox ' + checked + '"></div>');
            card.append('<img class="imp-card-thumb" src="' + escHtml(cacheBustUrl(item.thumb || '', item.id)) + '" alt="' + escHtml(item.title) + '" loading="lazy">');
            card.append(
                '<div class="imp-card-info">' +
                '<div class="imp-card-name">' + escHtml(item.filename) + '</div>' +
                '<div class="imp-card-meta">' +
                '<span class="imp-card-size">' + escHtml(item.filesize) + '</span>' +
                '<span class="imp-card-format">' + escHtml(ext) + '</span>' +
                '</div></div>'
            );
            card.append('<button class="imp-card-edit-btn" data-id="' + item.id + '">' + (L.edit_image || '✏ Edit') + '</button>');
            grid.append(card);
        });
        updateSelectedCount();
    }

    function bulkOptimize(ids) {
        var format  = $('#imp-format').val();
        var quality = $('#imp-quality').val();
        var replace = $('#imp-replace').is(':checked') ? 1 : 0;
        var total   = ids.length;

        if (total > 2) {
            $('#imp-bulk-progress').show();
            $('#imp-progress-fill').css('width', '0%');
            $('#imp-progress-text').text(uiText('dash_queue_queued', 'Queued for background processing...'));
            $('#imp-opt-log').show();
            $('#imp-bulk-optimize').prop('disabled', true);
            ajax('tso_im_enqueue_optimize_queue', {
                ids: ids, format: format, quality: quality, replace: replace
            }, function(data) {
                $('#imp-bulk-optimize').prop('disabled', false);
                $('#imp-bulk-progress').hide();
                addLog('info', '✓ ' + total + ' ' + uiText('dash_queue_queued_n', 'images queued.'));
                renderQueueStatus(data);
                $('.imp-tab[data-tab="dashboard"]').trigger('click');
                pollQueueStatus();
            }, function(err) {
                $('#imp-bulk-optimize').prop('disabled', false);
                addLog('err', err);
            });
            return;
        }

        var done    = 0;
        $('#imp-bulk-progress').show();
        $('#imp-progress-fill').css('width', '0%');
        $('#imp-progress-text').text((L.bulk_processing || 'Processing') + ' 0/' + total + '...');
        $('#imp-opt-log').show();
        $('#imp-bulk-optimize').prop('disabled', true);

        function processNext() {
            if (done >= total) {
                $('#imp-bulk-optimize').prop('disabled', false);
                $('#imp-bulk-progress').hide();
                addLog('info', '✓ ' + (L.bulk_done || 'Done') + ': ' + done + '/' + total);
                loadOptImages();
                return;
            }
            var id = ids[done];
            ajax('tso_im_optimize_image', {
                attachment_id: id, format: format, quality: quality, replace: replace
            }, function(data) {
                done++;
                var pct = Math.round(done / total * 100);
                $('#imp-progress-fill').css('width', pct + '%');
                $('#imp-progress-text').text((L.bulk_processing || 'Processing') + ' ' + done + '/' + total + '...');
                if (data.replaced) {
                    addLog('ok', '✓ ID ' + id + ' \u2192 ' + data.format.toUpperCase() + ' | ' + data.savings_pct + '% ' + (L.optimize_done || 'saved'));
                } else {
                    addLog('ok', '✓ ID ' + id + ' \u2192 ' + (L.optimized_no_replace || 'optimized'));
                }
                processNext();
            }, function(err) {
                done++;
                addLog('err', '✗ ID ' + id + ' \u2192 ' + err);
                processNext();
            });
        }
        processNext();
    }

    // ================================================================
    // ORPHANS TAB
    // ================================================================
    function initOrphansTab() {
        $('#imp-scan-orphans').on('click', function() {
            var limit = parseInt($('#imp-orphan-limit').val(), 10);
            scanOrphans(limit, 0, []);
        });
        $(document).on('click', '#imp-orphans-grid .imp-image-card', function(e) {
            if ($(e.target).hasClass('imp-card-edit-btn')) return;
            var id = $(this).data('id');
            if (state.orphanSelected.has(id)) {
                state.orphanSelected.delete(id);
                $(this).removeClass('selected').find('.imp-card-checkbox').removeClass('checked');
            } else {
                state.orphanSelected.add(id);
                $(this).addClass('selected').find('.imp-card-checkbox').addClass('checked');
            }
            updateOrphanCount();
        });
        $(document).on('click', '#imp-orphans-grid .imp-card-edit-btn', function(e) {
            e.stopPropagation();
            openModal($(this).data('id'), 'seo');
        });
        $('#imp-orphan-select-all').on('click', function() {
            state.orphanSelected.clear();
            state.orphans.found.forEach(function(o) { state.orphanSelected.add(o.id); });
            $('#imp-orphans-grid .imp-image-card').addClass('selected').find('.imp-card-checkbox').addClass('checked');
            updateOrphanCount();
        });
        $('#imp-orphan-deselect').on('click', function() {
            state.orphanSelected.clear();
            $('#imp-orphans-grid .imp-image-card').removeClass('selected').find('.imp-card-checkbox').removeClass('checked');
            updateOrphanCount();
        });
        $('#imp-delete-orphans').on('click', function() {
            var ids = Array.from(state.orphanSelected);
            if (!ids.length) { alert(L.no_selection || 'Select at least one image.'); return; }
            if (!confirm(L.confirm_delete || 'Delete selected images? This cannot be undone.')) return;
            ajax('tso_im_delete_images', { ids: ids }, function(data) {
                alert('✓ ' + data.deleted.length + ' ' + (L.images_deleted || 'images deleted.'));
                state.orphans.found = state.orphans.found.filter(function(o) { return ids.indexOf(o.id) === -1; });
                state.orphanSelected.clear();
                renderOrphansGrid();
            }, function(err) { alert((L.error_prefix || 'Error: ') + err); });
        });
    }

    function scanOrphans(limit, offset, accumulated) {
        $('#imp-orphans-loading').show();
        $('#imp-orphans-result').hide();
        ajax('tso_im_find_orphans', { limit: limit, offset: offset }, function(data) {
            accumulated = accumulated.concat(data.orphans);
            var scanned = offset + data.total_scanned;
            $('#imp-orphans-progress-text').text((L.scanning_msg || 'Scanning') + ' ' + scanned + '/' + data.total_images + '...');
            if (limit > 0 && scanned < data.total_images && data.total_scanned === limit) {
                setTimeout(function() { scanOrphans(limit, scanned, accumulated); }, 100);
            } else {
                $('#imp-orphans-loading').hide();
                state.orphans.found = accumulated;
                state.orphanSelected.clear();
                renderOrphansGrid();
                $('#imp-orphans-result').show();
            }
        }, function(err) { $('#imp-orphans-loading').hide(); alert((L.error_prefix || 'Error: ') + err); });
    }

    function renderOrphansGrid() {
        var grid = $('#imp-orphans-grid');
        grid.empty();
        var found = state.orphans.found;
        if (!found.length) {
            grid.html('<div class="imp-loading" style="color:var(--imp-success)">✓ ' + (L.no_orphans || 'No orphaned images found.') + '</div>');
            updateOrphanCount();
            return;
        }
        found.forEach(function(item) {
            var ext  = (item.mime || '').replace('image/', '').toUpperCase();
            var card = $('<div class="imp-image-card" data-id="' + item.id + '"></div>');
            card.append('<div class="imp-card-checkbox"></div>');
            card.append('<div class="imp-card-badges"><span class="imp-badge-orphan">ORPHAN</span></div>');
            card.append('<img class="imp-card-thumb" src="' + escHtml(cacheBustUrl(item.thumb || item.url || '', item.id)) + '" alt="' + escHtml(item.title) + '" loading="lazy">');
            card.append(
                '<div class="imp-card-info">' +
                '<div class="imp-card-name">' + escHtml(item.filename) + '</div>' +
                '<div class="imp-card-meta">' +
                '<span class="imp-card-size">' + escHtml(item.filesize_h) + '</span>' +
                '<span class="imp-card-format">' + escHtml(ext) + '</span>' +
                '</div></div>'
            );
            card.append('<button class="imp-card-edit-btn" data-id="' + item.id + '">' + (L.edit_image || '✏ Edit') + '</button>');
            grid.append(card);
        });
        updateOrphanCount();
    }

    function updateOrphanCount() {
        var n   = state.orphans.found.length;
        var sel = state.orphanSelected.size;
        $('#imp-orphan-count').text(n + ' | ' + sel + ' ' + (L.n_selected || 'selected'));
        $('#imp-delete-orphans').prop('disabled', sel === 0);
    }

    // ================================================================
    // ROGUE FILES SCANNER
    // ================================================================
    (function initRogueScanner() {
        var rogueFiles    = [];
        var rogueSelected = new Set();

        $('#imp-scan-rogue').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).text(L.btn_scanning || '⏳ Scanning...');
            $('#imp-rogue-result').hide();
            $('#imp-rogue-loading').show();
            ajax('tso_im_scan_rogue_files', {}, function(data) {
                btn.prop('disabled', false).text(uiText('scan_rogue', '🔍 Scan extra upload files'));
                $('#imp-rogue-loading').hide();
                rogueFiles    = data.files || [];
                rogueSelected = new Set();
                if (!rogueFiles.length) {
                    $('#imp-rogue-result').show();
                    $('#imp-rogue-grid').html('<div class="imp-loading" style="padding:30px 0">✓ ' + (L.no_rogue || 'No extra files found.') + '</div>');
                    $('#imp-rogue-summary').text('');
                    $('#imp-delete-rogue, #imp-rogue-select-all, #imp-rogue-deselect').hide();
                    return;
                }
                $('#imp-rogue-summary').text(data.total + ' ' + (L.files_label || 'files') + ' \u00b7 ' + data.total_size_h);
                $('#imp-delete-rogue, #imp-rogue-select-all, #imp-rogue-deselect').show();
                renderRogueGrid();
                $('#imp-rogue-result').show();
            }, function(err) {
                btn.prop('disabled', false).text(uiText('scan_rogue', '🔍 Scan extra upload files'));
                $('#imp-rogue-loading').hide();
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        $(document).on('click', '.imp-rogue-card', function(e) {
            if ($(e.target).closest('a').length) return;
            var path = $(this).data('path');
            if (rogueSelected.has(path)) {
                rogueSelected.delete(path);
                $(this).removeClass('selected');
            } else {
                rogueSelected.add(path);
                $(this).addClass('selected');
            }
            updateRogueToolbar();
        });

        $('#imp-rogue-select-all').on('click', function() {
            rogueSelected = new Set(rogueFiles.map(function(f) { return f.path; }));
            $('.imp-rogue-card').addClass('selected');
            updateRogueToolbar();
        });
        $('#imp-rogue-deselect').on('click', function() {
            rogueSelected.clear();
            $('.imp-rogue-card').removeClass('selected');
            updateRogueToolbar();
        });

        $('#imp-delete-rogue').on('click', function() {
            if (!rogueSelected.size) return;
            if (!confirm((uiText('confirm_delete_rogue', 'Delete') || 'Delete') + ' ' + rogueSelected.size + ' ' + uiText('file_unit_suffix', 'file(s)?'))) return;
            var btn = $(this);
            btn.prop('disabled', true).text(L.btn_deleting || '⏳ Deleting...');
            // Send path_b64 (base64-encoded absolute paths) for safe UTF-8/latin1 handling
            var paths_b64 = rogueFiles
                .filter(function(f) { return rogueSelected.has(f.path); })
                .map(function(f) { return f.path_b64; });
            ajax('tso_im_delete_rogue_files', { paths_b64: paths_b64 }, function(data) {
                btn.prop('disabled', false).text(L.delete_rogue || '🗑 Delete selected');
                rogueFiles    = rogueFiles.filter(function(f) { return !rogueSelected.has(f.path); });
                rogueSelected = new Set();
                if (data.errors && data.errors.length) {
                    alert(uiText('deleted_msg', 'Deleted') + ' ' + data.deleted + '. ' + uiText('errors_label', 'Errors:') + '\n' + data.errors.join('\n'));
                }
                if (!rogueFiles.length) {
                    $('#imp-rogue-grid').html('<div class="imp-loading" style="padding:30px 0">✓ ' + (L.all_rogue_deleted || 'All selected extra files deleted!') + '</div>');
                    $('#imp-rogue-summary').text('');
                    $('#imp-delete-rogue, #imp-rogue-select-all, #imp-rogue-deselect').hide();
                } else {
                    $('#imp-rogue-summary').text(rogueFiles.length + ' ' + (L.files_label || 'files') + ' ' + (L.remaining_label || 'remaining'));
                    renderRogueGrid();
                    updateRogueToolbar();
                }
            }, function(err) {
                btn.prop('disabled', false).text(L.delete_rogue || '🗑 Delete selected');
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        function renderRogueGrid() {
            var grid = $('#imp-rogue-grid');
            if (!rogueFiles.length) { grid.html(''); return; }
            var sorted = rogueFiles.slice().sort(function(a, b) {
                var ta = a.mtime || 0;
                var tb = b.mtime || 0;
                if (tb !== ta) return tb - ta;
                return String(a.filename || '').localeCompare(String(b.filename || ''), undefined, { sensitivity: 'base' });
            });
            var html = '';
            sorted.forEach(function(f) {
                var sel   = rogueSelected.has(f.path) ? ' selected' : '';
                var reasonLabel = uiText('rogue_reason_' + (f.reason_code || ''), f.reason || '');
                var badge = f.priority === 'high'
                    ? '<span class="imp-rogue-badge imp-rogue-badge-high">! ' + escHtml(reasonLabel) + '</span>'
                    : (f.reason_code === 'tso_backup' || f.reason_code === 'generic_backup' || f.reason_code === 'tso_pdf_compressed'
                        ? '<span class="imp-rogue-badge imp-rogue-badge-info">💾 ' + escHtml(reasonLabel) + '</span>'
                        : '<span class="imp-rogue-badge imp-rogue-badge-low">' + escHtml(reasonLabel) + '</span>');
                html += '<div class="imp-rogue-card' + sel + '" data-path="' + escHtml(f.path) + '">' +
                    '<div class="imp-rogue-name" title="' + escHtml(f.path) + '">' + escHtml(f.filename) + '</div>' +
                    badge +
                    '<div class="imp-rogue-meta">' +
                    '<span class="imp-rogue-size">'  + escHtml(f.size_h) + '</span>' +
                    '<span class="imp-rogue-date">'  + escHtml(f.date)   + '</span>' +
                    '<a href="' + escHtml(f.url) + '" target="_blank" rel="noopener" class="imp-rogue-link" title="' + escHtml(L.open_title || 'Open') + '">↗</a>' +
                    '</div></div>';
            });
            grid.html(html);
        }

        function updateRogueToolbar() {
            var n   = rogueFiles.length;
            var sel = rogueSelected.size;
            $('#imp-rogue-summary').text(n + ' ' + (L.files_label || 'files') + ' | ' + sel + ' ' + (L.n_selected || 'selected'));
            $('#imp-delete-rogue').prop('disabled', sel === 0);
        }

        window.tsoimmaRefreshRogueUi = function() {
            if (!$('#tab-orphans').is(':visible')) return;
            renderRogueGrid();
            updateRogueToolbar();
        };
    })();

    // ================================================================
    // SEO TAB
    // ================================================================
    function initSeoTab() {
        $('#imp-seo-sort').on('change', function() {
            state.seo.page = 1; loadSeoImages();
        });
        $(document).on('click', '#imp-seo-grid .imp-image-card', function(e) {
            if ($(e.target).hasClass('imp-card-edit-btn')) return;
            openModal($(this).data('id'), 'seo');
        });
        $(document).on('click', '#imp-seo-grid .imp-card-edit-btn', function(e) {
            e.stopPropagation();
            openModal($(this).data('id'), 'rename');
        });
    }

    function loadSeoImages() {
        var grid = $('#imp-seo-grid');
        var sort = $('#imp-seo-sort').val() || 'filesize';
        grid.html('<div class="imp-loading">' + (L.loading_images || 'Loading...') + '</div>');
        ajax('tso_im_get_images', {
            page: state.seo.page, per_page: state.seo.perPage,
            search: state.seo.search, sort: sort
        }, function(data) {
            state.seo.totalPages = data.total_pages;
            renderSeoGrid(data.items);
            renderPagination('#imp-seo-pagination', state.seo.page, data.total_pages, function(p) {
                state.seo.page = p; loadSeoImages();
            });
        }, function(err) {
            grid.html('<div class="imp-loading">' + (L.error_prefix || 'Error: ') + err + '</div>');
        });
    }

    function renderSeoGrid(items) {
        var grid = $('#imp-seo-grid');
        grid.empty();
        if (!items.length) { grid.html('<div class="imp-loading">' + (L.no_images || 'No images.') + '</div>'); return; }
        items.forEach(function(item) {
            var ext    = item.ext || item.mime.replace('image/', '').toUpperCase();
            var seoOk  = item.slug_ok && item.alt;
            var seoCls = seoOk ? 'imp-badge-seo-ok' : 'imp-badge-seo-bad';
            var seoTxt = seoOk ? 'SEO ✓' : 'SEO ✗';
            var card   = $('<div class="imp-image-card" data-id="' + item.id + '" style="cursor:pointer;"></div>');
            card.append('<div class="imp-card-badges"><span class="' + seoCls + '">' + seoTxt + '</span></div>');
            card.append('<img class="imp-card-thumb" src="' + escHtml(cacheBustUrl(item.thumb || '', item.id)) + '" alt="' + escHtml(item.title) + '" loading="lazy">');
            var altWarn = item.alt ? '' : '<div style="font-size:10px;color:var(--imp-warn);margin-top:4px;">⚠ ' + (L.no_alt_text || 'No alt text') + '</div>';
            card.append(
                '<div class="imp-card-info">' +
                '<div class="imp-card-name">' + escHtml(item.filename) + '</div>' +
                '<div class="imp-card-meta">' +
                '<span class="imp-card-size">' + escHtml(item.filesize) + '</span>' +
                '<span class="imp-card-format">' + escHtml(ext) + '</span>' +
                '</div>' + altWarn + '</div>'
            );
            card.append('<button class="imp-card-edit-btn" data-id="' + item.id + '">✏ SEO</button>');
            grid.append(card);
        });
    }

    // ================================================================
    // MODAL
    // ================================================================
    function initModal() {
        $(document).on('click', '.imp-modal-close, .imp-modal-overlay', closeModal);
        $(document).on('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
        $(document).on('click', '.imp-mtab', function() {
            var tab = $(this).data('mtab');
            $(this).closest('.imp-modal-tabs').find('.imp-mtab').removeClass('active');
            $(this).addClass('active');
            $(this).closest('.imp-modal-form').find('.imp-mtab-content').removeClass('active');
            $('#mtab-' + tab).addClass('active');
        });

        // Save SEO
        $('#imp-save-seo').on('click', function() {
            var id  = state.currentModalId;
            var btn = $(this);
            btn.prop('disabled', true).text(L.processing || 'Processing...');
            ajax('tso_im_update_seo', {
                attachment_id: id,
                title: $('#imp-seo-title').val(), alt: $('#imp-seo-alt').val(),
                caption: $('#imp-seo-caption').val(), description: $('#imp-seo-description').val()
            }, function() {
                btn.text(L.save_ok || '✓ Saved!');
                setTimeout(function() { btn.prop('disabled', false).text(L.save_seo || '💾 Save SEO'); }, 1500);
                loadSeoImages();
            }, function(err) { btn.prop('disabled', false).text(L.save_seo || '💾 Save SEO'); alert((L.error_prefix || 'Error: ') + err); });
        });

        // Suggest filename
        $('#imp-use-suggested').on('click', function() {
            $('#imp-new-filename').val($('#imp-suggested-name').text());
        });

        // Save rename
        $('#imp-save-rename').on('click', function() {
            var id      = state.currentModalId;
            var newName = $('#imp-new-filename').val().trim();
            if (!newName) { alert(L.enter_name || 'Enter a name.'); return; }
            var btn = $(this);
            btn.prop('disabled', true).text(L.processing || 'Processing...');
            ajax('tso_im_rename_image', { attachment_id: id, new_name: newName, strict_seo: 0 }, function(data) {
                btn.prop('disabled', false).text(uiText('rename_btn', '✏ Rename file'));
                $('#imp-current-filename').val(data.new_filename);
                alert('✓ ' + data.old_filename + ' \u2192 ' + data.new_filename);
                closeModal();
                loadOptImages();
                loadSeoImages();
            }, function(err) {
                btn.prop('disabled', false).text(uiText('rename_btn', '✏ Rename file'));
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        // Resize toggle
        $('#imp-modal-resize').on('change', function() {
            $('#imp-resize-options').toggle(this.checked);
            if (!this.checked) {
                $('#imp-modal-width').val('');
                $('#imp-modal-height').val('');
                $('.imp-preset-btn').removeClass('active');
            }
        });
        $(document).on('click', '#imp-resize-toggle', function(e) {
            if (!$(e.target).closest('.imp-toggle-switch').length) {
                var cb = $('#imp-modal-resize');
                cb.prop('checked', !cb.prop('checked')).trigger('change');
            }
        });
        $(document).on('click', '.imp-preset-btn', function() {
            $('#imp-modal-width').val($(this).data('w') || '');
            $('#imp-modal-height').val($(this).data('h') || '');
            $('.imp-preset-btn').removeClass('active');
            $(this).addClass('active');
        });
        $('#imp-modal-width, #imp-modal-height').on('input', function() {
            $('.imp-preset-btn').removeClass('active');
        });

        // Optimize single
        $('#imp-optimize-single').on('click', function() {
            var id       = state.currentModalId;
            var btn      = $(this);
            var doResize = $('#imp-modal-resize').is(':checked');
            btn.prop('disabled', true).text(L.processing || 'Processing...');
            ajax('tso_im_optimize_image', {
                attachment_id: id,
                format:     $('#imp-modal-format').val(),
                quality:    $('#imp-modal-quality').val(),
                replace:    $('#imp-modal-replace').is(':checked') ? 1 : 0,
                max_width:  doResize ? (parseInt($('#imp-modal-width').val(), 10)  || 0) : 0,
                max_height: doResize ? (parseInt($('#imp-modal-height').val(), 10) || 0) : 0
            }, function(data) {
                btn.prop('disabled', false).text(L.optimize_now || '⚡ Optimize now');
                var box = $('#imp-modal-result');
                if (data.replaced) {
                    var bigger   = data.savings_pct <= 0;
                    var cls      = bigger ? 'imp-result-warn' : 'imp-result-ok';
                    var origSize = data.backup_size ? formatBytes(data.backup_size) : formatBytes(data.original_size);
                    var thumbNote = '<br><small id="imp-thumb-status" style="color:var(--imp-accent2)">⏳ ' + (L.optimizing_thumbs || 'Optimizing thumbnails...') + '</small>';
                    var msg = (bigger ? '⚠ ' : '✓ ') +
                        (bigger ? (L.converted_bigger || 'Converted but larger') : (L.optimized_ok || 'Optimized!')) +
                        '<br>Format: <strong>' + data.format.toUpperCase() + '</strong><br>' +
                        origSize + ' \u2192 <strong>' + formatBytes(data.new_size) + '</strong>' +
                        (data.savings_pct > 0 ? ' | <strong>' + data.savings_pct + '%</strong>' : '') +
                        thumbNote;
                    box.removeClass('imp-result-err imp-result-ok imp-result-warn').addClass(cls + ' imp-result-box').show().html(msg);
                    state.imgCacheTs[id] = Date.now();
                    if (data.new_url) {
                        var ts = state.imgCacheTs[id];
                        $('#imp-modal-img').attr('src', data.new_url + '?_t=' + ts).attr('data-full-url', data.new_url + '?_t=' + ts);
                    }
                    loadOptImages(); loadSeoImages();
                    setTimeout(function() {
                        state.imgCacheTs[id] = Date.now();
                        var savedHtml = box.html();
                        var savedCls  = box.attr('class');
                        savedHtml = savedHtml.replace(/<small id="imp-thumb-status"[^>]*>.*?<\/small>/, '<small id="imp-thumb-status" style="color:var(--imp-success)">✓ ' + (L.thumbs_done || 'Thumbnails processed.') + '</small>');
                        openModal(id, 'optimize');
                        setTimeout(function() { $('#imp-modal-result').attr('class', savedCls).html(savedHtml).show(); }, 80);
                        loadOptImages(); loadSeoImages();
                    }, 8000);
                } else {
                    box.removeClass('imp-result-err imp-result-warn').addClass('imp-result-ok imp-result-box').show()
                       .html('✓ ' + (L.optimized_no_replace || 'Optimized (not replaced).') + ' ' + data.savings_pct + '%');
                }
            }, function(err) {
                btn.prop('disabled', false).text(L.optimize_now || '⚡ Optimize now');
                $('#imp-modal-result').show().removeClass('imp-result-ok imp-result-warn').addClass('imp-result-err imp-result-box').text((L.error_prefix || 'Error: ') + err);
            }, 120000);
        });
    }

    // Revert
    $(document).on('click', '#imp-revert-btn', function() {
        var id  = state.currentModalId;
        var btn = $(this);
        if (!confirm(L.confirm_revert || 'Revert to original? Optimized version will be lost.')) return;
        btn.prop('disabled', true).text(L.btn_reverting || '⏳ Reverting...');
        ajax('tso_im_revert_image', { attachment_id: id }, function(data) {
            var box      = $('#imp-modal-result');
            var savedHtml = '↩ ' + (L.reverted_ok || 'Reverted!') + '<br>' + data.restored_ext + ' | ' + data.restored_size_h;
            var savedCls  = 'imp-result-ok imp-result-box';
            setTimeout(function() {
                openModal(id, 'optimize');
                setTimeout(function() { $('#imp-modal-result').attr('class', savedCls).html(savedHtml).show(); }, 80);
            }, 400);
            loadOptImages(); loadSeoImages();
        }, function(err) {
            btn.prop('disabled', false).text(L.revert_btn || '↩ Revert');
            var msg = (err === 'backup_mismatch_after_rename')
                ? uiText('revert_rename_mismatch', 'Cannot revert because the file was renamed after optimization.')
                : err;
            alert((L.error_prefix || 'Error: ') + msg);
        });
    });

    // Delete backup
    $(document).on('click', '#imp-delete-backup-btn', function() {
        var id  = state.currentModalId;
        var btn = $(this);
        if (!confirm(L.confirm_del_backup || 'Delete backup? You will not be able to revert.')) return;
        btn.prop('disabled', true).text(L.btn_deleting || '⏳ Deleting...');
        ajax('tso_im_delete_backup', { attachment_id: id }, function() {
            openModal(id, 'optimize');
        }, function(err) { btn.prop('disabled', false).text(L.del_backup || '🗑 Delete backup'); alert((L.error_prefix || 'Error: ') + err); });
    });

    function openModal(id, defaultTab) {
        state.currentModalId = id;
        $('#imp-modal-result').hide().text('');
        $('#imp-modal-resize').prop('checked', false);
        $('#imp-resize-options').hide();
        $('#imp-modal-width, #imp-modal-height').val('');
        $('.imp-preset-btn').removeClass('active');
        $('#imp-modal').show();
        $('.imp-mtab').removeClass('active');
        $('.imp-mtab-content').removeClass('active');
        $('.imp-mtab[data-mtab="' + defaultTab + '"]').addClass('active');
        $('#mtab-' + defaultTab).addClass('active');
        $('#imp-modal-title-head').html('<span style="color:var(--imp-text-muted)">' + (L.loading_modal || 'Loading...') + '</span>');
        $('#imp-modal-img').attr('src', '');
        ajax('tso_im_get_image_info', { attachment_id: id }, function(data) {
            $('#imp-modal-title-head').html('<span class="imp-modal-fname">' + escHtml(data.filename) + '</span>');
            $('#imp-modal-id').val(id);
            var ts = Date.now();
            $('#imp-modal-img')
                .attr('src', (data.thumb || '') + '?t=' + ts)
                .attr('data-full-url', (data.url || data.thumb || '') + '?t=' + ts)
                .css('cursor', data.url ? 'zoom-in' : '');
            $('#imp-modal-fileinfo').html(
                '<strong>📄</strong> ' + escHtml(data.filename) + '<br>' +
                '<strong>📐</strong> ' + data.width + '\u00d7' + data.height + 'px<br>' +
                '<strong>⚖</strong> '   + escHtml(data.filesize_h) + '<br>' +
                '<strong>🖼</strong> ' + escHtml(data.ext) + ' \u00b7 ' + escHtml(data.mime)
            );
            $('#imp-seo-title').val(data.title || '');
            $('#imp-seo-alt').val(data.alt || '');
            $('#imp-seo-caption').val(data.caption || '');
            $('#imp-seo-description').val(data.description || '');
            renderUsedIn(data.used_in || [], !!data.is_orphan);
            $('#imp-current-filename').val(data.filename);
            $('#imp-new-filename').val('');
            $('#imp-suggested-name').text(data.suggested || '');
            var backupHtml = data.has_backup
                ? '<div class="imp-stat-box imp-stat-backup" style="border-color:var(--imp-warn);grid-column:1/-1">' +
                  '<div class="imp-backup-info"><span>💾 <strong>' + (L.backup_available || 'Backup available') + '</strong>' + (data.backup_size ? ' \u00b7 ' + data.backup_size : '') + '</span></div>' +
                  '<button id="imp-revert-btn" class="imp-btn imp-btn-sm imp-btn-danger">' + (L.revert_btn || 'Revert to original') + '</button>' +
                  '<button id="imp-delete-backup-btn" class="imp-btn imp-btn-sm" style="border-color:var(--imp-warn);color:var(--imp-warn)">🗑 ' + (L.del_backup || 'Delete backup') + '</button>' +
                  '</div>'
                : '';
            $('#imp-modal-stats').html(
                '<div class="imp-stat-box"><span class="imp-stat-label">' + (L.stat_current_size || 'Current size') + '</span><span class="imp-stat-val">' + escHtml(data.filesize_h) + '</span></div>' +
                '<div class="imp-stat-box"><span class="imp-stat-label">' + (L.stat_real_format || 'Format') + '</span><span class="imp-stat-val" style="color:var(--imp-accent2)">' + escHtml(data.ext) + '</span></div>' +
                backupHtml
            );
        });
    }

    function renderUsedIn(posts, isOrphan) {
        var $block = $('#imp-used-in-block');
        if (!$block.length) {
            $('#mtab-seo').append('<div id="imp-used-in-block" class="imp-used-in"></div>');
            $block = $('#imp-used-in-block');
        }
        if (!posts || !posts.length) {
            var msg   = isOrphan ? (L.orphan_confirmed || 'Confirmed orphan: not referenced anywhere.') : (L.not_in_content || 'Not found in post_content.');
            var color = isOrphan ? 'var(--imp-danger)' : 'var(--imp-warn)';
            $block.html('<div class="imp-used-in-title">📎 ' + (L.used_in || 'Used in') + '</div><div class="imp-used-in-empty" style="color:' + color + '">' + msg + '</div>');
            return;
        }
        var html = '<div class="imp-used-in-title">📎 ' + (L.used_in || 'Used in') + ' (' + posts.length + ')</div><div class="imp-used-in-list">';
        posts.forEach(function(p) {
            var editUrl  = TSOIMMA.site_url + '/wp-admin/post.php?post=' + p.id + '&action=edit';
            var typeLbl  = p.featured ? '⭐ ' + (L.featured || 'Featured') : (p.type === 'post' ? '📝 ' + (L.post_label || 'Post') : '📄 ' + (L.page_label || 'Page'));
            html += '<a href="' + escHtml(editUrl) + '" target="_blank" rel="noopener" class="imp-used-in-item">' +
                '<span class="imp-used-in-type">' + typeLbl + '</span>' +
                '<span class="imp-used-in-item-title">' + escHtml(p.title) + '</span>' +
                '<span style="color:var(--imp-text-muted);font-size:10px;">↗</span>' +
                '</a>';
        });
        html += '</div>';
        $block.html(html);
    }

    function closeModal() {
        $('#imp-modal').hide();
        state.currentModalId = null;
    }

    // ================================================================
    // PAGINATION
    // ================================================================
    function renderPagination(selector, current, total, onClick) {
        var $el = $(selector);
        $el.empty();
        if (total <= 1) return;
        for (var i = 1; i <= total; i++) {
            (function(page) {
                var btn = $('<button class="imp-page-btn">' + page + '</button>');
                if (page === current) btn.addClass('active');
                btn.on('click', function() { onClick(page); });
                $el.append(btn);
            })(i);
        }
    }

    // ================================================================
    // AJAX helper
    // ================================================================
    function parseAjaxError(data) {
        if (data == null || data === '') {
            return 'Unknown error.';
        }
        if (typeof data === 'string') {
            return data;
        }
        if (typeof data === 'object') {
            if (data.message) {
                return String(data.message);
            }
            if (data.code && !data.message) {
                return String(data.code);
            }
        }
        return 'Unknown error.';
    }

    function ajax(action, data, onSuccess, onError, timeoutMs) {
        $.ajax({
            url:     TSOIMMA.ajax_url,
            type:    'POST',
            timeout: timeoutMs || 120000,
            data:    $.extend({ action: action, nonce: TSOIMMA.nonce }, data),
            success: function(resp) {
                if (resp.success) {
                    if (onSuccess) onSuccess(resp.data);
                } else {
                    (onError || defaultError)(parseAjaxError(resp.data));
                }
            },
            error: function(xhr, status) {
                if (status === 'timeout') {
                    if (onError) onError('timeout');
                } else {
                    (onError || defaultError)('Network error: ' + xhr.status);
                }
            }
        });
    }

    function defaultError(msg) { console.error('[TSOIMMA]', msg); }

    function addLog(type, msg) {
        $('#imp-opt-log').show();
        $('#imp-log-content').prepend($('<div class="imp-log-entry imp-log-' + type + '"></div>').text(msg));
    }

    function cacheBustUrl(url, id) {
        if (!url) return url;
        var ts = state.imgCacheTs[id];
        if (!ts) return url;
        return url + (url.indexOf('?') === -1 ? '?' : '&') + '_t=' + ts;
    }

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ================================================================
    // PDF TAB
    // ================================================================
    var pdfState = { selected: new Set(), page: 1, perPage: 30, search: '' };

    function pollPdfStatus(id, btn, $sizeCell, attempt, replace) {
        var POLL_MS = 1500;
        var MAX_ATTEMPTS = 80; // 80 * 1.5s ~= 120s hard client cap
        attempt = attempt || 0;
        replace = replace !== undefined ? replace : 1;

        // Hard client timeout (backend also has its own cap)
        if (attempt > MAX_ATTEMPTS) {
            btn.prop('disabled', false).text(uiText('pdf_compress_btn', '📄 Compress')).css('background', '');
            var timeoutMessage = L.pdf_timeout_msg || 'GhostScript timed out. Check FTP.';
            ajax('tso_im_mark_pdf_non_compressible', {
                attachment_id: id,
                code: 'client_timeout',
                message: timeoutMessage
            }, function() {
                loadPdfs();
                alert(timeoutMessage);
            }, function() {
                loadPdfs();
                alert(timeoutMessage);
            });
            return;
        }

        // Show elapsed seconds so the user knows it's still working
        var elapsed = Math.round((attempt * POLL_MS) / 1000);
        var dots    = '.'.repeat((attempt % 3) + 1);
        btn.text('⏳ ' + (L.btn_processing || 'Processing') + dots + ' ' + elapsed + 's');

        setTimeout(function() {
            ajax('tso_im_pdf_status', { attachment_id: id, replace: replace }, function(data) {
                if (data.status === 'done') {
                    // Application error (no_gain, corrupt PDF, replace failed)
                    if (data.error) {
                        btn.prop('disabled', false).text(uiText('pdf_compress_btn', '📄 Compress')).css('background', '');
                        alert(data.error);
                        loadPdfs();
                        return;
                    }
                    var r   = data.result || {};
                    var pct = (r.savings_pct != null) ? parseFloat(r.savings_pct).toFixed(1) : '?';
                    btn.prop('disabled', false)
                       .text('✓ ' + pct + '% ' + (L.optimize_done || 'saved'))
                       .css('background', 'var(--imp-success)');
                    if (r.new_size && $sizeCell && $sizeCell.length) $sizeCell.text(formatBytes(r.new_size));
                } else if (data.status === 'idle') {
                    btn.prop('disabled', false).text(uiText('pdf_compress_btn', '📄 Compress')).css('background', '');
                    alert(L.gs_none || 'GhostScript failed silently.');
                    loadPdfs();
                } else {
                    pollPdfStatus(id, btn, $sizeCell, attempt + 1, replace);
                }
            }, function() { pollPdfStatus(id, btn, $sizeCell, attempt + 1, replace); });
        }, POLL_MS);
    }

    function pollPdfStatusBulk(id, onDone, attempt) {
        var POLL_MS = 1500;
        var MAX_ATTEMPTS = 80; // ~= 120s
        attempt = attempt || 0;
        if (attempt > MAX_ATTEMPTS) { onDone(null); return; }
        setTimeout(function() {
            ajax('tso_im_pdf_status', { attachment_id: id, replace: 1 }, function(data) {
                if (data.status === 'done')       onDone(data.result || {});
                else if (data.status === 'idle')  onDone(null);
                else                              pollPdfStatusBulk(id, onDone, attempt + 1);
            }, function() { pollPdfStatusBulk(id, onDone, attempt + 1); });
        }, POLL_MS);
    }

    function initPdfTab() {
        $(document).on('click', '.imp-tab[data-tab="pdf"]', function() { loadPdfs(); });
        initPdfPreview();
        var pdfSearchTimer;
        $('#imp-search-pdf').on('input', function() {
            clearTimeout(pdfSearchTimer);
            var v = $(this).val();
            pdfSearchTimer = setTimeout(function() { pdfState.search = v; pdfState.page = 1; loadPdfs(); }, 400);
        });
        $('#imp-pdf-select-all').on('click', function() {
            $('#imp-pdf-grid .imp-pdf-row').each(function() { pdfState.selected.add($(this).data('id')); $(this).addClass('selected'); });
            updatePdfCount();
        });
        $('#imp-pdf-deselect').on('click', function() {
            pdfState.selected.clear();
            $('#imp-pdf-grid .imp-pdf-row').removeClass('selected');
            updatePdfCount();
        });
        $(document).on('click', '#imp-pdf-grid .imp-pdf-row', function(e) {
            if ($(e.target).closest('.imp-pdf-compress-btn, .imp-pdf-preview-btn').length) return;
            var id = $(this).data('id');
            if (pdfState.selected.has(id)) { pdfState.selected.delete(id); $(this).removeClass('selected'); }
            else { pdfState.selected.add(id); $(this).addClass('selected'); }
            updatePdfCount();
        });
        $(document).on('click', '.imp-pdf-compress-btn', function(e) {
            e.stopPropagation();
            var id  = $(this).data('id');
            var btn = $(this);
            btn.prop('disabled', true).text('⏳');
            ajax('tso_im_compress_pdf', {
                attachment_id: id,
                quality: $('#imp-pdf-quality').val(),
                replace: $('#imp-pdf-replace').is(':checked') ? 1 : 0
            }, function(data) {
                if (data.status === 'processing') {
                    btn.text(L.btn_processing || '⏳ Processing...');
                    var replace = $('#imp-pdf-replace').is(':checked') ? 1 : 0;
                    pollPdfStatus(id, btn, btn.closest('.imp-pdf-row').find('.imp-pdf-size'), 0, replace);
                }
            }, function(err) {
                btn.prop('disabled', false).text(uiText('pdf_compress_btn', '📄 Compress')).css('background', '');
                alert(err || (L.error_prefix || 'Error: ') + ' PDF compression failed.');
                // Refresh list so newly marked "non-compressible" items appear immediately.
                loadPdfs();
            });
        });
        $('#imp-bulk-compress-pdf').on('click', function() {
            var ids = Array.from(pdfState.selected);
            if (!ids.length) { alert(L.no_selection || 'Select at least one.'); return; }
            bulkCompressPdf(ids);
        });
    }

    function initPdfPreview() {
        var $modal = $('#imp-pdf-preview-modal');
        function closePdfPreview() {
            $modal.hide();
            $('#imp-pdf-preview-frame').attr('src', 'about:blank');
        }
        function openPdfPreview(url, filename) {
            if (!url) return;
            $('#imp-pdf-preview-title').text(filename || uiText('pdf_preview_title', 'PDF preview'));
            $('#imp-pdf-preview-open').attr('href', url).text(uiText('pdf_open_tab', 'Open in new tab'));
            $('#imp-pdf-preview-frame').attr('src', url);
            $modal.show();
        }
        $(document).on('click', '.imp-pdf-preview-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openPdfPreview($(this).attr('data-url') || '', $(this).attr('data-filename') || '');
        });
        $modal.on('click', '.imp-modal-overlay, .imp-pdf-preview-close', function(e) {
            e.preventDefault();
            closePdfPreview();
        });
        $(document).on('keydown.impPdfPreview', function(e) {
            if (e.key === 'Escape' && $modal.is(':visible')) closePdfPreview();
        });
    }

    function loadPdfs() {
        var grid = $('#imp-pdf-grid');
        grid.html('<div class="imp-loading">' + (L.loading_pdfs || 'Loading PDFs...') + '</div>');
        ajax('tso_im_get_pdfs', { page: pdfState.page, per_page: pdfState.perPage, search: pdfState.search }, function(data) {
            var $eng = $('#imp-pdf-engine-status');
            if (data.gs_available) {
                $eng.html('<span class="imp-pdf-engine-ok">✓ ' + (L.gs_available || 'GhostScript available') + '</span>');
            } else if (data.imagick_available) {
                $eng.html('<span class="imp-pdf-engine-warn">' + (L.pdf_engine_warn || '⚠ GhostScript not found. Using Imagick.') + '</span>');
            } else {
                $eng.html('<span class="imp-pdf-engine-err">✗ ' + (L.gs_none || 'No engine available.') + '</span>');
            }
            renderPdfGrid(data.items);
            $('#imp-pdf-count').text(data.total + ' PDFs');
        });
    }

    function renderPdfGrid(items) {
        var grid = $('#imp-pdf-grid');
        grid.empty();
        if (!items.length) { grid.html('<div class="imp-loading">' + (L.no_pdfs || 'No PDFs found.') + '</div>'); return; }
        items.forEach(function(item) {
            var compTag = item.compressed ? '<span class="imp-pdf-compressed">✓ ' + uiText('compressed_tag', 'Compressed') + '</span>' : '';
            var noCompTag = item.non_compressible ? '<span class="imp-pdf-compressed" style="background:rgba(239,68,68,.18);color:#fecaca;">⚠ ' + uiText('non_compressible_tag', 'Not compressible') + '</span>' : '';
            var reasonTitle = item.non_compressible_reason ? (' title="' + escHtml(item.non_compressible_reason) + '"') : '';
            var btnDisabled = item.non_compressible ? ' disabled' : '';
            var row = $('<div class="imp-pdf-row" data-id="' + item.id + '"></div>');
            row.append('<span class="imp-pdf-icon">📄</span>');
            row.append('<span class="imp-pdf-name" title="' + escHtml(item.filename) + '">' + escHtml(item.filename) + '</span>');
            row.append('<span class="imp-pdf-size">' + escHtml(item.filesize) + '</span>');
            row.append('<span class="imp-pdf-date">' + escHtml(item.date) + '</span>');
            if (compTag) row.append(compTag);
            if (noCompTag) row.append(noCompTag);
            if (item.url) {
                row.append(
                    '<button type="button" class="imp-pdf-preview-btn" data-id="' + item.id + '" data-url="' + escHtml(item.url) + '" data-filename="' + escHtml(item.filename) + '" title="' + escHtml(uiText('pdf_preview_btn', 'View')) + '">' + uiText('pdf_preview_btn', '👁 View') + '</button>'
                );
            }
            row.append('<button class="imp-pdf-compress-btn" data-id="' + item.id + '"' + btnDisabled + reasonTitle + '>' + uiText('pdf_compress_btn', '📄 Compress') + '</button>');
            grid.append(row);
        });
    }

    function bulkCompressPdf(ids) {
        var total = ids.length;
        var done  = 0;
        $('#imp-pdf-bulk-progress').show();
        $('#imp-bulk-compress-pdf').prop('disabled', true);
        function next() {
            if (done >= total) { $('#imp-bulk-compress-pdf').prop('disabled', false); loadPdfs(); return; }
            var id = ids[done];
            ajax('tso_im_compress_pdf', { attachment_id: id, quality: $('#imp-pdf-quality').val(), replace: 1 }, function(data) {
                if (data.status === 'processing') {
                    pollPdfStatusBulk(id, function(result) {
                        done++;
                        if (result) {
                            $('#imp-pdf-progress-fill').css('width', Math.round(done/total*100) + '%');
                            $('#imp-pdf-progress-text').text((L.bulk_processing || 'Processed') + ' ' + done + '/' + total);
                        }
                        next();
                    });
                } else { done++; next(); }
            }, function() { done++; next(); });
        }
        next();
    }

    function updatePdfCount() {
        $('#imp-pdf-count').text(pdfState.selected.size + ' ' + (L.n_selected || 'selected'));
        $('#imp-bulk-compress-pdf').prop('disabled', pdfState.selected.size === 0);
    }

    // ================================================================
    // AUTO-OPTIMIZATION TAB
    // ================================================================
    var autoHistState = { page: 1, perPage: 20 };

    function initAutoTab() {
        $(document).on('click', '.imp-tab[data-tab="auto"]', function() {
            loadHistoryStats('#imp-auto-stats');
            autoHistState.page = 1;
            loadAutoHistory();
        });
        $('#imp-auto-history-clear-30').on('click', function() {
            if (!confirm(L.confirm_clean_30 || 'Delete entries older than 30 days?')) return;
            ajax('tso_im_clear_history', { days: 30, type: 'auto_optimize' }, function() { autoHistState.page = 1; loadAutoHistory(); loadHistoryStats('#imp-auto-stats'); });
        });
        $('#imp-auto-history-clear-all').on('click', function() {
            if (!confirm(L.confirm_clean_all || 'Delete ALL history?')) return;
            ajax('tso_im_clear_history', { days: 0, type: 'auto_optimize' }, function() { autoHistState.page = 1; loadAutoHistory(); loadHistoryStats('#imp-auto-stats'); });
        });
        $('#imp-fix-orphan-meta').on('click', function() {
            var $btn = $(this);
            var $res = $('#imp-fix-orphan-result');
            $btn.prop('disabled', true).text(L.btn_repairing || '⏳ Repairing...');
            $res.hide();
            ajax('tso_im_fix_orphan_meta', {}, function(data) {
                $btn.prop('disabled', false).text(uiText('repair_paths', '🔧 Repair broken paths'));
                $res.data('kind', 'repaired').data('count', (data.fixed || 0));
                $res.show().css('color', 'var(--imp-success)').html('✓ ' + (data.fixed || 0) + ' ' + uiText('repaired_msg', 'images repaired.'));
            }, function(err) {
                $btn.prop('disabled', false).text(uiText('repair_paths', '🔧 Repair broken paths'));
                $res.removeData('kind').removeData('count');
                $res.show().css('color', 'var(--imp-danger)').text((L.error_prefix || 'Error: ') + err);
            });
        });
        $('#imp-auto-quality').on('input', function() { $('#imp-auto-quality-val').text($(this).val()); });
        $('#imp-auto-enabled').on('change', function() { updateAutoToggleUI($(this).is(':checked')); });
        $('#imp-save-auto').on('click', function() {
            var selectedSourceFormats = $('.imp-auto-src-format:checked').map(function() { return $(this).val(); }).get();
            ajax('tso_im_save_auto_settings', {
                enabled: $('#imp-auto-enabled').is(':checked') ? 1 : 0,
                format:  $('#imp-auto-format').val(),
                quality: $('#imp-auto-quality').val(),
                source_formats: selectedSourceFormats,
                fill_alt_on_upload: $('#imp-auto-fill-alt').is(':checked') ? 1 : 0,
                skip_small_kb: $('#imp-auto-skip-kb').val()
            }, function(data) {
                $('#imp-auto-saved').show().delay(2000).fadeOut();
                updateAutoToggleUI(data.enabled);
            });
        });
        // ── Fix mime type mismatch ────────────────────────────────
        $('#imp-fix-mime-mismatch').on('click', function() {
            var $btn = $(this);
            var $res = $('#imp-fix-mime-result');
            $btn.prop('disabled', true).text(L.btn_repairing || '⏳ Repairing...');
            $res.hide();
            ajax('tso_im_fix_mime_mismatch', {}, function(data) {
                $btn.prop('disabled', false).text(uiText('mime_fix_btn', '🔧 Fix incorrect mime types'));
                var mimeMsg = data.fixed > 0
                    ? '✓ ' + data.fixed + ' ' + uiText('mime_fixed', 'attachments repaired.')
                    : '✓ ' + uiText('mime_no_issues', 'No issues found. All attachments are correct.');
                $res.data('kind', data.fixed > 0 ? 'mime_fixed' : 'mime_no_issues').data('count', (data.fixed || 0));
                $res.show().css('color', 'var(--imp-success)').text(mimeMsg);
            }, function(err) {
                $btn.prop('disabled', false).text(uiText('mime_fix_btn', '🔧 Fix incorrect mime types'));
                $res.removeData('kind').removeData('count');
                $res.show().css('color', 'var(--imp-danger)').text((L.error_prefix || 'Error: ') + err);
            });
        });

        // ── Ghost attachments ─────────────────────────────────────
        var ghostSelected = new Set();

        $('#imp-scan-ghosts').on('click', function() {
            var $btn = $(this);
            var $res = $('#imp-ghost-scan-result');
            $btn.prop('disabled', true).text(L.btn_scanning || '⏳ Scanning...');
            $res.hide();
            $('#imp-ghost-list').hide().empty();
            $('#imp-ghost-actions').hide();
            ghostSelected.clear();

            ajax('tso_im_find_ghost_attachments', {}, function(data) {
                $btn.prop('disabled', false).text('🔍 ' + uiText('scan_ghosts', 'Scan ghost attachments'));
                if (!data.total) {
                    $res.data('kind', 'ghost_none').data('count', 0);
                    $res.show().css('color', 'var(--imp-success)').html('✓ ' + uiText('no_ghosts', 'No ghost attachments found.'));
                    return;
                }
                $res.data('kind', 'ghost_found').data('count', data.total);
                $res.show().css('color', 'var(--imp-warn)').text(data.total + ' ' + (data.total > 1 ? uiText('ghost_found_plural', 'ghost attachments found.') : uiText('ghost_found_singular', 'ghost attachment found.')));
                renderGhostList(data.ghosts);
                $('#imp-ghost-list').show();
                $('#imp-ghost-actions').css('display', 'flex');
            }, function(err) {
                $btn.prop('disabled', false).text('🔍 ' + uiText('scan_ghosts', 'Scan ghost attachments'));
                $res.removeData('kind').removeData('count');
                $res.show().css('color', 'var(--imp-danger)').text((L.error_prefix || 'Error: ') + err);
            });
        });

        function renderGhostList(ghosts) {
            var $list = $('#imp-ghost-list');
            $list.empty();
            ghosts.forEach(function(g) {
                var $row = $('<div class="imp-ghost-row" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--imp-border);">' +
                    '<input type="checkbox" class="imp-ghost-check" data-id="' + g.id + '" style="cursor:pointer;">' +
                    '<span style="font-family:var(--imp-mono);font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtml(g.meta_path) + '">' + escHtml(g.filename) + '</span>' +
                    '<span style="font-size:11px;color:var(--imp-danger);flex-shrink:0;">' + escHtml(g.reason) + '</span>' +
                    '</div>');
                $list.append($row);
            });

            // Checkbox toggle
            $list.off('change', '.imp-ghost-check').on('change', '.imp-ghost-check', function() {
                var id = parseInt($(this).data('id'), 10);
                if ($(this).is(':checked')) ghostSelected.add(id);
                else ghostSelected.delete(id);
                updateGhostToolbar();
            });
            updateGhostToolbar();
        }

        function updateGhostToolbar() {
            $('#imp-delete-ghosts').prop('disabled', ghostSelected.size === 0);
        }

        $('#imp-ghost-select-all').on('click', function() {
            $('#imp-ghost-list .imp-ghost-check').prop('checked', true).each(function() {
                ghostSelected.add(parseInt($(this).data('id'), 10));
            });
            updateGhostToolbar();
        });

        $('#imp-ghost-deselect').on('click', function() {
            $('#imp-ghost-list .imp-ghost-check').prop('checked', false);
            ghostSelected.clear();
            updateGhostToolbar();
        });

        $('#imp-delete-ghosts').on('click', function() {
            if (!ghostSelected.size) return;
            if (!confirm((L.confirm_delete_ghosts || 'Delete') + ' ' + ghostSelected.size + ' ' + (L.ghost_confirm_suffix || 'ghost attachment(s)? This removes the database records.'))) return;
            var $btn = $(this);
            var $res = $('#imp-ghost-delete-result');
            $btn.prop('disabled', true).text(L.btn_deleting || '⏳ Deleting...');
            $res.hide();
            ajax('tso_im_delete_ghost_attachments', { ids: Array.from(ghostSelected) }, function(data) {
                $btn.prop('disabled', false).text('🗑 ' + uiText('delete_ghosts', 'Delete selected'));
                var msg = '✓ ' + data.deleted + ' ' + (L.ghost_deleted_ok || 'deleted successfully.');
                if (data.errors && data.errors.length) msg += ' ' + (L.errors_label || 'Errors:') + ' ' + data.errors.join(', ');
                $res.data('kind', 'ghost_deleted').data('count', data.deleted || 0);
                $res.show().css('color', data.errors && data.errors.length ? 'var(--imp-warn)' : 'var(--imp-success)').html(msg);
                ghostSelected.clear();
                // Remove deleted rows from list
                $('#imp-ghost-list .imp-ghost-check').each(function() {
                    var id = parseInt($(this).data('id'), 10);
                    if (!ghostSelected.has(id)) $(this).closest('.imp-ghost-row').fadeOut(300, function() { $(this).remove(); });
                });
                $('#imp-ghost-scan-result').text(($('#imp-ghost-list .imp-ghost-row').length - data.deleted) + ' ' + (L.remaining_label || 'remaining') + '.');
                updateGhostToolbar();
            }, function(err) {
                $btn.prop('disabled', false).text('🗑 ' + uiText('delete_ghosts', 'Delete selected'));
                $res.show().css('color', 'var(--imp-danger)').text((L.error_prefix || 'Error: ') + err);
            });
        });
    }

    function loadAutoHistory() {
        ajax('tso_im_get_history', { page: autoHistState.page, per_page: autoHistState.perPage, action_type: 'auto_optimize' }, function(data) {
            var wrap = $('#imp-auto-history-wrap');
            if (!data.items || !data.items.length) {
                wrap.html('<div class="imp-loading">' + (L.no_auto_history || 'No auto-optimization entries.') + '</div>');
                $('#imp-auto-history-pagination').empty();
                return;
            }
            var html = '<table class="imp-history-table"><thead><tr><th style="width:56px"></th><th>' + uiText('hdr_file', 'File') + '</th><th>' + uiText('hdr_size', 'Size') + '</th><th>' + uiText('hdr_savings', 'Savings') + '</th><th>' + uiText('hdr_date', 'Date') + '</th></tr></thead><tbody>';
            data.items.forEach(function(item) {
                var d     = item.details || {};
                var thumb = item.thumb ? '<img src="' + escHtml(item.thumb) + '" style="width:44px;height:44px;object-fit:cover;border-radius:4px;">' : '<div style="width:44px;height:44px;background:var(--imp-surface2);border-radius:4px;"></div>';
                html += '<tr><td>' + thumb + '</td><td style="font-family:var(--imp-mono);font-size:12px;word-break:break-word;white-space:normal;min-width:160px;">' + escHtml(d.filename || '—') + '</td><td>' + (d.new_size ? formatBytes(d.new_size) : '—') + '</td><td style="color:var(--imp-success);white-space:nowrap;">' + (d.savings_pct ? d.savings_pct.toFixed(1) + '%' : '—') + '</td><td style="color:var(--imp-text-muted);font-size:12px;white-space:nowrap;">' + escHtml(item.created_at_h || '—') + '</td></tr>';
            });
            html += '</tbody></table>';
            wrap.html(html);
            renderPagination('#imp-auto-history-pagination', autoHistState.page, data.total_pages, function(p) { autoHistState.page = p; loadAutoHistory(); });
        }, function() { $('#imp-auto-history-wrap').html('<div class="imp-loading">' + (L.auto_hist_error || 'Error loading history.') + '</div>'); });
    }

    function loadAutoSettings() {
        ajax('tso_im_get_auto_settings', {}, function(data) {
            $('#imp-auto-enabled').prop('checked', !!data.enabled);
            $('#imp-auto-format').val(data.format || 'webp').trigger('change');
            $('#imp-auto-quality').val(data.quality || 82);
            $('#imp-auto-quality-val').text(data.quality || 82);
            $('.imp-auto-src-format').prop('checked', false);
            (data.source_formats || ['jpg', 'png', 'webp']).forEach(function(fmt) {
                $('.imp-auto-src-format[value="' + fmt + '"]').prop('checked', true);
            });
            $('#imp-auto-fill-alt').prop('checked', !!data.fill_alt_on_upload);
            $('#imp-auto-skip-kb').val(data.skip_small_kb || 0);
            updateAutoToggleUI(!!data.enabled);
        });
    }

    function updateAutoToggleUI(enabled) {
        if (enabled) {
            $('#imp-auto-status-label').text(L.auto_enabled  || 'Auto-optimization ENABLED');
            $('#imp-auto-status-desc').text(L.auto_desc_enabled  || 'New images will be optimized automatically.');
        } else {
            $('#imp-auto-status-label').text(L.auto_disabled || 'Auto-optimization disabled');
            $('#imp-auto-status-desc').text(L.auto_desc_disabled || 'Enable to optimize automatically.');
        }
    }

    // ================================================================
    // HISTORY TAB
    // ================================================================
    var histState = { page: 1, perPage: 50 };

    function initHistoryTab() {
        var today = new Date().toISOString().slice(0, 10);
        $('#imp-history-date-from').val(today);
        $('#imp-history-date-to').val(today);
        loadHistoryRetention();
        $('#imp-history-retention-save').on('click', function() {
            var $err = $('#imp-retention-error');
            $err.hide().text('');
            ajax('tso_im_save_history_retention', {
                days: $('#imp-history-retention-days').val(),
                interval: $('#imp-history-purge-interval').val()
            }, function(data) {
                applyHistoryRetention(data);
                $('#imp-retention-saved').text(uiText('retention_saved', 'Saved!')).show().delay(2000).fadeOut();
            }, function(err) {
                $err.text(err || uiText('retention_invalid', 'Invalid value.')).show();
            });
        });
        $(document).on('click', '.imp-tab[data-tab="history"]', function() {
            loadHistoryStats('#imp-history-stats');
            histState.page = 1;
            loadHistory();
        });
        $('#imp-history-load').on('click', function() { histState.page = 1; loadHistory(); });
        $('#imp-history-clear-30').on('click', function() {
            if (!confirm(L.confirm_clean_30 || 'Delete entries older than 30 days?')) return;
            ajax('tso_im_clear_history', { days: 30 }, function() { loadHistory(); loadHistoryStats('#imp-history-stats'); });
        });
        $('#imp-history-clear-all').on('click', function() {
            if (!confirm(L.confirm_clean_all || 'Delete ALL history?')) return;
            ajax('tso_im_clear_history', { days: 0 }, function() {
                $('#imp-history-table-wrap').html('<div class="imp-loading">' + (L.history_empty || 'History empty.') + '</div>');
                $('#imp-history-pagination').empty();
                loadHistoryStats('#imp-history-stats');
            });
        });
    }

    function applyHistoryRetention(data) {
        if (!data) return;
        if (typeof data.days !== 'undefined') {
            $('#imp-history-retention-days').val(data.days);
        }
        if (data.interval) {
            var $interval = $('#imp-history-purge-interval');
            $interval.val(data.interval).trigger('change');
            var api = $interval.closest('.imp-csel').data('impCselApi');
            if (api && api.sync) api.sync();
        }
    }

    function loadHistoryRetention() {
        ajax('tso_im_get_history_retention', {}, function(data) {
            applyHistoryRetention(data);
        });
    }

    function loadHistory() {
        var wrap = $('#imp-history-table-wrap');
        wrap.html('<div class="imp-loading">' + (L.loading_data || 'Loading...') + '</div>');
        ajax('tso_im_get_history', {
            page: histState.page, per_page: histState.perPage,
            action_type: $('#imp-history-filter-type').val(),
            search:      $('#imp-history-search').val(),
            date_from:   $('#imp-history-date-from').val(),
            date_to:     $('#imp-history-date-to').val()
        }, function(data) {
            renderHistoryTable(data.items);
            renderPagination('#imp-history-pagination', data.page, data.total_pages, function(p) { histState.page = p; loadHistory(); });
        }, function(err) { wrap.html('<div class="imp-loading">' + (L.error_prefix || 'Error: ') + err + '</div>'); });
    }

    function renderHistoryTable(items) {
        var wrap = $('#imp-history-table-wrap');
        if (!items.length) { wrap.html('<div class="imp-loading">' + (L.no_history || 'No entries found.') + '</div>'); return; }
        var html = '<div class="imp-history-table-container"><table class="imp-history-table"><thead><tr><th>' + uiText('hdr_image', 'Image') + '</th><th>' + uiText('hdr_file', 'File') + '</th><th>' + uiText('hdr_action', 'Action') + '</th><th>' + uiText('hdr_details', 'Details') + '</th><th>' + uiText('hdr_user', 'User') + '</th><th>' + uiText('hdr_date', 'Date') + '</th></tr></thead><tbody>';
        items.forEach(function(item) {
            var actionLabelMap = {
                optimize: uiText('action_optimize', 'Optimized'),
                auto_optimize: uiText('action_auto_optimize', 'Auto-optimized'),
                rename: uiText('action_rename', 'Renamed'),
                seo_update: uiText('action_seo_update', 'SEO updated'),
                delete: uiText('action_delete', 'Deleted'),
                pdf_compress: uiText('action_pdf_compress', 'PDF compressed')
            };
            var d    = item.details || {};
            var details = '';
            if (d.savings_pct)  details += '<span class="imp-history-savings">-' + d.savings_pct + '%</span> (' + formatBytes(d.savings_bytes || 0) + ')';
            if (d.old_filename && d.new_filename) details += '<span style="color:var(--imp-text-muted);display:block;font-size:11px">' + escHtml(d.old_filename) + '</span><span style="color:var(--imp-accent2);display:block;font-size:11px">\u2192 ' + escHtml(d.new_filename) + '</span>';
            if (!details) details = '<span style="color:var(--imp-text-muted)">—</span>';
            var thumb = item.thumb ? '<img class="imp-thumb-sm" src="' + escHtml(item.thumb) + '" alt="">' : '<span style="font-size:20px">📄</span>';
            var displayFile = d.filename || (d.new_filename || '—');
            var actionLabel = actionLabelMap[item.action_type] || item.action_label || item.action_type || '—';
            var userLabel = (item.user_name === 'Sistema') ? uiText('system_user', 'System') : item.user_name;
            html += '<tr><td>' + thumb + '</td><td style="font-family:var(--imp-mono);font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtml(displayFile) + '">' + escHtml(displayFile) + '</td><td><span class="imp-history-action ' + escHtml(item.action_type) + '">' + escHtml(actionLabel) + '</span></td><td style="max-width:220px">' + details + '</td><td style="font-size:12px;color:var(--imp-text-muted)">' + escHtml(userLabel) + '</td><td style="font-size:12px;color:var(--imp-text-muted);white-space:nowrap">' + escHtml(item.created_at_h) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        wrap.html(html);
    }

    function loadHistoryStats(selector) {
        ajax('tso_im_get_history_stats', {}, function(data) {
            var $el  = $(selector);
            var html = '<div class="imp-stat-card"><span class="imp-stat-label">' + uiText('stat_total', 'Total operations') + '</span><span class="imp-stat-val">' + data.total_operations + '</span></div>' +
                       '<div class="imp-stat-card"><span class="imp-stat-label">' + uiText('stat_saved', 'Space freed') + '</span><span class="imp-stat-val" style="color:var(--imp-success)">' + data.total_saved_h + '</span></div>';
            var types = {
                optimize: uiText('filter_optimize', '⚡ Optimized'),
                auto_optimize: uiText('filter_auto', '🤖 Auto-opt.'),
                rename: uiText('filter_rename', '✏ Renamed'),
                seo_update: uiText('filter_seo', '🏷 SEO'),
                pdf_compress: uiText('filter_pdf', '📄 PDFs')
            };
            Object.keys(types).forEach(function(key) {
                var cnt = (data.by_type || {})[key] || 0;
                html += '<div class="imp-stat-card"><span class="imp-stat-label">' + types[key] + '</span><span class="imp-stat-val">' + cnt + '</span></div>';
            });
            $el.html(html);
        });
    }

    // ================================================================
    // URL FIXER TAB
    // ================================================================
    function initUrlFixer() {
        var urlIssues       = [];
        var urlSelected     = new Set();
        var urlRemoveSelected = new Set();

        function renderUrlSummary(summary) {
            if (!summary) return;
            var summHtml = '<div class="imp-stat-box"><span class="imp-stat-label">' + uiText('posts_scanned', 'Posts scanned') + '</span><span class="imp-stat-val">' + summary.total_posts_scanned + '</span></div>' +
                           '<div class="imp-stat-box"><span class="imp-stat-label">' + uiText('broken_urls', 'Broken URLs') + '</span><span class="imp-stat-val" style="color:var(--imp-danger)">' + summary.total + '</span></div>' +
                           '<div class="imp-stat-box"><span class="imp-stat-label">' + uiText('fixable_urls', 'Auto-fixable') + '</span><span class="imp-stat-val" style="color:var(--imp-success)">' + summary.fixable + '</span></div>' +
                           '<div class="imp-stat-box"><span class="imp-stat-label">' + uiText('removable_label', 'removable') + '</span><span class="imp-stat-val" style="color:var(--imp-warn)">' + (summary.removable || 0) + '</span></div>';
            $('#imp-url-summary').html(summHtml);
        }

        refreshUrlFixerUi = function() {
            if (!$('#tab-urlfixer').hasClass('active') || !$('#imp-url-result').is(':visible')) return;
            renderUrlSummary($('#imp-url-summary').data('summary'));
            if (!urlIssues.length) {
                $('#imp-url-toolbar').hide();
                $('#imp-url-list').html('<div class="imp-loading" style="padding:40px 0">✓ ' + uiText('url_all_ok', 'No broken URLs found!') + '</div>');
                return;
            }
            $('#imp-url-toolbar').show();
            renderUrlList();
            updateUrlToolbar();
        };

        $('#imp-scan-urls').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).text(uiText('btn_scanning', '⏳ Scanning...'));
            $('#imp-url-result').hide();
            $('#imp-url-loading').show();
            ajax('tso_im_scan_url_issues', {}, function(data) {
                btn.prop('disabled', false).text(uiText('scan_web', '🔍 Scan entire site'));
                $('#imp-url-loading').hide();
                urlIssues    = data.issues || [];
                urlSelected  = new Set();
                urlRemoveSelected = new Set();
                $('#imp-url-summary').data('summary', {
                    total_posts_scanned: data.total_posts_scanned || 0,
                    total: data.total || 0,
                    fixable: data.fixable || 0,
                    removable: data.removable || 0
                });
                renderUrlSummary($('#imp-url-summary').data('summary'));
                $('#imp-url-result').show();
                if (!urlIssues.length) {
                    $('#imp-url-toolbar').hide();
                    $('#imp-url-list').html('<div class="imp-loading" style="padding:40px 0">✓ ' + uiText('url_all_ok', 'No broken URLs found!') + '</div>');
                    return;
                }
                $('#imp-url-toolbar').show();
                renderUrlList();
                updateUrlToolbar();
            }, function(err) {
                btn.prop('disabled', false).text(uiText('scan_web', '🔍 Scan entire site'));
                $('#imp-url-loading').hide();
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        $('#imp-url-select-all').on('click', function() {
            urlSelected = new Set();
            urlIssues.forEach(function(issue) { if (issue.has_fix) urlSelected.add(issue.old_url); });
            $('.imp-url-row.fixable').addClass('selected');
            updateUrlToolbar();
        });
        $('#imp-url-select-removable').on('click', function() {
            urlRemoveSelected = new Set();
            urlIssues.forEach(function(issue) { if (!issue.has_fix) urlRemoveSelected.add(issue.old_url); });
            $('.imp-url-row.removable').addClass('selected');
            updateUrlToolbar();
        });
        $('#imp-url-deselect').on('click', function() {
            urlSelected = new Set();
            urlRemoveSelected = new Set();
            $('.imp-url-row').removeClass('selected');
            updateUrlToolbar();
        });
        $(document).on('click', '.imp-url-row.fixable', function() {
            var url = $(this).data('url');
            if (urlSelected.has(url)) { urlSelected.delete(url); $(this).removeClass('selected'); }
            else                      { urlSelected.add(url);    $(this).addClass('selected');    }
            updateUrlToolbar();
        });
        $(document).on('click', '.imp-url-row.removable', function() {
            var url = $(this).data('url');
            if (urlRemoveSelected.has(url)) { urlRemoveSelected.delete(url); $(this).removeClass('selected'); }
            else                            { urlRemoveSelected.add(url);    $(this).addClass('selected');    }
            updateUrlToolbar();
        });
        $('#imp-fix-urls').on('click', function() {
            if (!urlSelected.size) return;
            if (!confirm(uiText('fix_selected', 'Fix') + ' ' + urlSelected.size + ' ' + uiText('url_items_suffix', 'URL(s)?'))) return;
            var btn = $(this);
            btn.prop('disabled', true).text(uiText('url_fixing', '⏳ Fixing...'));
            var fixes = [];
            urlIssues.forEach(function(issue) {
                if (urlSelected.has(issue.old_url) && issue.has_fix) fixes.push({ old_url: issue.old_url, new_url: issue.new_url });
            });
            ajax('tso_im_fix_url_issues', { fixes: fixes }, function(data) {
                btn.prop('disabled', false).text(uiText('fix_selected', '✓ Fix selected'));
                var msg = '✓ ' + data.fixed + ' ' + uiText('url_fixed_ok', 'URLs fixed.');
                if (data.skipped) msg += ' (' + data.skipped + ' ' + uiText('skipped_suffix', 'skipped') + ')';
                if (data.errors && data.errors.length) msg += '\n' + uiText('errors_label', 'Errors:') + '\n' + data.errors.join('\n');
                alert(msg);
                urlIssues    = urlIssues.filter(function(i) { return !urlSelected.has(i.old_url); });
                urlSelected  = new Set();
                renderUrlList();
                updateUrlToolbar();
                if (!urlIssues.length) {
                    $('#imp-url-toolbar').hide();
                    $('#imp-url-list').html('<div class="imp-loading" style="padding:40px 0">✓ ' + uiText('url_all_ok', 'All URLs fixed!') + '</div>');
                }
            }, function(err) { btn.prop('disabled', false).text(uiText('fix_selected', '✓ Fix selected')); alert((L.error_prefix || 'Error: ') + err); });
        });

        $('#imp-remove-urls').on('click', function() {
            if (!urlRemoveSelected.size) return;
            if (!confirm(uiText('confirm_remove_urls', 'Remove selected broken URL references from content?'))) return;
            var btn = $(this);
            btn.prop('disabled', true).text(uiText('url_removing', '⏳ Removing...'));
            var urls = Array.from(urlRemoveSelected);
            ajax('tso_im_remove_url_issues', { urls: urls }, function(data) {
                btn.prop('disabled', false).text(uiText('remove_selected', '🗑 Remove from content'));
                var msg = '✓ ' + data.removed + ' ' + uiText('url_removed_ok', 'URL references removed.');
                if (data.skipped) msg += ' (' + data.skipped + ' ' + uiText('skipped_suffix', 'skipped') + ')';
                if (data.errors && data.errors.length) msg += '\n' + uiText('errors_label', 'Errors:') + '\n' + data.errors.join('\n');
                alert(msg);
                urlIssues = urlIssues.filter(function(i) { return !urlRemoveSelected.has(i.old_url); });
                urlRemoveSelected = new Set();
                var summary = $('#imp-url-summary').data('summary') || {};
                summary.total = urlIssues.length;
                summary.fixable = urlIssues.filter(function(i) { return i.has_fix; }).length;
                summary.removable = urlIssues.filter(function(i) { return !i.has_fix; }).length;
                $('#imp-url-summary').data('summary', summary);
                renderUrlSummary(summary);
                renderUrlList();
                updateUrlToolbar();
                if (!urlIssues.length) {
                    $('#imp-url-toolbar').hide();
                    $('#imp-url-list').html('<div class="imp-loading" style="padding:40px 0">✓ ' + uiText('url_all_ok', 'All URLs fixed!') + '</div>');
                }
            }, function(err) {
                btn.prop('disabled', false).text(uiText('remove_selected', '🗑 Remove from content'));
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        function renderUrlList() {
            var html = '';
            urlIssues.forEach(function(issue) {
                var fixable  = issue.has_fix;
                var selClass = (fixable ? urlSelected.has(issue.old_url) : urlRemoveSelected.has(issue.old_url)) ? ' selected' : '';
                var cls      = fixable ? ' fixable' + selClass : ' removable no-fix' + selClass;
                var badge = issue.type === 'outdated'
                    ? '<span class="imp-url-badge imp-url-badge-outdated">🔄 ' + uiText('url_outdated_badge', 'Obsolete thumbnail') + '</span>'
                    : (issue.type === 'missing'
                        ? '<span class="imp-url-badge imp-url-badge-fix">⚠️ ' + uiText('url_missing_badge', 'Missing file \u2014 alternative found') + '</span>'
                        : '<span class="imp-url-badge imp-url-badge-broken">❌ ' + uiText('url_broken_badge', 'Missing \u2014 no fix') + '</span>');
                var postsHtml = issue.posts.map(function(p) {
                    var editUrl = TSOIMMA.site_url + '/wp-admin/post.php?post=' + p.id + '&action=edit';
                    return '<a href="' + escHtml(editUrl) + '" target="_blank" rel="noopener" class="imp-url-post-link">' + escHtml(p.title || (uiText('post_label', 'Post') + ' #' + p.id)) + '</a>';
                }).join('');
                html += '<div class="imp-url-row' + cls + '" data-url="' + escHtml(issue.old_url) + '">' +
                    '<div class="imp-url-row-header">' + badge + '<span class="imp-url-occ">' + issue.occurrences + ' ' + (issue.occurrences > 1 ? uiText('occurrences', 'occurrences') : uiText('occurrence', 'occurrence')) + '</span></div>' +
                    '<div class="imp-url-paths">' +
                    '<div class="imp-url-path imp-url-path-bad"><span class="imp-url-ext-badge" style="background:rgba(255,77,109,.2);color:var(--imp-danger)">.' + escHtml(issue.old_ext) + '</span><span class="imp-url-fname">' + escHtml(issue.filename) + '.' + escHtml(issue.old_ext) + '</span><span class="imp-url-label">' + uiText('url_content_label', 'URL in content (obsolete)') + '</span></div>' +
                    (fixable
                        ? '<div class="imp-url-path imp-url-path-good"><span class="imp-url-ext-badge" style="background:rgba(6,214,160,.2);color:var(--imp-success)">.' + escHtml(issue.new_ext) + '</span><span class="imp-url-fname">' + escHtml(issue.new_filename || issue.filename) + '.' + escHtml(issue.new_ext) + '</span><span class="imp-url-label">' + uiText('url_correct_label', 'Correct URL (file exists)') + '</span></div>'
                        : '<div class="imp-url-path imp-url-path-nofix"><span class="imp-url-label" style="color:var(--imp-text-muted)">' + uiText('url_no_fix_label', 'No alternative found.') + '</span></div>') +
                    '</div>' +
                    '<div class="imp-url-posts">' + uiText('appears_in', 'Appears in') + ': ' + postsHtml + '</div>' +
                    (fixable
                        ? '<div class="imp-url-select-hint">👆 ' + uiText('url_click_select', 'Click to select') + '</div>'
                        : '<div class="imp-url-select-hint">👆 ' + uiText('url_click_select_remove', 'Click to select for removal') + '</div>') +
                    '</div>';
            });
            $('#imp-url-list').html(html || '<div class="imp-loading">' + uiText('url_no_results', 'No results.') + '</div>');
        }

        function updateUrlToolbar() {
            var fixableCount   = urlIssues.filter(function(i) { return i.has_fix; }).length;
            var removableCount = urlIssues.filter(function(i) { return !i.has_fix; }).length;
            var selFix         = urlSelected.size;
            var selRemove      = urlRemoveSelected.size;
            $('#imp-url-count').text(
                selFix + ' ' + uiText('selected_of', 'selected of') + ' ' + fixableCount + ' ' + uiText('fixable_label', 'fixable') +
                ' · ' + selRemove + ' ' + uiText('selected_of', 'selected of') + ' ' + removableCount + ' ' + uiText('removable_label', 'removable')
            );
            $('#imp-fix-urls').prop('disabled', selFix === 0);
            $('#imp-remove-urls').prop('disabled', selRemove === 0);
        }
    }

})(jQuery);
