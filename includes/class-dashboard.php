<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health overview and SEO audit helpers for the admin dashboard.
 */
class TSOIMMA_Dashboard {

	/**
	 * Per-request cache for alt audit helpers (avoids recomputing suggestions thousands of times).
	 *
	 * @var array<int, array<string, string>>
	 */
	private static $alt_audit_cache = array();

	/**
	 * In-request cache for fillable ID lists.
	 *
	 * @var array<string, array<int>>
	 */
	private static $fillable_ids_request = array();

	/**
	 * Clear cached fillable-alt lists (after alt writes).
	 *
	 * @return void
	 */
	public static function flush_fillable_alt_cache() {
		delete_transient( 'tsoimma_fillable_alt_all' );
		delete_transient( 'tsoimma_fillable_alt_used' );
		self::$fillable_ids_request = array();
		self::$alt_audit_cache     = array();
	}

	/**
	 * Clear cached backup storage stats (after backup purge/delete).
	 *
	 * @return void
	 */
	public static function flush_backup_stats_cache() {
		delete_transient( 'tsoimma_backup_storage_stats' );
	}

	/**
	 * @param bool $used_only Used-only filter flag.
	 * @return string
	 */
	private static function fillable_cache_key( $used_only ) {
		return $used_only ? 'tsoimma_fillable_alt_used' : 'tsoimma_fillable_alt_all';
	}

	/**
	 * Cached filename/title/suggestion context for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{base: string, title: string, humanized: string, suggested: string}
	 */
	private static function get_alt_audit_context( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return array(
				'base'       => '',
				'title'      => '',
				'humanized'  => '',
				'suggested'  => '',
			);
		}

		if ( ! isset( self::$alt_audit_cache[ $attachment_id ] ) ) {
			$file_path = get_attached_file( $attachment_id );
			$base      = '';
			if ( $file_path ) {
				$base = (string) pathinfo( basename( $file_path ), PATHINFO_FILENAME );
			}

			self::$alt_audit_cache[ $attachment_id ] = array(
				'base'      => $base,
				'title'     => trim( (string) get_the_title( $attachment_id ) ),
				'humanized' => trim( TSOIMMA_Image_Manager::suggest_alt_from_filename( $attachment_id ) ),
				'suggested' => '',
			);
		}

		if ( '' === self::$alt_audit_cache[ $attachment_id ]['suggested'] ) {
			self::$alt_audit_cache[ $attachment_id ]['suggested'] = trim(
				TSOIMMA_Image_Manager::suggest_alt_text( $attachment_id )
			);
		}

