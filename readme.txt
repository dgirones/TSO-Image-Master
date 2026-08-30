=== TSO Image Master ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: image optimization, webp, avif, media library, pdf compression
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.9.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete image optimization suite: WebP/AVIF/JPG/PNG, PDFs, orphans, SEO, queue, and URL repair.

== Description ==

TSO Image Master is a complete media management and image optimization plugin for WordPress. It provides the following features from a single admin screen:

**Overview** — Site health metrics (image count, missing alt, TSO backups, space saved, server engines). Bulk-fill missing or weak alt text. Scan for duplicate image files. Configure backup retention and watch the background optimize queue.

**Image Optimizer** — Convert images to WebP, AVIF (when GD supports it), JPG, or PNG. Set quality and dimensions, replace originals, and update content links. Bulk optimize runs in the background via WP-Cron (5 images per batch). Choose how many images to show per page (21–105). Requires PHP GD (WebP strongly recommended).

**Orphaned Image Finder** — Detects Media Library images that are not referenced in posts, pages, widgets, meta, theme Customizer settings, FSE templates/parts/patterns, term meta, or menu items. Paginated batch scanning helps avoid timeouts on large sites.

**Rogue File Scanner** — Scans the uploads directory for physical files WordPress does not know about: double-extension files (e.g. image.jpg.webp), plugin backup files, temporary files, and other unregistered images that waste disk space.

**SEO & File Names** — Edit title, alt text, caption, and description for any image. Rename files using SEO-friendly slugs (lowercase, no accents, hyphens instead of spaces). All internal links are updated automatically.

**PDF Compressor** — Reduce PDF file size in the Media Library using GhostScript (recommended) or the Imagick PHP extension as a fallback. The original URL never changes.

**Auto-Optimizer** — Automatically optimize new uploads with your chosen format and quality. Optional: skip already-small WebP/AVIF files, and fill missing alt text on upload (even when auto-optimize is off). Each image is processed only once so regenerations do not re-trigger optimization.

**Media Library** — Row action and bulk action to queue images for background optimization, plus a short note on the attachment screen.

**History** — Audit log of optimizations, renames, SEO updates (with field details), PDF compressions, and related actions. Filter by action type, date range, and filename. Configurable automatic cleanup.

**URL Fixer** — Scans public content (posts, pages, and custom post types) for broken image URLs after format conversions (e.g. `.jpg` still referenced after conversion to `.webp`). Renders blocks and shortcodes when needed so embedded images are detected. Finds replacements and updates the database in one click; can also remove broken references when no fix exists.

**Cache Compatibility** — After operations that change file URLs or content, the plugin purges LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Fastest Cache, Breeze, SiteGround Optimizer, and Autoptimize when they are active.

= Requirements =

* PHP 7.4 or higher (tested up to 8.3)
* WordPress 6.1 or higher (tested up to 7.1)
* PHP GD library with JPEG, PNG, GIF, and WebP support (AVIF output needs GD with `imageavif()` / `imagecreatefromavif()`)
* GhostScript (optional, required for best PDF compression)
* Imagick PHP extension (optional, fallback for PDF compression)

= Source Code =

The source code for this plugin is entirely human-readable. The file `admin/js/admin.js` is unminified, unobfuscated source code. No build tools are required. All translation strings are passed from PHP via `wp_localize_script()`.

= Translations =

The plugin interface is already translated into the following languages (included in the plugin):

* **English** — default
* **Català (Catalan)** — `ca`
* **Español (Spanish)** — `es_ES`

Bundled `.mo` files also translate the plugin name and description on the WordPress **Plugins** screen when your site language is Catalan or Spanish.

If you would like to contribute a translation into another language, you can do so at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/tso-image-master/).

== Installation ==

1. Upload the `tso-image-master` folder to the `/wp-content/plugins/` directory, or install it directly from the WordPress plugin dashboard.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Image Master** in the admin sidebar to start using the plugin.
4. (Optional) To enable PDF compression, install GhostScript on your server. Ask your hosting provider for assistance if needed.

== Frequently Asked Questions ==

= Does the plugin modify original image files? =

