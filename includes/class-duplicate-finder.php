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

			// Show the copy that's genuinely used in content first — a user
			// scanning the group visually should see the one to keep at a
			// glance, not have to read every card to find it buried further
			// down. This also fixes the "Keep this one" radio defaulting to
			// whichever copy the scan happened to list first, which could
			// be the wrong one to keep. array_multisort with a decorated
			// index keeps ties (e.g. two "safe to delete" copies) in their
			// original relative order.
			$sort_priority = array();
			$sort_index    = array();
			foreach ( $items as $i => $item ) {
				$sort_priority[] = self::dup_item_sort_priority( $item );
				$sort_index[]    = $i;
			}
			array_multisort( $sort_priority, SORT_ASC, $sort_index, SORT_ASC, $items );

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
	 * Filter a reference-report 'direct' list down to genuine embeds —
	 * i.e. drop any ref that is merely 'parent_only' (post_parent points at
	 * that post, but nothing in its content/featured image/custom fields
	 * actually shows the file). Shared by enrich_item_usage() (decides the
	 * "keep"/"attached only"/"safe to delete" badges) and delete_if_unused()
	 * (the server-side re-check right before a single-item delete) so the
	 * two never drift apart.
	 *
	 * @param array<int, array<string, mixed>> $direct 'direct' refs from get_attachment_reference_report().
	 * @return array<int, array<string, mixed>>
	 */
	private static function genuinely_embedded_refs( $direct ) {
		return array_filter(
			(array) $direct,
			function ( $ref ) {
				return empty( $ref['parent_only'] );
			}
		);
	}

	/**
	 * Recompute usage/badge info for a single attachment on demand (no full
	 * rescan) — used after an in-place action on one duplicate item (e.g.
	 * "Desvincular"/Unlink) so the Duplicates tab can update just that
	 * item's badge and buttons.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param int[] $sibling_ids   Other attachment IDs in the same hash group, if known.
	 * @return array<string, mixed>
	 */
	public static function refresh_item_usage( $attachment_id, $sibling_ids = array() ) {
		return self::enrich_item_usage( array( 'id' => absint( $attachment_id ) ), $sibling_ids );
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

		// A ref with 'parent_only' means the attachment's post_parent points
		// at that post (it's "attached to" it in the Media Library) but
		// nothing in the post's actual content, featured image, or custom
		// fields displays it — see
		// TSOIMMA_Image_Manager::describe_attachment_parent_reference().
		// Only recommend "keep" when at least one reference is a genuine
		// embed; otherwise the badge would tell the user to keep a copy
		// that isn't actually shown anywhere.
		$genuinely_embedded = self::genuinely_embedded_refs( $direct );

		$item['usage_status']     = isset( $report['status'] ) ? (string) $report['status'] : 'none';
		$item['used_in_count']    = isset( $report['used_in_count'] ) ? (int) $report['used_in_count'] : 0;
		$item['keep_recommended'] = ! empty( $genuinely_embedded );
		$item['attached_only']    = ! empty( $direct ) && empty( $genuinely_embedded );
		// Deleting an attachment doesn't need its post_parent link cleared
		// first (that link disappears with the post being deleted, same as
		// WordPress's own Media Library, which lets you delete a merely
		// "attached" file directly). So "safe to delete" only needs to rule
		// out a GENUINE embed — a bare post_parent link (attached_only) or a
		// filename-only match never blocks deletion. Keep this in sync with
		// TSOIMMA_Duplicate_Finder::delete_if_unused()'s server-side re-check.
		$item['safe_to_delete']   = empty( $genuinely_embedded );

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
	 * Display-order priority for a duplicate item within its group — lower
	 * sorts first. The genuinely-used copy leads (so it's the one visible
	 * at a glance and the one the "Keep this one" radio defaults to),
	 * merely-attached copies come next, and copies already flagged safe to
	 * delete sort last.
	 *
	 * @param array<string, mixed> $item Enriched duplicate item.
	 * @return int
	 */
	private static function dup_item_sort_priority( $item ) {
		if ( ! empty( $item['keep_recommended'] ) ) {
			return 0;
		}
		if ( ! empty( $item['attached_only'] ) ) {
			return 1;
		}
		return 2;
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

	/**
	 * Merge a group of byte-identical duplicate attachments into one.
	 *
	 * For each attachment in $delete_ids: rewrite every reference the plugin
	 * can positively identify (post_content URLs/IDs, featured image,
	 * ACF/Elementor-style postmeta) so it points at $keep_id instead, then
	 * re-check the attachment and only delete it once nothing we can see
	 * still references it. A "filename only" (indirect) match, or any
	 * reference we could not safely rewrite, blocks deletion of that one
	 * file — it is reported back instead of guessed at.
	 *
	 * @param int   $keep_id    Attachment ID to keep.
	 * @param int[] $delete_ids Attachment IDs to retire.
	 * @return array<string, mixed>
	 */
	public static function merge_group( $keep_id, $delete_ids ) {
		$keep_id    = absint( $keep_id );
		$delete_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $delete_ids ) ) ) );

		$result = array(
			'keep_id'       => $keep_id,
			'updated_posts' => array(),
			'deleted_ids'   => array(),
			'skipped'       => array(),
			'errors'        => array(),
		);

		if ( $keep_id <= 0 || 'attachment' !== get_post_type( $keep_id ) || empty( $delete_ids ) ) {
			$result['errors'][] = 'invalid_arguments';
			return $result;
		}

		$keep_file = get_attached_file( $keep_id );
		if ( ! $keep_file || ! is_file( $keep_file ) || ! is_readable( $keep_file ) ) {
			$result['errors'][] = 'keep_file_missing';
			return $result;
		}
		$keep_hash = md5_file( $keep_file );

		// Make sure the kept attachment has every registered size generated,
		// so post content that points at a specific size has somewhere to go.
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$meta = wp_generate_attachment_metadata( $keep_id, $keep_file );
			if ( $meta ) {
				wp_update_attachment_metadata( $keep_id, $meta );
			}
		}

		foreach ( $delete_ids as $delete_id ) {
			if ( $delete_id === $keep_id ) {
				continue;
			}

			$delete_file = get_attached_file( $delete_id );
			if ( ! $delete_file || ! is_file( $delete_file ) || ! is_readable( $delete_file ) ) {
				$result['skipped'][ $delete_id ] = array( 'reason' => 'file_missing' );
				continue;
			}

			// Defensive: refuse to touch it if the file changed since the scan ran.
			if ( ! $keep_hash || md5_file( $delete_file ) !== $keep_hash ) {
				$result['skipped'][ $delete_id ] = array( 'reason' => 'hash_mismatch' );
				continue;
			}

			$report = TSOIMMA_Image_Manager::get_attachment_reference_report( $delete_id );

			foreach ( (array) $report['direct'] as $ref ) {
				$post_id = isset( $ref['id'] ) ? absint( $ref['id'] ) : 0;
				if ( $post_id <= 0 ) {
					continue;
				}

				$changed = false;
				$how     = isset( $ref['how'] ) ? (string) $ref['how'] : '';

				if ( ! empty( $ref['featured'] ) ) {
					update_post_meta( $post_id, '_thumbnail_id', $keep_id );
					$changed = true;
				} elseif ( 'meta (ACF/Elementor)' === $how ) {
					$changed = self::repoint_postmeta_value( $post_id, $delete_id, $keep_id );
				} elseif ( 'adjunta' === $how ) {
					// post_parent-only reference (the attachment record is
					// "attached to" this post in the Media Library, but
					// nothing in post_content actually displays it — see
					// TSOIMMA_Image_Manager::describe_attachment_parent_reference()).
					// There is no content to rewrite here; the file being
					// deleted resolves this on its own, but detach it now
					// so the re-check below doesn't keep seeing this same
					// non-content reference and refuse the deletion forever.
					$updated = wp_update_post(
						array(
							'ID'          => $delete_id,
							'post_parent' => 0,
						),
						true
					);
					$changed = ! is_wp_error( $updated ) && 0 !== $updated;
				} else {
					$changed = self::repoint_post_content( $post_id, $delete_id, $keep_id );
				}

				if ( $changed ) {
					if ( ! isset( $result['updated_posts'][ $post_id ] ) ) {
						$result['updated_posts'][ $post_id ] = array();
					}
					$result['updated_posts'][ $post_id ][] = $delete_id;

					if ( class_exists( 'TSOIMMA_History' ) ) {
						// Store ready-to-display fields (post title, kept
						// file's name, and the already-localized "how" text
						// from get_attachment_reference_report()) so the
						// History tab's Details column can show what
						// actually happened without having to re-derive a
						// label from the raw 'how' key client-side.
						TSOIMMA_History::log(
							$delete_id,
							'dup_merge_rewrite',
							array(
								'post_id'       => $post_id,
								'post_title'    => get_the_title( $post_id ),
								'keep_id'       => $keep_id,
								'keep_filename' => basename( $keep_file ),
								'how'           => $how,
								'detail'        => isset( $ref['detail'] ) ? (string) $ref['detail'] : '',
							)
						);
					}
				}
			}

			// Re-check: only delete once nothing we can see still points at it.
			$after = TSOIMMA_Image_Manager::get_attachment_reference_report( $delete_id );
			if ( 'none' !== $after['status'] ) {
				$result['skipped'][ $delete_id ] = array(
					'reason' => 'references_remaining',
					'refs'   => array_merge( (array) $after['direct'], (array) $after['indirect'] ),
				);
				continue;
			}

			// Capture filename/title before deleting — TSOIMMA_History::log()
			// otherwise looks them up via get_attached_file()/get_the_title(),
			// which return nothing once the attachment post no longer exists,
			// leaving the History row's "Archivo" column blank.
			$delete_filename = basename( $delete_file );
			$delete_title    = get_the_title( $delete_id );

			$deleted = wp_delete_attachment( $delete_id, false );
			if ( $deleted ) {
				$result['deleted_ids'][] = $delete_id;
				if ( class_exists( 'TSOIMMA_History' ) ) {
					TSOIMMA_History::log(
						$delete_id,
						'dup_merge_delete',
						array(
							'keep_id'          => $keep_id,
							'keep_filename'    => basename( $keep_file ),
							'filename'         => $delete_filename,
							'attachment_title' => $delete_title,
						)
					);
				}
			} else {
				$result['skipped'][ $delete_id ] = array( 'reason' => 'delete_failed' );
			}
		}

		return $result;
	}

	/**
	 * Rewrite every URL/ID reference to $old_id inside a post's content so it
	 * points at $new_id instead. Caller has already verified both attachments
	 * are byte-identical, so nothing visual changes — only which file/ID is
	 * referenced.
	 *
	 * @param int $post_id Post being edited.
	 * @param int $old_id  Attachment ID being retired.
	 * @param int $new_id  Attachment ID to keep.
	 * @return bool Whether the post content actually changed.
	 */
	private static function repoint_post_content( $post_id, $old_id, $new_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$content = (string) $post->post_content;
		if ( '' === $content ) {
			return false;
		}
		$original = $content;

		$old_url = wp_get_attachment_url( $old_id );
		$new_url = wp_get_attachment_url( $new_id );

		if ( $old_url && $new_url ) {
			$upload_dir = wp_upload_dir();
			$url_pairs  = array( $old_url => $new_url );

			$old_rel = str_replace( $upload_dir['baseurl'], '', $old_url );
			$new_rel = str_replace( $upload_dir['baseurl'], '', $new_url );
			if ( $old_rel !== $old_url ) {
				$url_pairs[ $old_rel ] = $new_rel;
			}

			// Every generated intermediate size (thumbnail/medium/large/etc.).
			$old_meta = wp_get_attachment_metadata( $old_id );
			$new_meta = wp_get_attachment_metadata( $new_id );
			if ( ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) {
				foreach ( $old_meta['sizes'] as $size_key => $old_size ) {
					if ( empty( $old_size['file'] ) || empty( $new_meta['sizes'][ $size_key ]['file'] ) ) {
						continue;
					}
					$old_size_url               = str_replace( wp_basename( $old_url ), $old_size['file'], $old_url );
					$new_size_url               = str_replace( wp_basename( $new_url ), $new_meta['sizes'][ $size_key ]['file'], $new_url );
					$url_pairs[ $old_size_url ] = $new_size_url;
				}
			}

			foreach ( $url_pairs as $search => $replace ) {
				if ( '' === (string) $search || $search === $replace ) {
					continue;
				}
				$content = str_replace( $search, $replace, $content );
			}
		}

		// Structural ID references: wp-image-N class, data-id="N", Gutenberg "id":N / \"id\":N, "ids":[...N...].
		$old_id_q = preg_quote( (string) $old_id, '/' );
		$content  = preg_replace( '/\bwp-image-' . $old_id_q . '\b/', 'wp-image-' . $new_id, $content );
		$content  = preg_replace( '/\bdata-id="' . $old_id_q . '"/', 'data-id="' . $new_id . '"', $content );
		$content  = preg_replace( '/((?:"|\\\\")id(?:"|\\\\")\s*:\s*)' . $old_id_q . '(?=[,\}\s])/', '${1}' . $new_id, $content );
		$content  = preg_replace(
			'/("ids"\s*:\s*\[[^\]]*)(?<![0-9])' . $old_id_q . '(?![0-9])([^\]]*\])/',
			'${1}' . $new_id . '${2}',
			$content
		);

		// Bare filename (Classic Editor without a full URL or block ID).
		$old_filename = $old_url ? wp_basename( $old_url ) : '';
		$new_filename = $new_url ? wp_basename( $new_url ) : '';
		if ( $old_filename && $new_filename && $old_filename !== $new_filename ) {
			$content = str_replace( $old_filename, $new_filename, $content );
		}

		if ( $content === $original ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		return ! is_wp_error( $updated ) && 0 !== $updated;
	}

	/**
	 * Repoint postmeta rows whose value is exactly the old attachment ID
	 * (ACF/Elementor-style single-image fields) to the new attachment ID.
	 *
	 * @param int $post_id Post being edited.
	 * @param int $old_id  Attachment ID being retired.
	 * @param int $new_id  Attachment ID to keep.
	 * @return bool Whether any row changed.
	 */
	private static function repoint_postmeta_value( $post_id, $old_id, $new_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta}
				 WHERE post_id = %d AND meta_value = %s
				 AND meta_key NOT IN ( '_thumbnail_id', '_wp_attachment_metadata', '_wp_attached_file' )",
				$post_id,
				(string) $old_id
			)
		);

		if ( empty( $meta_ids ) ) {
			return false;
		}

		foreach ( $meta_ids as $meta_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- updated by meta_id (primary key), not searched by meta_value; verified in repoint_postmeta_value() above.
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => (string) $new_id ), array( 'meta_id' => absint( $meta_id ) ) );
		}
		clean_post_cache( $post_id );

		return true;
	}

	/**
	 * Delete a single duplicate attachment, but only after re-checking
	 * server-side that nothing references it. The "safe to delete" badge in
	 * the UI reflects the state at scan time; a post could have started
	 * using the file since, so we never trust the client's word for a
	 * destructive action — we look again right before deleting.
	 *
	 * @param int $attachment_id Attachment ID to delete.
	 * @return array<string, mixed>
	 */
	public static function delete_if_unused( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array(
				'deleted' => false,
				'reason'  => 'invalid_attachment',
			);
		}

		$report = TSOIMMA_Image_Manager::get_attachment_reference_report( $attachment_id );

		// A post_parent-only ("adjunta") reference doesn't display the file
		// anywhere; deleting the attachment removes that link on its own,
		// the same way WordPress's own Media Library lets you delete a
		// merely "attached" file without unattaching it first. Clear it now
		// so the genuine-embed check below doesn't refuse the deletion over
		// a link that is about to disappear anyway (mirrors the 'adjunta'
		// handling in merge_group()).
		foreach ( (array) $report['direct'] as $ref ) {
			if ( ! empty( $ref['parent_only'] ) ) {
				wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => 0 ), true );
				$report = TSOIMMA_Image_Manager::get_attachment_reference_report( $attachment_id );
				break;
			}
		}

		$genuinely_embedded = self::genuinely_embedded_refs( $report['direct'] );
		if ( ! empty( $genuinely_embedded ) ) {
			return array(
				'deleted' => false,
				'reason'  => 'references_remaining',
				'refs'    => array_merge( (array) $report['direct'], (array) $report['indirect'] ),
			);
		}

		// Capture filename/title before deleting — TSOIMMA_History::log()
		// otherwise looks them up via get_attached_file()/get_the_title(),
		// which return nothing once the attachment post no longer exists,
		// leaving the History row's "Archivo" column blank.
		$delete_title = get_the_title( $attachment_id );

		$result  = TSOIMMA_Image_Manager::delete( array( $attachment_id ) );
		$deleted = false;
		$deleted_file = '';
		foreach ( (array) ( $result['deleted'] ?? array() ) as $row ) {
			if ( isset( $row['id'] ) && absint( $row['id'] ) === $attachment_id ) {
				$deleted      = true;
				$deleted_file = isset( $row['file'] ) ? (string) $row['file'] : '';
				break;
			}
		}

		if ( $deleted ) {
			if ( class_exists( 'TSOIMMA_History' ) ) {
				TSOIMMA_History::log(
					$attachment_id,
					'dup_delete_unused',
					array(
						'filename'         => $deleted_file ? basename( $deleted_file ) : '',
						'attachment_title' => $delete_title,
					)
				);
			}
			return array( 'deleted' => true );
		}

		return array(
			'deleted' => false,
			'reason'  => 'delete_failed',
			'errors'  => isset( $result['errors'] ) ? $result['errors'] : array(),
		);
	}
}
