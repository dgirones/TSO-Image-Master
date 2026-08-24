=== TSO Image Master ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: image optimization, webp, media library, seo, pdf compression
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.10.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete image optimization suite: convert to WebP/JPG, resize, compress PDFs, find orphans, fix broken URLs.

== Description ==

TSO Image Master is a complete media management and image optimization plugin for WordPress. It provides the following features from a single admin screen:

**Image Optimizer** — Convert images to WebP or JPG, set quality and dimensions, replace originals and automatically update all content links. Supports bulk operations. Requires the PHP GD library with WebP support.

**Orphaned Image Finder** — Detects images in the Media Library that are not referenced in any post, page, widget, meta field, or theme customizer setting. Supports paginated batch scanning to avoid timeouts on large sites.

**Rogue File Scanner** — Scans the uploads directory for physical files that WordPress does not know about: double-extension files (e.g. image.jpg.webp), plugin backup files, temporary files, and other unregistered images that waste disk space.

**SEO & File Names** — Edit title, alt text, caption, and description for any image. Rename files using SEO-friendly slugs (lowercase, no accents, hyphens instead of spaces). All internal links are updated automatically.

**PDF Compressor** — Reduce the file size of PDFs in the Media Library using GhostScript (recommended) or the Imagick PHP extension as a fallback. The original URL never changes.

**Auto-Optimizer** — Automatically optimize every new image on upload using the configured format and quality. Uses a transient-based mechanism to ensure each image is processed only once and regenerations do not trigger re-optimization.

**History** — Full audit log of all operations performed by the plugin: optimizations, renames, SEO updates, PDF compressions, and reversions. Filterable by action type, date range, and filename. Configurable automatic cleanup.

**URL Fixer** — Scans all public content types (posts, pages, and custom post types such as portfolio or slides) for broken image URLs caused by format conversions (e.g. references to .jpg files that have been converted to .webp). Renders blocks and shortcodes when needed so embedded images are detected. Automatically finds the correct replacement and updates the database in one click.

**Cache Compatibility** — After any operation that modifies file URLs or content, the plugin automatically purges LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Fastest Cache when they are active.

= Requirements =

* PHP 7.4 or higher (tested up to 8.3)
* WordPress 5.9 or higher (tested up to 7.1)
* PHP GD library with JPEG, PNG, GIF, and WebP support
* GhostScript (optional, required for PDF compression)
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

Only if you check the "Replace original" option. When replacing, the plugin saves a backup copy in a dedicated folder inside your uploads directory: `wp-content/uploads/tso-image-master/`. The backup file is named `originalname_tso_im_backup.ext`. You can revert to the backup at any time from the editor modal, or delete it to free disk space. Backup files are never stored inside the plugin folder.

= What happens to my images if I uninstall the plugin? =

Uninstalling the plugin removes the following data:

* **Database:** plugin options, the custom history table (`wp_tso_im_history`), all plugin postmeta keys, and scheduled cron events.
* **Backup folder:** the `wp-content/uploads/tso-image-master/` folder and all backup copies inside it are deleted.
* **Original images:** your actual image files in the uploads folder are **never** deleted. Only the plugin-created backup copies are removed.

= Does the plugin work with caching plugins? =

Yes. After any operation that modifies content or file URLs, the plugin automatically calls the purge functions of LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Fastest Cache when they are installed and active.

= Can I use the plugin on a multisite installation? =

The plugin has not been explicitly tested on multisite. It is designed for standard single-site WordPress installations.

= The PDF compressor does not work. What should I do? =

PDF compression requires GhostScript or the Imagick PHP extension to be available on your server. The plugin indicates which engines are available at the top of the PDF tab. Contact your hosting provider to install GhostScript for best results.

= I optimized an image and the new format weighs more than the original. What happened? =

This can happen with images that are already well-optimized, very small images, or images with a lot of transparency or detail. The plugin will show a warning in this case. You can revert to the original using the backup.

== Screenshots ==

