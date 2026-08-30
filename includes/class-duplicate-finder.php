<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find duplicate image attachments by file hash.
 */
class TSOIMMA_Duplicate_Finder {

	const USED_IN_PREVIEW_LIMIT = 8;
	const SCAN_BATCH_SIZE       = 100;
	const TRANSIENT_PREFIX      = 'tsoimma_dup_scan_';

	/**
	 * Scan one batch of attachments (state kept in a user transient).
	 *
	 * @param int  $after_id Attachment ID cursor (0 on first batch after reset).
	 * @param int  $batch    Items per batch.
	 * @param bool $reset    Start a fresh scan.
	 * @return array<string, mixed>
	 */
	public static function scan_batch( $after_id = 0, $batch = self::SCAN_BATCH_SIZE, $reset = false ) {
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		@set_time_limit( 120 );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array(
				'done'    => true,
				'error'   => 'not_logged_in',
				'groups'  => array(),
				'scanned' => 0,
				'total'   => 0,
			);
		}

		$key   = self::TRANSIENT_PREFIX . $user_id;
		$batch = max( 20, min( 200, absint( $batch ) ) );

		if ( $reset ) {
			delete_transient( $key );
		}

		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = array(
				'after_id' => 0,
				'total'    => self::count_image_attachments(),
				'scanned'  => 0,
				'groups'   => array(),
			);
		}

		if ( $reset ) {
			$state['after_id'] = 0;
			$state['scanned']  = 0;
			$state['groups']   = array();
			$state['total']    = self::count_image_attachments();
		}

		$after_id  = $reset ? 0 : max( (int) $state['after_id'], absint( $after_id ) );
		$upload_dir = wp_upload_dir();
		$basedir    = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
		$ids        = self::get_image_attachment_ids_batch( $after_id, $batch );

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$state['after_id'] = $attachment_id;
			++$state['scanned'];

			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
				continue;
			}

			$hash = md5_file( $file_path );
			if ( ! $hash ) {
				continue;
			}

			if ( ! isset( $state['groups'][ $hash ] ) ) {
				$state['groups'][ $hash ] = array(
					'hash'  => $hash,
					'size'  => filesize( $file_path ),
					'items' => array(),
				);
			}

			$state['groups'][ $hash ]['items'][] = self::build_item_stub( $attachment_id, $file_path, $basedir );
		}

		$done = empty( $ids ) || count( $ids ) < $batch;
		if ( ! $done ) {
			set_transient( $key, $state, 15 * MINUTE_IN_SECONDS );
			return array(
				'done'     => false,
				'scanned'  => (int) $state['scanned'],
				'total'    => (int) $state['total'],
				'after_id' => (int) $state['after_id'],
			);
		}

		delete_transient( $key );
		return self::finalize_scan_state( $state );
	}

	/**
	 * Legacy single-request scan (avoid on large libraries).
	 *
	 * @param int $limit Max attachments to scan (0 = all).
	 * @return array<string, mixed>
	 */
	public static function scan( $limit = 0 ) {
		$limit = absint( $limit );
		if ( 0 === $limit ) {
			return self::scan_batch( 0, self::SCAN_BATCH_SIZE, true );
		}

		// Small capped scan for backwards compatibility.
		$state = array(
			'after_id' => 0,
			'total'    => $limit,
			'scanned'  => 0,
			'groups'   => array(),
		);

		$upload_dir = wp_upload_dir();
		$basedir    = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
		$ids        = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$file_path     = get_attached_file( $attachment_id );
			if ( ! $file_path || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
				continue;
			}

			$hash = md5_file( $file_path );
			if ( ! $hash ) {
				continue;
			}

			if ( ! isset( $state['groups'][ $hash ] ) ) {
				$state['groups'][ $hash ] = array(
					'hash'  => $hash,
					'size'  => filesize( $file_path ),
					'items' => array(),
				);
			}

			$state['groups'][ $hash ]['items'][] = self::build_item_stub( $attachment_id, $file_path, $basedir );
			++$state['scanned'];
		}

		$result               = self::finalize_scan_state( $state );
		$result['done']       = true;
		$result['after_id']   = 0;
		return $result;
	}

	/**
	 * @return int
	 */
	public static function count_image_attachments() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND post_status = 'inherit'
                 AND post_mime_type LIKE %s",
				$wpdb->esc_like( 'image/' ) . '%'
			)
		);
	}

	/**
	 * @param int $after_id Last processed attachment ID.
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	private static function get_image_attachment_ids_batch( $after_id, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND post_status = 'inherit'
                 AND post_mime_type LIKE %s
                 AND ID > %d
                 ORDER BY ID ASC
                 LIMIT %d",
				$wpdb->esc_like( 'image/' ) . '%',
				absint( $after_id ),
				absint( $limit )
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Build duplicate groups response and enrich usage only for duplicate items.
	 *
	 * @param array<string, mixed> $state Scan state with groups hash map.
	 * @return array<string, mixed>
	 */
	private static function finalize_scan_state( $state ) {
		$groups = isset( $state['groups'] ) && is_array( $state['groups'] ) ? $state['groups'] : array();

		$duplicate_groups = array_values(
			array_filter(
				$groups,
				function ( $group ) {
					return isset( $group['items'] ) && count( $group['items'] ) > 1;
				}
			)
		);

		foreach ( $duplicate_groups as $group_index => $group ) {
			$items       = array();
			$sibling_ids = array();
			foreach ( $group['items'] as $stub ) {
				if ( ! empty( $stub['id'] ) ) {
					$sibling_ids[] = absint( $stub['id'] );
				}
			}

			foreach ( $group['items'] as $item ) {
				$items[] = self::enrich_item_usage( $item, $sibling_ids );
			}

			$item_count = count( $items );
			$group_size = isset( $group['size'] ) ? (int) $group['size'] : 0;
			$wasted     = $item_count > 1 ? $group_size * ( $item_count - 1 ) : 0;

			$duplicate_groups[ $group_index ]['items']          = $items;
			$duplicate_groups[ $group_index ]['size_h']         = size_format( $group_size );
			$duplicate_groups[ $group_index ]['wasted_bytes']   = $wasted;
			$duplicate_groups[ $group_index ]['wasted_h']       = size_format( $wasted );
			$duplicate_groups[ $group_index ]['same_filename']  = self::group_has_same_filename( $items );
		}

		usort(
			$duplicate_groups,
			function ( $a, $b ) {
				$waste_diff = (int) ( $b['wasted_bytes'] ?? 0 ) <=> (int) ( $a['wasted_bytes'] ?? 0 );
				if ( 0 !== $waste_diff ) {
					return $waste_diff;
				}
				return count( $b['items'] ) <=> count( $a['items'] );
			}
		);

		$wasted = 0;
		foreach ( $duplicate_groups as $group ) {
			$wasted += (int) ( $group['wasted_bytes'] ?? 0 );
		}

		$scanned = isset( $state['scanned'] ) ? (int) $state['scanned'] : 0;
		$total   = isset( $state['total'] ) ? (int) $state['total'] : $scanned;

		return array(
			'done'            => true,
			'groups'          => $duplicate_groups,
			'group_count'     => count( $duplicate_groups ),
			'duplicate_files' => array_sum(
				array_map(
					function ( $group ) {
						return isset( $group['items'] ) ? count( $group['items'] ) : 0;
					},
					$duplicate_groups
				)
			),
			'wasted_bytes'    => $wasted,
			'wasted_h'        => size_format( $wasted ),
			'scanned'         => $scanned,
			'total'           => $total,
			'after_id'        => isset( $state['after_id'] ) ? (int) $state['after_id'] : 0,
		);
	}

	/**
	 * Fast stub during hash scan (no reference DB lookups).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file_path     Absolute file path.
	 * @param string $basedir       Normalized uploads basedir with trailing slash.
	 * @return array<string, mixed>
	 */
	private static function build_item_stub( $attachment_id, $file_path, $basedir ) {
		$attachment_id = absint( $attachment_id );
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$post          = get_post( $attachment_id );
		$rel_path      = ltrim( str_replace( $basedir, '', wp_normalize_path( $file_path ) ), '/' );
		$filesize      = filesize( $file_path );

		return array(
			'id'         => $attachment_id,
			'title'      => get_the_title( $attachment_id ),
			'filename'   => basename( $file_path ),
			'rel_path'   => $rel_path,
			'url'        => wp_get_attachment_url( $attachment_id ),
			'thumb'      => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'filesize'   => (int) $filesize,
			'filesize_h' => size_format( $filesize ),
			'width'      => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
			'height'     => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
			'uploaded'   => $post ? (string) $post->post_date : '',
			'uploaded_h' => $post ? mysql2date( get_option( 'date_format' ), $post->post_date ) : '',
			'edit_url'   => admin_url( 'post.php?post=' . $attachment_id . '&action=edit' ),
		);
	}

	/**
	 * Add used-in references for one duplicate item.
	 *
	 * @param array<string, mixed> $item        Item stub.
	 * @param int[]                $sibling_ids Other attachment IDs in the same hash group.
	 * @return array<string, mixed>
	 */
	private static function enrich_item_usage( $item, $sibling_ids = array() ) {
		$attachment_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		if ( $attachment_id <= 0 ) {
			return $item;
		}

		$report = TSOIMMA_Image_Manager::get_attachment_reference_report( $attachment_id );
		$direct = isset( $report['direct'] ) && is_array( $report['direct'] ) ? $report['direct'] : array();
		$indirect = isset( $report['indirect'] ) && is_array( $report['indirect'] ) ? $report['indirect'] : array();

		foreach ( $indirect as $index => $ref ) {
			$post_id = isset( $ref['id'] ) ? absint( $ref['id'] ) : 0;
			if ( $post_id <= 0 ) {
				continue;
			}
			foreach ( (array) $sibling_ids as $sibling_id ) {
				$sibling_id = absint( $sibling_id );
				if ( $sibling_id <= 0 || $sibling_id === $attachment_id ) {
					continue;
				}
				if ( TSOIMMA_Image_Manager::post_content_contains_attachment_id( $post_id, $sibling_id ) ) {
					$indirect[ $index ]['active_attachment_id'] = $sibling_id;
					/* translators: 1: attachment ID used in content, 2: duplicate attachment ID */
					$indirect[ $index ]['detail'] = sprintf(
						'El post usa #%1$d; #%2$d no hi és al codi',
						$sibling_id,
						$attachment_id
					);
					break;
				}
			}
		}

		$item['usage_status']     = isset( $report['status'] ) ? (string) $report['status'] : 'none';
		$item['used_in_count']    = isset( $report['used_in_count'] ) ? (int) $report['used_in_count'] : 0;
		$item['keep_recommended'] = ! empty( $direct );
		$item['safe_to_delete']   = ( 'none' === $item['usage_status'] ) || ( 'filename_only' === $item['usage_status'] && empty( $direct ) );

		foreach ( $direct as $index => $ref ) {
			$post_id = isset( $ref['id'] ) ? absint( $ref['id'] ) : 0;
			if ( $post_id <= 0 ) {
				continue;
			}
			$direct[ $index ]['edit_url']     = TSOIMMA_Post_Editor_Highlight::get_post_edit_highlight_url( $post_id, $attachment_id );
			$direct[ $index ]['highlight_id'] = $attachment_id;
		}

		foreach ( $indirect as $index => $ref ) {
			$post_id = isset( $ref['id'] ) ? absint( $ref['id'] ) : 0;
			if ( $post_id <= 0 ) {
				continue;
			}
			$highlight_id = ! empty( $ref['active_attachment_id'] ) ? absint( $ref['active_attachment_id'] ) : $attachment_id;
			$indirect[ $index ]['edit_url']     = TSOIMMA_Post_Editor_Highlight::get_post_edit_highlight_url( $post_id, $highlight_id );
			$indirect[ $index ]['highlight_id'] = $highlight_id;
		}

		$item['used_in_direct']   = array_slice( $direct, 0, self::USED_IN_PREVIEW_LIMIT );
		$item['used_in_indirect'] = array_slice( $indirect, 0, self::USED_IN_PREVIEW_LIMIT );
		$item['used_in']          = array_merge( $item['used_in_direct'], $item['used_in_indirect'] );
		$item['used_in_more']     = max( 0, $item['used_in_count'] - count( $item['used_in'] ) );

		return $item;
	}

	/**
	 * Whether every item in a duplicate group shares the same basename.
	 *
	 * @param array<int, array<string, mixed>> $items Group items.
	 * @return bool
	 */
	private static function group_has_same_filename( $items ) {
		$names = array();
		foreach ( (array) $items as $item ) {
			if ( empty( $item['filename'] ) ) {
				continue;
			}
			$names[ (string) $item['filename'] ] = true;
		}
		return count( $names ) <= 1;
	}
}