Only if you check the "Replace original" option. When replacing, the plugin saves a backup copy under `wp-content/uploads/tso-image-master/` (mirroring your upload year/month folders when applicable). Backup files use a `_tso_im_backup` name pattern. You can revert from the editor modal or delete backups to free disk space. Empty backup folders are removed automatically after deletion. Backups are never stored inside the plugin folder.

= Can backups be deleted automatically? =

Yes. On the Overview tab you can set backup retention by maximum age (days) and/or maximum total size (MB). A daily cron purges old or oversized TSO backups. Set both to `0` to disable automatic purge. You can also run **Purge now** from Overview.

= What happens to my images if I uninstall the plugin? =

Uninstalling the plugin removes the following data:

* **Database:** plugin options, the custom history table (`wp_*tsoimma_history`), all plugin postmeta keys, and scheduled cron events.
* **Backup folder:** the `wp-content/uploads/tso-image-master/` folder and all backup copies inside it are deleted.
* **Original images:** your actual image files in the uploads folder are **never** deleted. Only the plugin-created backup copies are removed.

= Which output formats are supported? =

**WebP**, **JPG**, **PNG**, and **keep original**. **AVIF** appears when your PHP GD build supports AVIF read/write. If AVIF or WebP is requested but unavailable, the plugin falls back to a supported format.

= Why is AVIF disabled or missing? =

AVIF needs PHP GD compiled with AVIF support (`imageavif` / `imagecreatefromavif`). Many hosts still lack this. Check **Overview → server engines** (GD AVIF). WebP remains the recommended default for broad browser and host support.

= How does bulk / Media Library optimize work? =

Selected images are added to a background queue processed by WP-Cron (about 5 images per batch). Progress appears on the Overview tab; you can cancel pending jobs. You can also queue from the Media Library row action or bulk actions.

= Does the plugin work with caching plugins? =

Yes. After operations that modify content or file URLs, the plugin calls purge helpers for LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Fastest Cache, Breeze, SiteGround Optimizer, and Autoptimize when they are installed and active.

= Can I use the plugin on a multisite installation? =

The plugin has not been explicitly tested on multisite. It is designed for standard single-site WordPress installations.

= The PDF compressor does not work. What should I do? =

PDF compression requires GhostScript or the Imagick PHP extension on your server. The Overview and PDF areas indicate which engines are available. Contact your hosting provider to install GhostScript for best results.

= I optimized an image and the new format weighs more than the original. What happened? =

This can happen with images that are already well-optimized, very small images, or images with a lot of transparency or detail. The plugin will show a warning in this case. You can revert to the original using the backup. On Auto-Optimizer you can optionally skip WebP/AVIF files already under a size threshold (KB).

= How does fill missing alt text work? =

On Overview you can bulk-fill useful alt text for selected images (from title or humanized filename). On Auto-Optimizer you can enable fill-on-upload for new images that lack alt text. Fill-on-upload can run even when auto-optimize is disabled. Generic or weak alt values may be replaced when a better suggestion exists; humanized filename fills are treated as useful.

= What does the duplicate scanner do? =

On Overview, **Scan duplicates** groups Media Library files with the same MD5 hash so you can spot wasted space. It does not delete files automatically; review groups before removing anything.

== Screenshots ==

1. Image Optimizer tab with search, bulk actions, and format/quality controls.

2. Auto-Optimizer tab with upload automation settings and maintenance tools.

3. Per-image Optimize modal with output format, resize, and replace-original options.

== Changelog ==

For release history from 1.0.0 through 1.9.3, see CHANGELOG.txt in the plugin folder.

= 1.9.8 =
* Improved: WordPress.org compliance — canonical `tsoimma_` AJAX/storage layer, prefixed history table, attachment meta migration, sanitized AJAX inputs.
* Improved: Overview alt list — manual edit for numeric/camera filenames, accurate missing-alt counter, used-in column, SEO tab weak-alt detection.
* Improved: Dashboard layout — background queue and backup retention side by side; backup stats cache; queue enqueue mutex.
* Improved: Bulk optimize limits (25 sync / 100 queued); bulk alt fill no longer overwrites good alts.
* Fixed: Weak alt detection with spaces; thumbnail AJAX errors surfaced; Requires at least WordPress 6.1.

