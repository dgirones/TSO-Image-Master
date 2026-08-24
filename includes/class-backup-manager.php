<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TSO backup retention and cleanup under uploads/tso-image-master/.
 */
class TSOIMMA_Backup_Manager {

	const OPTION_KEY = 'tsoimma_backup_retention';
	const CRON_HOOK  = 'tsoimma_backup_purge';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'purge_old_backups' ) );
	}

	/**
	 * @return array<string, int>
	 */
	public static function get_settings() {
		$defaults = array(
			'days'   => 0,
			'max_mb' => 0,
		);
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$clean = array(
			'days'   => min( 3650, max( 0, absint( $saved['days'] ?? 0 ) ) ),
			'max_mb' => min( 102400, max( 0, absint( $saved['max_mb'] ?? 0 ) ) ),
		);
		return wp_parse_args( $clean, $defaults );
	}

	/**
	 * @param array<string, int> $settings Settings.
	 * @return array<string, int>
	 */
	public static function save_settings( $settings ) {
		$clean = array(
			'days'   => min( 3650, max( 0, absint( $settings['days'] ?? 0 ) ) ),
			'max_mb' => min( 102400, max( 0, absint( $settings['max_mb'] ?? 0 ) ) ),
		);
		update_option( self::OPTION_KEY, $clean );
		self::schedule_purge_cron();
		return $clean;
	}

	/**
	 * @return void
	 */
	public static function schedule_purge_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$settings = self::get_settings();
		if ( $settings['days'] <= 0 && $settings['max_mb'] <= 0 ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Delete backups older than retention settings.
	 *
	 * @return array<string, int>
	 */
	public static function purge_old_backups() {
		$settings = self::get_settings();
		$deleted  = 0;
		$freed    = 0;

		if ( $settings['days'] <= 0 && $settings['max_mb'] <= 0 ) {
			return array(
				'deleted'     => 0,
				'freed'       => 0,
				'freed_h'     => size_format( 0 ),
				'noop'        => true,
				'noop_reason' => 'retention_disabled',
			);
		}

		$files = self::list_backup_files();
		$now   = time();

		if ( $settings['days'] > 0 ) {
			$cutoff = $now - ( $settings['days'] * DAY_IN_SECONDS );
			foreach ( $files as $index => $file ) {
				if ( $file['mtime'] < $cutoff ) {
					if ( self::delete_backup_file( $file['path'] ) ) {
						++$deleted;
						$freed += (int) $file['size'];
						unset( $files[ $index ] );
					}
				}
			}
			$files = array_values( $files );
		}

		if ( $settings['max_mb'] > 0 ) {
			$max_bytes = $settings['max_mb'] * 1024 * 1024;
			$total     = 0;
			foreach ( $files as $file ) {
				$total += (int) $file['size'];
			}
			if ( $total > $max_bytes ) {
				usort(
					$files,
					function ( $a, $b ) {
						return $a['mtime'] <=> $b['mtime'];
					}
				);
				foreach ( $files as $file ) {
					if ( $total <= $max_bytes ) {
						break;
					}
					if ( self::delete_backup_file( $file['path'] ) ) {
						++$deleted;
						$freed += (int) $file['size'];
						$total -= (int) $file['size'];
					}
				}
			}
		}

		return array(
			'deleted'  => $deleted,
			'freed'    => $freed,
			'freed_h'  => size_format( $freed ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function list_backup_files() {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master';
		$files      = array();

		if ( ! is_dir( $base_dir ) ) {
			return $files;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( UnexpectedValueException $e ) {
			return $files;
		}

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}
			$path = $file_info->getPathname();
			if ( ! preg_match( '/_tso_im_backup\.[a-z0-9]+$/i', $file_info->getFilename() ) ) {
				continue;
			}
			if ( ! TSOIMMA_Optimizer::resolve_backup_path( $path, false ) ) {
				continue;
			}
			$files[] = array(
				'path'  => $path,
				'size'  => (int) $file_info->getSize(),
				'mtime' => (int) $file_info->getMTime(),
			);
		}

		return $files;
	}

	/**
	 * @param string $path Backup path.
	 * @return bool
	 */
	private static function delete_backup_file( $path ) {
		$resolved = TSOIMMA_Optimizer::resolve_backup_path( $path, true );
		if ( ! $resolved ) {
			return false;
		}
		wp_delete_file( $resolved );
		if ( file_exists( $resolved ) ) {
			return false;
		}
		self::clear_meta_for_backup_path( $path, $resolved );
		TSOIMMA_Optimizer::prune_empty_backup_dirs( $resolved );
		return true;
	}

	/**
	 * Clear attachment backup meta that pointed at a deleted backup file.
	 *
	 * @param string $original_path Path used for listing/deletion.
	 * @param string $resolved_path Real path after resolve.
	 * @return void
	 */
	private static function clear_meta_for_backup_path( $original_path, $resolved_path ) {
		global $wpdb;

		$candidates = array_unique(
			array_filter(
				array(
					(string) $original_path,
					(string) $resolved_path,
					wp_normalize_path( (string) $original_path ),
					wp_normalize_path( (string) $resolved_path ),
				)
			)
		);

		$ids = array();
		foreach ( $candidates as $candidate ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tso_im_backup_file' AND meta_value = %s",
					$candidate
				)
			);
			foreach ( (array) $found as $post_id ) {
				$ids[] = absint( $post_id );
			}
		}

		$basename = basename( (string) $resolved_path );
		if ( '' !== $basename ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tso_im_backup_file' AND meta_value LIKE %s",
					'%' . $wpdb->esc_like( $basename )
				)
			);
			foreach ( (array) $found as $post_id ) {
				$ids[] = absint( $post_id );
			}
		}

		foreach ( array_unique( array_filter( $ids ) ) as $attachment_id ) {
			$stored = (string) get_post_meta( $attachment_id, '_tso_im_backup_file', true );
			if ( '' === $stored ) {
				continue;
			}
			$stored_norm = wp_normalize_path( $stored );
			$match       = false;
			foreach ( $candidates as $candidate ) {
				if ( $stored === $candidate || $stored_norm === wp_normalize_path( $candidate ) ) {
					$match = true;
					break;
				}
			}
			if ( ! $match && $basename && false !== strpos( $stored_norm, $basename ) ) {
				$match = true;
			}
			if ( $match ) {
				TSOIMMA_Optimizer::clear_backup_meta( $attachment_id );
			}
		}
	}
}