		return self::$alt_audit_cache[ $attachment_id ];
	}

	/**
	 * Return dashboard overview metrics (fast queries only).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_overview() {
		$history = TSOIMMA_History::get_stats();
		$auto    = TSOIMMA_Auto_Optimizer::get_settings();
		$backup  = self::get_backup_storage_stats();

		$cached_fillable = get_transient( self::fillable_cache_key( false ) );

		return array(
			'total_images'          => self::count_total_images(),
			'missing_alt'           => is_array( $cached_fillable ) ? count( $cached_fillable ) : null,
			'missing_alt_pending'   => ! is_array( $cached_fillable ),
			'backup_count'          => (int) $backup['count'],
			'backup_bytes'       => (int) $backup['bytes'],
			'backup_bytes_h'     => (string) $backup['bytes_h'],
			'total_saved_bytes'  => (int) $history['total_saved_bytes'],
			'total_saved_h'      => (string) $history['total_saved_h'],
			'total_operations'   => (int) $history['total_operations'],
			'auto_enabled'       => ! empty( $auto['enabled'] ),
			'auto_format'        => isset( $auto['format'] ) ? (string) $auto['format'] : 'webp',
			'queue'              => TSOIMMA_Queue::get_status(),
			'engines'            => self::get_engine_status(),
		);
	}

	/**
	 * Paginated list of attachments with missing or weak alt text.
	 *
	 * @param int  $page     Page number.
	 * @param int  $per_page Items per page.
	 * @param bool $used_only Only images referenced in content/meta.
	 * @return array<string, mixed>
	 */
	public static function get_missing_alt_list( $page = 1, $per_page = 35, $used_only = false ) {
		$page     = max( 1, absint( $page ) );
		$per_page = min( 100, max( 1, absint( $per_page ) ) );

		$ids = self::query_fillable_missing_alt_ids( $used_only );

		$total       = count( $ids );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		$offset      = ( $page - 1 ) * $per_page;
		$page_ids    = array_slice( $ids, $offset, $per_page );

		$items = array();
		foreach ( $page_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$file_path     = get_attached_file( $attachment_id );
			$current_alt   = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			$suggested_alt = self::resolve_dashboard_suggested_alt( $attachment_id );

			$is_used = TSOIMMA_Image_Manager::is_attachment_referenced_cached( $attachment_id );

			$items[] = array(
				'id'             => $attachment_id,
				'title'          => get_the_title( $attachment_id ),
				'alt'            => $current_alt,
				'suggested_alt'  => $suggested_alt,
				'needs_manual_alt' => '' === trim( $suggested_alt ),
				'filename'       => $file_path ? basename( $file_path ) : '',
				'thumb'          => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
				'url'            => wp_get_attachment_image_url( $attachment_id, 'full' ) ?: wp_get_attachment_url( $attachment_id ),
				'is_used'        => $is_used,
				'used_in_count'  => $is_used ? 1 : 0,
			);
		}

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
		);
	}

	/**
	 * Bulk-fill alt text for selected attachments.
	 *
	 * @param int[]  $attachment_ids Attachment IDs.
	 * @param string $source         filename|title|suggested.
	 * @param array  $custom_alts    Optional map attachment ID => alt text from the dashboard editor.
	 * @param bool   $recount        Whether to recompute missing-alt count (expensive on large libraries).
	 * @return array<string, mixed>
	 */
	public static function bulk_fill_alt( $attachment_ids, $source = 'suggested', $custom_alts = array(), $recount = false ) {
		$source      = sanitize_key( $source );
		$custom_alts = self::sanitize_custom_alt_map( $custom_alts );
		if ( ! in_array( $source, array( 'filename', 'title', 'suggested' ), true ) ) {
			$source = 'suggested';
		}

		$updated = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( array_map( 'absint', (array) $attachment_ids ) as $attachment_id ) {
			if ( $attachment_id <= 0 ) {
				continue;
			}

			$using_custom = array_key_exists( $attachment_id, $custom_alts );
			$current_alt  = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
			if ( ! $using_custom && '' !== $current_alt && ! self::is_weak_alt( $current_alt, $attachment_id ) ) {
				++$skipped;
				continue;
			}

			if ( $using_custom ) {
				if ( '' !== $current_alt && ! self::is_weak_alt( $current_alt, $attachment_id ) ) {
					++$skipped;
					continue;
				}
				$new_alt = $custom_alts[ $attachment_id ];
			} else {
				$new_alt = self::pick_alt_fill_value( $attachment_id, $source );
				if ( '' === $new_alt && 'suggested' === $source ) {
					$new_alt = self::resolve_dashboard_suggested_alt( $attachment_id );
				}
			}

			if ( '' === $new_alt ) {
				$errors[] = sprintf(
					/* translators: %d: attachment ID */
					__( 'Attachment %d: could not build alt text.', 'tso-image-master' ),
					$attachment_id
				);
				continue;
			}

			$result = TSOIMMA_Image_Manager::update_seo_fields( $attachment_id, null, $new_alt, null, null );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			TSOIMMA_History::log(
				$attachment_id,
				'seo_update',
				array(
					'seo_alt' => $new_alt,
					'source'  => 'dashboard_bulk_alt',
				)
			);
			++$updated;
		}

		if ( $updated > 0 ) {
			self::flush_fillable_alt_cache();
		}

		return array(
			'updated'     => $updated,
			'skipped'     => $skipped,
			'errors'      => $errors,
			'missing_alt' => $recount ? self::count_missing_alt() : null,
		);
	}

	/**
	 * Pick the first alt candidate that is non-empty and not weak/generic.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source        filename|title|suggested.
	 * @return string
	 */
	public static function pick_alt_fill_value( $attachment_id, $source = 'suggested' ) {
		$options = array();

		if ( 'title' === $source ) {
			$options[] = sanitize_text_field( get_the_title( $attachment_id ) );
		} elseif ( 'filename' === $source ) {
			$options[] = sanitize_text_field( TSOIMMA_Image_Manager::suggest_alt_from_filename( $attachment_id ) );
		} else {
			$resolved = sanitize_text_field( self::resolve_dashboard_suggested_alt( $attachment_id ) );
			if ( '' !== $resolved && ! self::is_weak_alt( $resolved, $attachment_id ) ) {
				return $resolved;
			}
			$options[] = sanitize_text_field( TSOIMMA_Image_Manager::suggest_alt_from_filename( $attachment_id ) );
			$options[] = sanitize_text_field( TSOIMMA_Image_Manager::suggest_alt_text( $attachment_id ) );
		}

		foreach ( $options as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( ! self::is_weak_alt( $candidate, $attachment_id ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Sanitize attachment ID => alt map from AJAX/dashboard input.
	 *
	 * @param mixed $raw Raw map.
	 * @return array<int, string>
	 */
	public static function sanitize_custom_alt_map( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $attachment_id => $alt ) {
			$attachment_id = absint( $attachment_id );
			$alt           = sanitize_text_field( wp_unslash( (string) $alt ) );
			if ( $attachment_id <= 0 || '' === $alt ) {
				continue;
			}
			$clean[ $attachment_id ] = $alt;
		}

		return $clean;
	}

	/**
	 * @return int
	 */
	public static function count_total_images() {
		return (int) TSOIMMA_Orphan_Finder::count_total();
	}

	/**
	 * @return int
	 */
	public static function count_missing_alt() {
		return count( self::query_fillable_missing_alt_ids( false ) );
	}

	/**
	 * Attachment IDs with missing/weak alt (dashboard list; includes numeric/camera names for manual edit).
	 *
	 * @param bool $used_only Only images referenced in content/meta.
	 * @return array<int>
	 */
	private static function query_fillable_missing_alt_ids( $used_only = false ) {
		$req_key = $used_only ? 'used' : 'all';
		if ( isset( self::$fillable_ids_request[ $req_key ] ) ) {
			return self::$fillable_ids_request[ $req_key ];
		}

		$transient_key = self::fillable_cache_key( $used_only );
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			self::$fillable_ids_request[ $req_key ] = $cached;
			return $cached;
		}

		$ids = self::query_missing_alt_ids();
		if ( $used_only ) {
			$ids = array_values(
				array_filter(
					$ids,
					array( 'TSOIMMA_Image_Manager', 'is_attachment_referenced' )
				)
			);
		}

		$fillable = array();
		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id <= 0 ) {
				continue;
			}
			$fillable[] = $attachment_id;
		}

		set_transient( $transient_key, $fillable, 2 * MINUTE_IN_SECONDS );
		self::$fillable_ids_request[ $req_key ] = $fillable;

		return $fillable;
	}

	/**
	 * @return array<int>
	 */
	private static function query_missing_alt_ids() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment'
			   AND p.post_status = 'inherit'
			   AND p.post_mime_type LIKE 'image/%'
			   AND ( pm.meta_value IS NULL OR pm.meta_value = '' )"
		);
		// phpcs:enable

		$weak = array();
		$rows = array_map( 'absint', (array) $rows );

		// Also include weak/generic alt values (DSC_, IMG_, filename-only).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value AS alt_text
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment'
			   AND p.post_status = 'inherit'
			   AND p.post_mime_type LIKE 'image/%'
			   AND pm.meta_value != ''"
		);
		// phpcs:enable

		foreach ( (array) $candidates as $row ) {
			$attachment_id = absint( $row->ID );
			$alt_text      = (string) $row->alt_text;
			if ( self::is_weak_alt( $alt_text, $attachment_id ) ) {
				$weak[] = $attachment_id;
			}
		}

		return array_values( array_unique( array_merge( $rows, $weak ) ) );
	}

	/**
	 * @param string $alt            Current alt text.
	 * @param int    $attachment_id  Attachment ID.
	 * @return bool
	 */
	public static function is_weak_alt( $alt, $attachment_id ) {
		$alt = trim( (string) $alt );
		if ( '' === $alt ) {
			return true;
		}

		if ( preg_match( '/^(IMG[-_]?|DSC[-_]?|DSCN|PICT|PHOTO)[0-9]+$/i', $alt ) ) {
			return true;
		}

		if ( preg_match( '/^\d+$/', $alt ) ) {
			return true;
		}

		// Multi-word alts are almost always intentional (skip expensive filename checks).
		if ( preg_match( '/\s/u', $alt ) ) {
			return false;
		}

		$context = self::get_alt_audit_context( $attachment_id );
		$base    = $context['base'];
		$suggested = $context['suggested'];

		if ( '' !== $suggested && strcasecmp( $alt, $suggested ) === 0 ) {
			return false;
		}

		if ( self::alt_matches_resolved_suggestion( $alt, $attachment_id, $context ) ) {
			return false;
		}

		if ( '' !== $base || '' !== $context['humanized'] ) {
			$humanized = $context['humanized'];

			if ( '' !== $humanized && strcasecmp( $alt, $humanized ) === 0 ) {
				return false;
			}

			if ( self::is_raw_filename_stem_alt( $alt, $base ) ) {
				return true;
			}

			$title = $context['title'];
			if ( '' !== $title && strcasecmp( $alt, $title ) === 0
				&& TSOIMMA_Image_Manager::is_title_just_filename_stem( $title, $base )
				&& self::is_raw_filename_stem_alt( $alt, $base ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Best alt suggestion for the dashboard (prefer humanized filename over raw stem).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function resolve_dashboard_suggested_alt( $attachment_id ) {
		$context   = self::get_alt_audit_context( $attachment_id );
		$humanized = $context['humanized'];
		$from_full = $context['suggested'];

		if ( '' !== $humanized && ! self::is_raw_filename_stem_alt( $humanized, $context['base'] ) ) {
			return $humanized;
		}
		if ( '' !== $from_full && ! self::is_raw_filename_stem_alt( $from_full, $context['base'] ) ) {
			return $from_full;
		}
		if ( '' !== $humanized ) {
			return $humanized;
		}

		return $from_full;
	}

	/**
	 * Whether alt text is just the raw upload filename stem (not an accepted suggestion).
	 *
	 * @param string $alt  Alt text.
	 * @param string $base Filename stem.
	 * @return bool
	 */
	private static function is_raw_filename_stem_alt( $alt, $base ) {
		$alt  = trim( (string) $alt );
		$base = trim( (string) $base );
		if ( '' === $alt || '' === $base ) {
			return false;
		}
		if ( 0 !== strcasecmp( $alt, $base ) ) {
			return false;
		}

		return ( $alt === $base || $alt === strtolower( $base ) );
	}

	/**
	 * Compare alt keys for suggestion matching (ignore case, spaces, punctuation).
	 *
	 * @param string $text Alt or suggestion text.
	 * @return string
	 */
	private static function alt_compare_key( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}
		$text = strtolower( $text );
		$text = preg_replace( '/[^a-z0-9]+/', '', $text );

		return (string) $text;
	}

	/**
	 * Whether saved alt matches the dashboard suggestion the user likely accepted.
	 *
	 * @param string               $alt            Alt text.
	 * @param int                  $attachment_id  Attachment ID.
	 * @param array<string,string> $context        Optional audit context.
	 * @return bool
	 */
	private static function alt_matches_resolved_suggestion( $alt, $attachment_id, $context = null ) {
		if ( null === $context ) {
			$context = self::get_alt_audit_context( $attachment_id );
		}
		if ( self::is_raw_filename_stem_alt( $alt, $context['base'] ) ) {
			return false;
		}

		$resolved = self::resolve_dashboard_suggested_alt( $attachment_id );
		if ( '' === $resolved ) {
			return false;
		}
		if ( 0 === strcasecmp( $alt, $resolved ) ) {
			return true;
		}

		return self::alt_compare_key( $alt ) === self::alt_compare_key( $resolved );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_backup_storage_stats() {
		$cached = get_transient( 'tsoimma_backup_storage_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master';

		$stats = array(
			'count'   => 0,
			'bytes'   => 0,
			'bytes_h' => '0 B',
		);

		if ( ! is_dir( $base_dir ) ) {
			set_transient( 'tsoimma_backup_storage_stats', $stats, 5 * MINUTE_IN_SECONDS );
			return $stats;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( UnexpectedValueException $e ) {
			return $stats;
		}

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}
			if ( ! preg_match( '/_tso_im_backup\.[a-z0-9]+$/i', $file_info->getFilename() ) ) {
				continue;
			}
			++$stats['count'];
			$stats['bytes'] += (int) $file_info->getSize();
		}

		$stats['bytes_h'] = size_format( $stats['bytes'] );
		set_transient( 'tsoimma_backup_storage_stats', $stats, 5 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_engine_status() {
		$gd_webp_ok  = TSOIMMA_Optimizer::webp_supported();
		$gd_avif_ok  = TSOIMMA_Optimizer::avif_supported();
		$gs_ok       = TSOIMMA_PDF_Compressor::ghostscript_available();
		$imagick_ok  = class_exists( 'Imagick' );

		return array(
			'gd_webp'     => array(
				'ok'     => $gd_webp_ok,
				'reason' => $gd_webp_ok ? '' : self::engine_reason_gd_webp(),
			),
			'gd_avif'     => array(
				'ok'     => $gd_avif_ok,
				'reason' => $gd_avif_ok ? '' : self::engine_reason_gd_avif(),
			),
			'ghostscript' => array(
				'ok'     => $gs_ok,
				'reason' => $gs_ok ? '' : __( 'GhostScript was not found in the server PATH. Install it to compress PDFs from the command line.', 'tso-image-master' ),
			),
			'imagick'     => array(
				'ok'     => $imagick_ok,
				'reason' => $imagick_ok ? '' : __( 'The PHP Imagick extension is not installed. Some PDF and image operations may be limited.', 'tso-image-master' ),
			),
		);
	}

	/**
	 * @return string
	 */
	private static function engine_reason_gd_webp() {
		if ( ! function_exists( 'imagewebp' ) && ! function_exists( 'imagecreatefromwebp' ) ) {
			return __( 'PHP GD cannot read or write WebP (imagewebp and imagecreatefromwebp are missing). Enable WebP in PHP GD.', 'tso-image-master' );
		}
		if ( ! function_exists( 'imagewebp' ) ) {
			return __( 'PHP GD cannot encode WebP (imagewebp is missing).', 'tso-image-master' );
		}
		return __( 'PHP GD cannot decode WebP (imagecreatefromwebp is missing).', 'tso-image-master' );
	}

	/**
	 * @return string
	 */
	private static function engine_reason_gd_avif() {
		if ( ! function_exists( 'imageavif' ) && ! function_exists( 'imagecreatefromavif' ) ) {
			return __( 'PHP GD was compiled without AVIF support (imageavif and imagecreatefromavif are missing). Recompile GD with libavif or use WebP instead.', 'tso-image-master' );
		}
		if ( ! function_exists( 'imageavif' ) ) {
			return __( 'PHP GD cannot encode AVIF (imageavif is missing).', 'tso-image-master' );
		}
		return __( 'PHP GD cannot decode AVIF (imagecreatefromavif is missing).', 'tso-image-master' );
	}
}
