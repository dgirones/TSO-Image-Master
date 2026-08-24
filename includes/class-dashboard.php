<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health overview and SEO audit helpers for the admin dashboard.
 */
class TSOIMMA_Dashboard {

	/**
	 * Return dashboard overview metrics (fast queries only).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_overview() {
		$history = TSOIMMA_History::get_stats();
		$auto    = TSOIMMA_Auto_Optimizer::get_settings();
		$backup  = self::get_backup_storage_stats();

		return array(
			'total_images'       => self::count_total_images(),
			'missing_alt'        => self::count_missing_alt(),
			'backup_count'       => (int) $backup['count'],
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

		$ids = self::query_missing_alt_ids();
		if ( $used_only ) {
			$ids = array_values(
				array_filter(
					$ids,
					array( 'TSOIMMA_Image_Manager', 'is_attachment_referenced' )
				)
			);
		}

		$total       = count( $ids );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		$offset      = ( $page - 1 ) * $per_page;
		$page_ids    = array_slice( $ids, $offset, $per_page );

		$items = array();
		foreach ( $page_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$file_path     = get_attached_file( $attachment_id );
			$current_alt   = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

			$items[] = array(
				'id'             => $attachment_id,
				'title'          => get_the_title( $attachment_id ),
				'alt'            => $current_alt,
				'suggested_alt'  => TSOIMMA_Image_Manager::suggest_alt_text( $attachment_id ),
				'filename'       => $file_path ? basename( $file_path ) : '',
				'thumb'          => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
				'used_in_count'  => count( TSOIMMA_Image_Manager::get_used_in_posts( $attachment_id ) ),
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
	 * @return array<string, mixed>
	 */
	public static function bulk_fill_alt( $attachment_ids, $source = 'suggested' ) {
		$source = sanitize_key( $source );
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

			$current_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
			if ( '' !== $current_alt && ! self::is_weak_alt( $current_alt, $attachment_id ) ) {
				++$skipped;
				continue;
			}

			$new_alt = '';
			if ( 'title' === $source ) {
				$new_alt = sanitize_text_field( get_the_title( $attachment_id ) );
			} elseif ( 'filename' === $source ) {
				$file_path = get_attached_file( $attachment_id );
				$base      = $file_path ? pathinfo( basename( $file_path ), PATHINFO_FILENAME ) : '';
				$new_alt   = sanitize_text_field( ucwords( str_replace( array( '-', '_' ), ' ', $base ) ) );
			} else {
				$new_alt = sanitize_text_field( TSOIMMA_Image_Manager::suggest_alt_text( $attachment_id ) );
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

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
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
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment'
			   AND p.post_status = 'inherit'
			   AND p.post_mime_type LIKE 'image/%'
			   AND ( pm.meta_value IS NULL OR pm.meta_value = '' )"
		);
		// phpcs:enable
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
			if ( self::is_weak_alt( (string) $row->alt_text, $attachment_id ) ) {
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

		$file_path = get_attached_file( $attachment_id );
		if ( $file_path ) {
			$base = pathinfo( basename( $file_path ), PATHINFO_FILENAME );
			// Raw basename only (e.g. "foto-vacances") — humanized fills must not stay "weak".
			if ( strcasecmp( $alt, $base ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_backup_storage_stats() {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master';

		$stats = array(
			'count'   => 0,
			'bytes'   => 0,
			'bytes_h' => '0 B',
		);

		if ( ! is_dir( $base_dir ) ) {
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
