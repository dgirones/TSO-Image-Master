<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sizes that the attachment metadata lists but whose file is not on disk.
 *
 * It is the opposite case of TSOIMMA_Size_Scanner, which only counts size files that exist
 * (a stale metadata entry never shows up there). Here the metadata says "medium is
 * foto-300x225.webp" and that file is gone, so the admin screens and the site request a
 * file that returns 404.
 *
 * Repair, per image and never touching the original file:
 *  - relink     : the same size exists with another extension (jpg/webp...): the metadata is pointed at it.
 *  - regenerate : the size is still registered and the image is big enough: WordPress makes it again.
 *  - drop       : the size is not registered any more (or the image is smaller): the stale entry is removed.
 *
 * @package TSO_Image_Master
 */
class TSOIMMA_Missing_Sizes {

	/** @var int Attachments checked per scan request. */
	const SCAN_BATCH = 200;

	/** @var int Attachments repaired per request (each one may generate several files). */
	const REPAIR_BATCH = 5;

	/** @var string[] Image extensions tried when a size file exists under another extension. */
	const ALT_EXTENSIONS = array( 'webp', 'avif', 'jpg', 'jpeg', 'png', 'gif' );

	/**
	 * Check one batch of image attachments.
	 *
	 * @param int $after_id Attachment ID cursor (0 on the first batch).
	 * @param int $limit    Attachments to check.
	 * @return array{items: array, scanned: int, total: int, after_id: int, done: bool}
	 */
	public static function scan_batch( $after_id = 0, $limit = self::SCAN_BATCH ) {
		global $wpdb;

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		@set_time_limit( 120 );

		$after_id = max( 0, (int) $after_id );
		$limit    = max( 20, min( 500, (int) $limit ) );
		$like     = $wpdb->esc_like( 'image/' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE %s",
				$like
			)
		);
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE %s AND ID > %d
				 ORDER BY ID ASC LIMIT %d",
				$like,
				$after_id,
				$limit
			)
		);
		// phpcs:enable

		$ids = array_map( 'absint', (array) $ids );
		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', $ids );
		}

		$registered = TSOIMMA_Size_Scanner::get_registered_size_names();
		$items      = array();
		$last_id    = $after_id;

		foreach ( $ids as $id ) {
			$last_id = $id;
			$item    = self::inspect( $id, $registered );
			if ( $item ) {
				$items[] = $item;
			}
		}

		return array(
			'items'    => $items,
			'scanned'  => count( $ids ),
			'total'    => $total,
			'after_id' => $last_id,
			'done'     => count( $ids ) < $limit,
		);
	}

	/**
	 * Missing sizes of one attachment, or null when there is nothing to report.
	 *
	 * Images whose original is not on this server (offloaded media, ghost attachments) are skipped:
	 * every size would look missing, and the ghost tool already covers the second case.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string[] $registered    Size names registered right now.
	 * @return array<string,mixed>|null
	 */
	private static function inspect( $attachment_id, array $registered ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return null;
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			return null;
		}

		$dir     = trailingslashit( dirname( $file ) );
		$width   = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height  = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$sizes   = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		$missing = array();

		foreach ( $meta['sizes'] as $name => $data ) {
			$size_file = isset( $data['file'] ) ? (string) $data['file'] : '';
			if ( '' === $size_file || is_file( $dir . $size_file ) ) {
				continue;
			}

			$action = 'drop';
			if ( '' !== self::find_alternative_file( $dir, $size_file ) ) {
				$action = 'relink';
			} elseif ( in_array( (string) $name, $registered, true ) && self::would_be_generated( $sizes, (string) $name, $width, $height ) ) {
				$action = 'regenerate';
			}

			$missing[] = array(
				'size'   => (string) $name,
				'file'   => $size_file,
				'action' => $action,
			);
		}

		if ( empty( $missing ) ) {
			return null;
		}

		return array(
			'id'       => (int) $attachment_id,
			'filename' => basename( $file ),
			'missing'  => $missing,
			'thumb'    => method_exists( 'TSOIMMA_Image_Manager', 'get_existing_image_url' )
				? TSOIMMA_Image_Manager::get_existing_image_url( $attachment_id, 'thumbnail' )
				: (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
		);
	}

	/**
	 * Same file name with another image extension that does exist ("foto-300x225.jpg" -> "foto-300x225.webp").
	 *
	 * @param string $dir       Folder with trailing slash.
	 * @param string $size_file File name listed in the metadata.
	 * @return string Existing file name or ''.
	 */
	private static function find_alternative_file( $dir, $size_file ) {
		$info = pathinfo( $size_file );
		if ( empty( $info['filename'] ) ) {
			return '';
		}
		$current = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';
		foreach ( self::ALT_EXTENSIONS as $ext ) {
			if ( $ext === $current ) {
				continue;
			}
			$candidate = $info['filename'] . '.' . $ext;
			if ( is_file( $dir . $candidate ) && filesize( $dir . $candidate ) > 0 ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Whether WordPress would create this size for an image of these dimensions
	 * (it skips sizes bigger than the image).
	 *
	 * @param array<string,array> $sizes  wp_get_registered_image_subsizes().
	 * @param string              $name   Size name.
	 * @param int                 $width  Original width.
	 * @param int                 $height Original height.
	 * @return bool
	 */
	private static function would_be_generated( array $sizes, $name, $width, $height ) {
		if ( empty( $sizes[ $name ] ) || $width <= 0 || $height <= 0 ) {
			return false;
		}
		$size = $sizes[ $name ];
		return (bool) image_resize_dimensions(
			$width,
			$height,
			(int) $size['width'],
			(int) $size['height'],
			! empty( $size['crop'] )
		);
	}

	/**
	 * Repair the missing sizes of one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{id:int,ok:bool,error:string,relinked:int,regenerated:int,dropped:int,filename:string}
	 */
	public static function repair( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$result        = array(
			'id'          => $attachment_id,
			'ok'          => false,
			'error'       => '',
			'relinked'    => 0,
			'regenerated' => 0,
			'dropped'     => 0,
			'filename'    => '',
		);

		$post = $attachment_id ? get_post( $attachment_id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			$result['error'] = __( 'This is not an image from the media library.', 'tso-image-master' );
			return $result;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			$result['error'] = __( 'The original file is missing, so nothing can be regenerated.', 'tso-image-master' );
			return $result;
		}
		$result['filename'] = basename( $file );

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			$result['ok'] = true;
			return $result;
		}

		$dir     = trailingslashit( dirname( $file ) );
		$removed = array();

		foreach ( $meta['sizes'] as $name => $data ) {
			$size_file = isset( $data['file'] ) ? (string) $data['file'] : '';
			if ( '' === $size_file || is_file( $dir . $size_file ) ) {
				continue;
			}

			$alternative = self::find_alternative_file( $dir, $size_file );
			if ( '' !== $alternative ) {
				$meta['sizes'][ $name ]['file']      = $alternative;
				$meta['sizes'][ $name ]['mime-type'] = self::mime_for_file( $dir . $alternative, isset( $data['mime-type'] ) ? (string) $data['mime-type'] : '' );
				++$result['relinked'];
				continue;
			}

			unset( $meta['sizes'][ $name ] );
			$removed[] = (string) $name;
		}

		// Save the relinks and the removals first: from here on the metadata only lists files that exist.
		if ( $result['relinked'] > 0 || ! empty( $removed ) ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		if ( ! empty( $removed ) ) {
			if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			// Regenerating must not trigger this plugin's own convert-on-upload hook.
			$auto_hook = class_exists( 'TSOIMMA_Auto_Optimizer' )
				&& has_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ) );
			if ( $auto_hook ) {
				remove_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ), 20 );
			}

			// Only the sizes removed above: a size that was cleaned up on purpose (Image Sizes tab) must not come back.
			$only_removed = static function ( $sizes ) use ( $removed ) {
				return array_intersect_key( (array) $sizes, array_flip( $removed ) );
			};
			add_filter( 'intermediate_image_sizes_advanced', $only_removed, PHP_INT_MAX );

			// Creates the registered sizes that the metadata no longer lists.
			$updated = wp_update_image_subsizes( $attachment_id );

			remove_filter( 'intermediate_image_sizes_advanced', $only_removed, PHP_INT_MAX );

			if ( $auto_hook ) {
				add_action( 'wp_generate_attachment_metadata', array( 'TSOIMMA_Auto_Optimizer', 'on_upload' ), 20, 2 );
			}

			if ( is_wp_error( $updated ) ) {
				$result['error'] = $updated->get_error_message();
			}

			$after = wp_get_attachment_metadata( $attachment_id );
			foreach ( $removed as $name ) {
				$new_file = isset( $after['sizes'][ $name ]['file'] ) ? (string) $after['sizes'][ $name ]['file'] : '';
				if ( '' !== $new_file && is_file( $dir . $new_file ) ) {
					++$result['regenerated'];
				} else {
					++$result['dropped'];
				}
			}
		}

		clean_attachment_cache( $attachment_id );
		$result['ok'] = ( '' === $result['error'] );

		return $result;
	}

	/**
	 * Mime type of an image file (falls back to the previous value).
	 *
	 * @param string $path     Absolute path.
	 * @param string $fallback Mime type to keep when it cannot be detected.
	 * @return string
	 */
	private static function mime_for_file( $path, $fallback ) {
		$type = wp_check_filetype( $path );
		return ! empty( $type['type'] ) ? (string) $type['type'] : $fallback;
	}
}
