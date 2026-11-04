=== TSO Image Master ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: image optimization, webp, avif, media library, pdf compression
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-converts images to WebP, AVIF, JPG or PNG, compresses PDFs, and repairs your media library: broken links, orphans, unused sizes, alt, SEO.

== Description ==

Most image plugins only compress. TSO Image Master also fixes what goes wrong. It automatically converts every upload to WebP, AVIF, JPG or PNG (with an undo backup) and compresses your PDFs. Then it helps you repair the rest of your media library: broken image URLs with one-click fix and undo, safe renaming that updates every link, orphan and still-referenced images, extra files, duplicates, unused image sizes that waste disk space, missing or hyphenated alt text, and oversized files.

It provides the following features from a single admin screen:

**Overview** — Site health metrics (image count, missing alt, TSO backups, space saved, server engines). Bulk-fill missing or weak alt text. Configure backup retention (backups of deleted images are never purged automatically) and watch the background optimize queue.

**Image Optimizer** — Convert images to WebP, AVIF (when GD supports it), JPG, or PNG. Set quality and dimensions, replace originals, and update content links (posts, custom fields, options, widgets and Elementor pages). Bulk optimize runs in the background via WP-Cron (5 images per batch). Choose how many images to show per page (21–105). Requires PHP GD (WebP strongly recommended).

**Orphan Images** — Three sub-tabs. *Orphans* detects Media Library images not used in posts, pages, widgets, meta, Customizer settings, FSE templates/parts/patterns, term or user meta, or menus; paginated scanning avoids timeouts on large sites. *Referenced* lists images without a parent post that are still in use and shows exactly where, so you can attach them to their post or delete them. *Extra files* scans uploads for files WordPress does not know about (double extensions like image.jpg.webp, plugin backups, temporary files) and shows which TSO backups can still revert an image. Every deletion saves a backup of the original first.

**Image Sizes** — Finds image sizes (thumbnails) left behind by old plugins or themes that WordPress no longer registers or uses, grouped by size. Delete a whole size in one click: every deletion saves a backup zip you can download, restore or delete, and files used by another size or by the main image are never removed. Sizes turned off in TSO Swiss Knife are marked, and thumbnail, medium and large can never be deleted.

**SEO & File Names** — Edit title, alt text, caption, and description for any image. Rename files using SEO-friendly slugs (lowercase, no accents, hyphens instead of spaces). All internal links are updated automatically.

**PDF Compressor** — Reduce PDF file size in the Media Library using GhostScript (recommended) or the Imagick PHP extension as a fallback. The original URL never changes.

**Auto-Optimizer** — Automatically optimize new uploads with your chosen format and quality. Optional: skip already-small WebP/AVIF files, and fill missing alt text on upload (even when auto-optimize is off). Each image is processed only once so regenerations do not re-trigger optimization.

**Media Library** — Row action and bulk action to queue images for background optimization, plus a short note on the attachment screen.

**Repairs** — Inconsistent URL detector, attachment repairs (broken paths, MIME types, ghost attachments, and image sizes listed in the metadata whose file is missing), duplicate images, and the backups of removals.

**History** — Audit log of optimizations, renames, SEO updates (with field details), PDF compressions, and related actions. Filter by action type, date range, and filename. Configurable automatic cleanup.

**URL Fixer** — Scans public content (posts, pages, and custom post types) for broken image URLs after format conversions (e.g. `.jpg` still referenced after conversion to `.webp`). Renders blocks and shortcodes when needed so embedded images are detected. Finds replacements and updates the database in one click; can also remove broken references when no fix exists.

**Cache Compatibility** — After operations that change file URLs or content, the plugin purges LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Fastest Cache, Breeze, SiteGround Optimizer, and Autoptimize when they are active.

= Requirements =

* PHP 7.4 or higher (tested up to 8.3)
* WordPress 6.2 or higher (tested up to 7.1)
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

If you would like to contribute a translation into another language, you can do so at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/tso-image-master/).

== Installation ==

1. Upload the `tso-image-master` folder to the `/wp-content/plugins/` directory, or install it directly from the WordPress plugin dashboard.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Image Master** in the admin sidebar to start using the plugin.
4. (Optional) To enable PDF compression, install GhostScript on your server. Ask your hosting provider for assistance if needed.
5. WebP output requires PHP GD. Ask your hosting provider for assistance if needed.

== Frequently Asked Questions ==

= Does the plugin modify original image files? =

Only if you check the "Replace original" option. When replacing, the plugin saves a backup copy under `wp-content/uploads/tso-image-master/` (mirroring your upload year/month folders when applicable). Backup files use a `_tso_im_backup` name pattern. You can revert from the editor modal or delete backups to free disk space. Empty backup folders are removed automatically after deletion. Backups are never stored inside the plugin folder.

= Can backups be deleted automatically? =

Yes. On the Overview tab you can set backup retention by maximum age (days) and/or maximum total size (MB). A daily cron purges old or oversized TSO backups (the backup zips made by the Image Sizes tab are never purged automatically: delete them manually from that tab). Set both to `0` to disable automatic purge. You can also run **Purge now** from Overview.

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

