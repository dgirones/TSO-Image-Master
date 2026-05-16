# TSO-Image-Master
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: image optimization, webp, media library, seo, pdf compression
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.5.9
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

**URL Fixer** — Scans all posts and pages for broken image URLs caused by format conversions (e.g. references to .jpg files that have been converted to .webp). Automatically finds the correct replacement and updates the database in one click.

**Cache Compatibility** — After any operation that modifies file URLs or content, the plugin automatically purges LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Fastest Cache when they are active.

= Requirements =

* PHP 7.4 or higher (tested up to 8.3)
* WordPress 5.9 or higher (tested up to 6.9)
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

=== TSO Image Master ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: image optimization, webp, media library, seo, pdf compression
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.5.9
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
* WordPress 5.9 or higher (tested up to 6.9)
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

= 1.5.9 =
* Security: URL Fixer only applies database replacements when both URLs point to the site uploads directory; destination files are resolved with `realpath()` so paths cannot escape uploads.
* Security: Rogue file deletion resolves each path with `realpath()` and requires the file to stay inside `wp-content/uploads`.
* Improved: translations load via `load_textdomain()` with bundled or language-pack `.mo` files (Plugin Check compatibility; avoids discouraged `load_plugin_textdomain()` call).
* Fixed: use `wp_parse_url()` instead of `parse_url()` for error messages (coding standards).

= 1.5.8 =
* Fixed: plugin name and description on the WordPress Plugins screen now appear in Catalan and Spanish when the site language is set accordingly.
* Added: bundled `languages/*.mo` files and early textdomain loading for site-locale translations.
* Fixed: URL Fixer now scans all public custom post types (e.g. portfolio, portfolio-item, diapositivas), not only posts and pages.
* Improved: URL Fixer also inspects rendered block/shortcode output and post excerpts so broken image URLs inside CPT content are detected.
* Improved: URL Fixer summary label now refers to scanned content items instead of posts only.

= 1.5.7 =
* Updated: screenshot descriptions in readme to match the current plugin UI.

= 1.5.6 =
* Updated: version bump to 1.5.6.

= 1.5.5 =
* Fixed: sanitize manual rename input in AJAX handler to satisfy PHPCS while preserving UTF-8 characters (e.g. ç, ñ).

= 1.5.4 =
* Fixed: strict UTF-8 search behavior in image and PDF finders (no false positives with characters like ñ).
* Fixed: manual image transform URL updates for encoded/non-encoded filenames with accents and special characters.
* Fixed: URL Inconsistencies scan/fix handling for UTF-8 paths and encoded URLs.
* Fixed: History and Rogue dynamic UI language refresh after changing plugin language.
* Improved: mobile readability in Rogue and History sections (better card/table layout on small screens).

= 1.5.3 =
* Fixed: PDF compression flow now avoids long indefinite waits with a strict timeout and faster polling.
* Added: automatic GhostScript-to-Imagick fallback in background PDF compression when no output is produced in time.
* Added: pre-checks for encrypted/protected PDFs and already-compressed PDFs to fail fast with clear feedback.
* Added: persistent "not compressible" PDF status with reason/timestamp, including UI badge and disabled re-try button.
* Improved: timeout and error handling now refreshes PDF list immediately to reflect status changes.

= 1.5.2 =
* Added: auto-conversion source format selector in Auto-Optimizer settings (JPG/JPEG, PNG, WEBP, GIF static-only, BMP, TIFF).
* Added: support for auto-optimizing static GIF, BMP and TIFF uploads when selected.
* Improved: robust GIF handling — animated GIFs are never auto-converted, with fail-safe behavior if frame detection cannot be verified.
* Improved: broader mime support for TIFF/TIF detection in auto-optimizer.
* Fixed: consistency of "original format" behavior for BMP/TIFF in auto mode (now safely skipped instead of unexpected fallback).

= 1.5.1 =
* Fixed: complete in-plugin language switching (CA/ES/EN) for dynamic AJAX messages and URL Fixer summaries/lists.
* Fixed: mixed-language residual strings after changing language from Catalan to Spanish/English.
* Fixed: mobile header overlap/cropping in WordPress admin top bar.
* Improved: responsive layout for mobile tabs, header, and history/auto-history table rendering.

= 1.5.0 =
* Added: URL Fixer tab — scans and repairs broken image URLs in posts and pages.
* Added: Rogue File Scanner — detects unregistered files and double-extension backups.
* Added: base64-encoded path handling in rogue file deletion for correct UTF-8/latin1 filesystem encoding.
* Added: TIPO B2 URL fix — detects thumbnails missing because of dimension suffix renaming.
* Fixed: Auto-optimizer now uses a transient-based mechanism to prevent re-optimization on internal regenerations.
* Fixed: PDF compression now updates `_wp_attachment_metadata[filesize]` for correct display in WP 6.0+.
* Fixed: Rogue scanner path normalization for cross-platform compatibility.
* Improved: All i18n strings moved from JS JSON.parse to PHP `wp_localize_script()`.
* Improved: Inline CSS now uses `wp_add_inline_style()` instead of `echo '<style>'`.
* Changed: Class prefix updated to `TSOIMMA_` to comply with WordPress plugin guidelines.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.5.9 =
Hardens URL Fixer and rogue file deletion (upload-only URLs, realpath checks) and aligns translation loading with Plugin Check guidance.

= 1.5.8 =
Fixes the plugin description on the Plugins screen (CA/ES) and expands URL Fixer scanning to all public content types, including portfolio-style CPTs and rendered block/shortcode content.

= 1.5.7 =
Minor readme update to align screenshot descriptions with the current admin interface.

= 1.5.6 =
Version bump release to 1.5.6.

