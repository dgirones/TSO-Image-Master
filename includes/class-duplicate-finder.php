<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find duplicate image attachments by file hash.
 */
class TSOIMMA_Duplicate_Finder {

	/**
	 * Scan image attachments and group by MD5 hash.
	 *
	 * @param int $limit Max attachments to scan (0 = all).
	 * @return array<string, mixed>
	 */
	public static function scan( $limit = 500 ) {
		$limit = absint( $limit );
		$args  = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
		);

		$ids    = get_posts( $args );
		$groups = array();

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

			if ( ! isset( $groups[ $hash ] ) ) {
				$groups[ $hash ] = array(
					'hash'  => $hash,
					'size'  => filesize( $file_path ),
					'items' => array(),
				);
			}

			$groups[ $hash ]['items'][] = array(
				'id'       => $attachment_id,
				'title'    => get_the_title( $attachment_id ),
				'filename' => basename( $file_path ),
				'url'      => wp_get_attachment_url( $attachment_id ),
				'thumb'    => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
				'used_in'  => TSOIMMA_Image_Manager::is_attachment_referenced( $attachment_id ) ? 1 : 0,
			);
		}

		$duplicate_groups = array_values(
			array_filter(
				$groups,
				function ( $group ) {
					return count( $group['items'] ) > 1;
				}
			)
		);

		usort(
			$duplicate_groups,
			function ( $a, $b ) {
				return count( $b['items'] ) <=> count( $a['items'] );
			}
		);

		$wasted = 0;
		foreach ( $duplicate_groups as $group ) {
			$count = count( $group['items'] );
			if ( $count > 1 ) {
				$wasted += (int) $group['size'] * ( $count - 1 );
			}
		}

		return array(
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
			'scanned'         => count( $ids ),
		);
	}
}