On Repairs > Duplicate images, **Scan duplicates** groups Media Library files with the same MD5 hash so you can spot wasted space. It does not delete files automatically; review groups before removing anything.

== Screenshots ==

1. Image Optimizer tab with search, bulk actions, and format/quality controls.

2. Auto-Optimizer tab with upload automation settings and maintenance tools.

3. Per-image Optimize modal with output format, resize, and replace-original options.

4. Overview dashboard — site health cards, server engines, and missing-alt editor with bulk fill.

5. PDF compressor — DPI quality, replace-original option, GhostScript status, and PDF search/select toolbar.

== Changelog ==

For releases before 2.0.0, see CHANGELOG.txt in the plugin folder.

= 2.1.2 =
* Fixed: A number in a WordPress option is no longer taken as an attachment ID unless the option is about media: images whose ID matched a core setting (posts_per_rss = 10, thumbnail_size_w = 150...) looked "used by a theme option" and were wrongly protected or recommended to keep; the SEO tab no longer shows an empty counter pill.
* New: Repairs has a "Missing sizes" tool: it finds images whose metadata lists a size (for example medium) whose file is not on disk (the cause of 404s on thumbnails) and repairs them without touching the original: it relinks the size if it exists with another extension, regenerates it if it is still registered, or removes the stale entry. It is the opposite case of the Image Sizes tab, which only counts existing files. Repairs are logged in History.
* Fixed: Regenerating thumbnails (queue and manual optimization) no longer deletes the old thumbnails first: they are moved aside and put back if the regeneration fails, times out or is cancelled, so the metadata never points to files that are gone (404s on the site and in the admin); converting thumbnails saves the metadata after each size.
* Fixed: Admin lists no longer request thumbnails that do not exist on disk (a size listed in the metadata whose file is gone, for example after a size cleanup or while thumbnails are regenerated): they fall back to a smaller size or the original, so 404 monitors stop logging them.
* Fixed: Referenced images: images used by ID inside a post or page (for example a [gallery ids="..."]) are now found and can be attached to it; images used as the featured image of a type that is not public but editable (such as a slider's slides) can be attached too; up to ten candidate posts are checked per image; the "where it is used" text names the real post and its type; results and errors show inline instead of in alerts; the list becomes cards on mobile and the scan button is locked while scanning. The same ID check now also protects those images from being treated as orphans.
* Fixed: Duplicate images review: the scan keeps only IDs in its temporary data and details only the 20 groups with most wasted space (with a note when there are more), a scan whose data expired now asks to start again instead of returning partial results, the merge summary is no longer erased by the rescan, the progress bar no longer resets each batch, the merge no longer rewrites file names that only contain the retired one, where-used texts are translated to CA/ES/EN (also in the History tab for new merges), error messages are styled, and the layout is fixed on mobile.
* Improved: Duplicate images moved from Overview to a new sub-tab in Repairs, and the "Largest images" shortcut was removed from Overview.
* Improved: SEO & Names now has one criterion for badges and order, weighted by how much Google and Bing rely on each element: missing alt, generic alt, alt written like a file name, generic or non-slug file name, and missing title; each card shows a colour-coded badge per gap, and "Needs SEO first" sorts by that score.

= 2.1.1 =
* Improved: The "URLs" tab is now called "Repairs" and has three sub-tabs: the inconsistent URL detector and the attachment repairs (broken paths, mime types, ghost attachments), moved from Auto-optimization, and a third one with the removal backups; the tab bar stays on one line.
* Fixed: Repairs tab: texts of the repair tools and their messages are translated, MIME and ghost repairs report errors, the summary updates after fixing URLs, and restored backups can no longer be undone twice.
* Fixed: History now shows file name and icon for URL repairs, and logs path repairs, MIME repairs, ghost attachment deletions and removal backup deletions; it can be searched by the file names involved.

= 2.1.0 =
* New: Orphan Images now has three sub-tabs (Orphans, Referenced, Extra files). Referenced lists images without a parent post that are still in use and shows where; they can be attached to their post or deleted.
* Improved: Every deletion from Orphans and Referenced saves a backup of the original first; Orphans re-checks each image on the server, deletes in batches of 100, and also recognises images used as category/term or user images.
* Improved: Extra files no longer flags image-editor originals or WebP/AVIF files of existing images, tells apart backups that can revert an image from backups of deleted images, and the automatic retention never removes the latter.
* New: Image Sizes marks the sizes turned off in TSO Swiss Knife and explains the difference between its quarantine and this plugin's backup zip.
* Fixed: Image Sizes works with capital-letter size names, the backups list shows every size zip, the Space column matches what is really freed, and zips no longer fail when two images share a size file.
* Improved: Image Sizes scans and deletes faster on big libraries (batched scan with progress, cache purged once); thumbnail, medium and large are never deletable.
* Fixed: Your choices (quality, format, replace original, PDF quality, batch size, SEO filters) are remembered per user, the 24-hour "Extra files" delete list works with Redis/Memcached, and buttons and sub-tab headers line up.