1. Image Optimizer tab with search, bulk actions, and format/quality controls.

2. Auto-Optimizer tab with upload automation settings and maintenance tools.

3. Per-image Optimize modal with output format, resize, and replace-original options.

== Changelog ==

For the full release history, see CHANGELOG.txt in the plugin folder.

= 1.10.1 =
* Fixed: Auto settings save now persists fill-alt-on-upload and skip-small-file options.
* Fixed: Media Library bulk optimize hook names, image-only filtering, and success notice on upload screen.
* Fixed: Queue status excludes cancelled jobs; skips duplicate pending attachment IDs.
* Fixed: AVIF load/save fallback, backup delete verification, and deep-link opens Optimize tab.
* Improved: Dashboard alt filter and duplicate scan use fast reference checks (avoids timeouts).
* Improved: Queue polling on dashboard load; backup directory scan handles permission errors.

= 1.10.0 =
* Added: Background job queue for bulk optimize (WP-Cron, 5 images per batch).
* Added: TSO backup retention (max age + max total size) with daily purge cron.
* Added: AVIF output format when GD supports `imageavif()`.
* Added: Duplicate image scanner (MD5 groups) on Overview tab.
* Added: Auto-upload options — skip small WebP/AVIF files (WP 7.1) and fill missing alt on upload.
* Added: Media Library row action, bulk queue action, and attachment box note.
* Improved: Orphan detection scans FSE templates/parts/patterns, term meta, and menu items.
* Improved: Cache purge also clears Breeze, SiteGround Optimizer, and Autoptimize when present.

= 1.9.3 =
* Added: Overview dashboard tab with site health metrics (images, missing alt, backups, space saved, engines).
* Added: Missing/generic alt audit with bulk fill from suggested title or filename.

= 1.9.2 =
* Improved: WordPress 7.1 compatibility (readme and tested declaration).
* Docs: older changelog entries moved to CHANGELOG.txt.

= 1.9.1 =
* Improved: empty folders under `uploads/tso-image-master/` are removed automatically after a backup is deleted (manual delete, revert, attachment delete, rogue scanner).
* Fixed: orphan empty backup directories when backup creation failed or the file was already gone from disk.
* Fixed: delete-backup and attachment delete now validate backup paths before removal.

= 1.9.0 =
* Added: URL Fixer — manually remove broken image references from content when no automatic fix is available (img tags, Gutenberg blocks, widgets).
* Improved: history auto-cleanup — separate retention days and check frequency (daily/weekly/monthly); save feedback fixed.
* Improved: clearer revert error when backup no longer matches after a file rename.
* Fixed: image/PDF search matches filename prefix only (e.g. "ar" finds "arbre", not "mar").
* Added: PDF preview modal in the PDFs tab (iframe + open in new tab fallback).
* Improved: Rogue Scanner UI renamed to “extra upload files”; TSO backups shown as informational (not “problematic”).
* Fixed: image rename failed with fatal error (private URL replace method now callable from Image Manager).
* Fixed: history filename search uses prefix match (consistent with image/PDF search).
* Fixed: history retention accepts 1–3650 days (0 = disabled).
* Added: index.php in plugin subdirectories; upgrade hook reschedules history cron on version bump.

== Upgrade Notice ==

= 1.10.1 =
Bugfix release: queue, Media Library bulk action, auto settings, AVIF, and dashboard performance fixes.

= 1.10.0 =
Major release: background bulk queue, backup retention, AVIF, duplicate scanner, auto-alt on upload, and Media Library integration.

= 1.9.3 =
New Overview tab: health metrics plus bulk alt-text fill for images missing accessible alt.

= 1.9.2 =
Compatibility update: tested with WordPress 7.1 (no code changes required).

= 1.9.1 =
Cleans up empty backup folders under uploads/tso-image-master after backup deletion; safer backup path validation.

= 1.9.0 =
Major stability release: reliable image conversion, accurate backup detection, URL repair across posts and widgets, and safer rename/revert flows.