= 1.5.5 =
This update adds input sanitization for manual rename in AJAX while preserving UTF-8 characters (including accents and ñ/ç).

= 1.5.4 =
This update fixes UTF-8 filename edge cases (ñ/accents) in searches and URL replacement, and improves mobile readability plus dynamic language refresh.

= 1.5.3 =
This update makes PDF compression much more robust: faster timeout handling, automatic GhostScript/Imagick fallback, and persistent "not compressible" marking.

= 1.5.2 =
This update adds selectable auto-conversion source formats and new support for static GIF, BMP and TIFF uploads, with safer GIF animation handling.

= 1.5.1 =
This update fixes language switching consistency (CA/ES/EN) and multiple mobile admin UI layout issues.

= 1.5.0 =
This version updates the class prefix to TSOIMMA_ and moves all i18n strings to PHP. If you are upgrading from a previous version, deactivate and reactivate the plugin to recreate the history table with the correct name.


= 1.5.8 =
* Fixed: plugin name and description on the WordPress Plugins screen now appear in Catalan and Spanish when the site language is set accordingly.
* Added: bundled `languages/*.mo` files and early textdomain loading for site-locale translations.

= 1.5.7 =
* Updated: screenshot descriptions in readme to match the current plugin UI.

= 1.5.6 =
* Updated: version bump to 1.5.6.

= 1.5.5 =
* Fixed: sanitize manual rename input in AJAX handler to satisfy PHPCS while preserving UTF-8 characters (e.g. ç, ñ).

= 1.5.4 =
* Fixed: strict UTF-8 search behavior in image and PDF finders (no false positives with characters like ñ).
* Fixed: manual image transform URL updates for encoded/non-encoded filenames with accents and special characters.
* Fixed: URL Inconsistencies scan/fix handling for UTF-8 paths and encoded URLs.
* Fixed: History and Rogue dynamic UI language refresh after changing plugin language.
* Improved: mobile readability in Rogue and History sections (better card/table layout on small screens).

= 1.5.3 =
* Fixed: PDF compression flow now avoids long indefinite waits with a strict timeout and faster polling.
* Added: automatic GhostScript-to-Imagick fallback in background PDF compression when no output is produced in time.
* Added: pre-checks for encrypted/protected PDFs and already-compressed PDFs to fail fast with clear feedback.
* Added: persistent "not compressible" PDF status with reason/timestamp, including UI badge and disabled re-try button.
* Improved: timeout and error handling now refreshes PDF list immediately to reflect status changes.

= 1.5.2 =
* Added: auto-conversion source format selector in Auto-Optimizer settings (JPG/JPEG, PNG, WEBP, GIF static-only, BMP, TIFF).
* Added: support for auto-optimizing static GIF, BMP and TIFF uploads when selected.
* Improved: robust GIF handling — animated GIFs are never auto-converted, with fail-safe behavior if frame detection cannot be verified.
* Improved: broader mime support for TIFF/TIF detection in auto-optimizer.
* Fixed: consistency of "original format" behavior for BMP/TIFF in auto mode (now safely skipped instead of unexpected fallback).

= 1.5.1 =
* Fixed: complete in-plugin language switching (CA/ES/EN) for dynamic AJAX messages and URL Fixer summaries/lists.
* Fixed: mixed-language residual strings after changing language from Catalan to Spanish/English.
* Fixed: mobile header overlap/cropping in WordPress admin top bar.
* Improved: responsive layout for mobile tabs, header, and history/auto-history table rendering.

= 1.5.0 =
* Added: URL Fixer tab — scans and repairs broken image URLs in posts and pages.
* Added: Rogue File Scanner — detects unregistered files and double-extension backups.
* Added: base64-encoded path handling in rogue file deletion for correct UTF-8/latin1 filesystem encoding.
* Added: TIPO B2 URL fix — detects thumbnails missing because of dimension suffix renaming.
* Fixed: Auto-optimizer now uses a transient-based mechanism to prevent re-optimization on internal regenerations.
* Fixed: PDF compression now updates `_wp_attachment_metadata[filesize]` for correct display in WP 6.0+.
* Fixed: Rogue scanner path normalization for cross-platform compatibility.
* Improved: All i18n strings moved from JS JSON.parse to PHP `wp_localize_script()`.
* Improved: Inline CSS now uses `wp_add_inline_style()` instead of `echo '<style>'`.
* Changed: Class prefix updated to `TSOIMMA_` to comply with WordPress plugin guidelines.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.5.8 =
Fixes the plugin description on the Plugins screen so it displays in Catalan or Spanish when your WordPress site uses those languages.

= 1.5.7 =
Minor readme update to align screenshot descriptions with the current admin interface.

= 1.5.6 =
Version bump release to 1.5.6.

= 1.5.5 =
This update adds input sanitization for manual rename in AJAX while preserving UTF-8 characters (including accents and ñ/ç).

= 1.5.4 =
This update fixes UTF-8 filename edge cases (ñ/accents) in searches and URL replacement, and improves mobile readability plus dynamic language refresh.

= 1.5.3 =
This update makes PDF compression much more robust: faster timeout handling, automatic GhostScript/Imagick fallback, and persistent "not compressible" marking.

= 1.5.2 =
This update adds selectable auto-conversion source formats and new support for static GIF, BMP and TIFF uploads, with safer GIF animation handling.

= 1.5.1 =
This update fixes language switching consistency (CA/ES/EN) and multiple mobile admin UI layout issues.

= 1.5.0 =
This version updates the class prefix to TSOIMMA_ and moves all i18n strings to PHP. If you are upgrading from a previous version, deactivate and reactivate the plugin to recreate the history table with the correct name.