= 1.9.7 =
* Improved: Overview alt list — inline edit (save/cancel), one-click accept (✓) for suggested alt, thumbnail hover preview, row removal without reloading the whole dashboard.
* Improved: Alt humanization (Spanish accents, fewer bad filename splits); missing-alt counter after bulk fill; dashboard overview cached until data changes.
* Improved: Search clear (×) buttons; duplicate finder link styling; post editor deep-link highlights attachment in Gutenberg galleries.
* Improved: Optimize modal collapses the resize section after a successful optimization.
* Fixed: Alt save button contrast and spacing; hover preview stuck after bulk alt fill; save blocked when browser autocomplete was open.

= 1.9.6 =
* Improved: Smarter suggested alt text (humanized filenames, EXIF/caption, skips pure numbers and camera noise).
* Improved: Overview alt list shows only images with a fillable suggestion; bulk fill skips unusable names.
* Improved: URL Fixer matches uploads URLs with http/https and encoded paths; safer path resolution.
* Improved: Background queue shows pending vs processing separately; Cancel only affects pending jobs.
* Improved: MIME mismatch and ghost attachment scans paginate in batches (large libraries).
* Improved: Rogue file delete allowlist UX (24h scan note and rescan hint).
* Fixed: Auto-optimize rollback, queue lock TTL/refresh, ghost delete on valid images, history default dates.
* Fixed: Optimize modal thumbnail status (no fake delay; sync fallback without double cron).
* Fixed: Duplicate scan covers full library; backup meta purge without basename collisions.
* Fixed: History logs optimize only when replaced; revert action translated in history table.
* Fixed: Plugin Check LIKE placeholder warnings in maintenance AJAX queries.
* Added: Filter `tsoimma_suggest_alt_text` for custom/AI alt enrichment.

= 1.9.5 =
* Added: Background job queue for bulk optimize (WP-Cron, 5 images per batch).
* Added: TSO backup retention (max age + max total size) with daily purge cron.
* Added: AVIF and PNG output formats (AVIF when GD supports `imageavif()`).
* Added: Duplicate image scanner (MD5 groups) on Overview tab.
* Added: Auto-upload options — skip small WebP/AVIF files (WP 7.1) and fill missing alt on upload.
* Added: Media Library row action, bulk queue action, and attachment box note.
* Added: Per-page selector (21/35/49/70/105) on Optimize and SEO grids.
* Improved: History shows SEO field details (title/alt/caption/description); History tab moved to end of nav.
* Improved: Orphan detection scans FSE templates/parts/patterns, term meta, and menu items.
* Improved: Cache purge also clears Breeze, SiteGround Optimizer, and Autoptimize when present.
* Fixed: Auto settings save persists fill-alt-on-upload and skip-small-file options.
* Fixed: Media Library bulk optimize hooks, image-only filtering, and upload-screen notice.
* Fixed: Queue status excludes cancelled jobs; skips duplicate pending attachment IDs.
* Fixed: AVIF load/save fallback, backup delete verification, and deep-link opens Optimize tab.
* Improved: Dashboard alt filter and duplicate scan use fast reference checks (avoids timeouts).
* Improved: Queue polling on dashboard load; backup directory scan handles permission errors.
* Fixed: Queue lock + per-job status writes (no lost enqueue, no double-process, stuck reclaim).
* Fixed: Backup retention purge clears related attachment meta; purge-now no-op when retention is off.
* Fixed: AVIF thumbnail cleanup and truecolor encode; orphan-meta repair finds `.avif`.
* Fixed: Fill-alt-on-upload works without auto-optimize; weak-alt no longer fights humanized fills.
* Fixed: Uninstall removes all backup meta keys and queue lock option.
* Fixed: History no longer shows attachment title as SEO title on alt-only updates.
* Fixed: PNG compression level clamped to 0–9; invalid output formats normalized.

== Upgrade Notice ==

= 1.9.8 =
Overview alt manual edit, WordPress.org prefix/storage hardening, dashboard UX, and queue/backup layout improvements.

= 1.9.7 =
Overview alt editor UX, hover preview, humanization tweaks, dashboard cache, and optimize modal polish.

= 1.9.6 =
Smarter alt suggestions, stability fixes (queue, rollback, URL fixer, ghost scan), and maintenance scan improvements.

= 1.9.5 =
Queue, backup retention, AVIF/PNG output, duplicate scanner, Media Library integration, history SEO details, and related bug fixes.
