<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Escàner de mides d'imatge generades a la biblioteca de mitjans.
 *
 * Agrupa tots els fitxers de mida (thumbnail, medium, wp_review...) que
 * WordPress ha generat per als attachments, per nom de mida, amb comptador
 * i espai ocupat. Permet detectar mides que ja no estan registrades per cap
 * tema/plugin actiu (per exemple perquè s'ha desactivat un plugin que
 * definia una mida pròpia) perquè l'usuari decideixi si val la pena
 * eliminar-les en massa, i eliminar-les — sense tocar mai el fitxer
 * original ni les altres mides de l'attachment.
 *
 * @package TSO_Image_Master
 */
class TSOIMMA_Size_Scanner {

	/** @var int Attachments processats per lot intern durant scan(). */
	private const SCAN_BATCH = 500;

	/**
	 * Noms de mida que WordPress generaria ARA MATEIX per a una pujada nova.
	 *
	 * No n'hi ha prou amb wp_get_registered_image_subsizes(): aquesta funció
	 * només diu quines mides s'han declarat amb add_image_size() alguna vegada,
	 * però no sap si un tema o plugin les ha desactivades via el filtre
	 * 'intermediate_image_sizes_advanced' (el mateix mecanisme que WordPress
	 * fa servir per decidir, just abans de generar les miniatures, quines
	 * mides es creen realment). Apliquem aquí el mateix filtre perquè la
	 * columna "Registrada ara?" coincideixi amb qualsevol altra pantalla
	 * (d'aquest plugin o d'un altre) que activi/desactivi mides per aquesta via.
	 *
	 * @return string[]
	 */
	public static function get_registered_size_names() {
		if ( ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
			return array();
		}

		$sizes = wp_get_registered_image_subsizes();

		/** This filter is documented in wp-admin/includes/image.php */
		$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes, array(), 0 );

		if ( ! is_array( $sizes ) ) {
			return array();
		}

		return array_values( array_map( 'strval', array_keys( $sizes ) ) );
	}

	/**
	 * Mides que WordPress genera pel seu compte fora del registre normal
	 * d'add_image_size() / wp_get_registered_image_subsizes(), i que per
	 * tant SEMPRE sortirien com "no registrades" encara que siguin
	 * necessàries. La més habitual: les mides de la icona del lloc
	 * ("site_icon-32", "site_icon-192"...), que WordPress core genera
	 * expressament per a l'attachment triat a Aparença → Personalitzar →
	 * Identitat del lloc (favicon/icona d'app) — vegeu
	 * WP_Site_Icon::intermediate_image_sizes() al nucli. Esborrar-les
	 * trencaria la icona del lloc als navegadors/dispositius que ja la
	 * tinguin en caché amb aquesta mida.
	 *
	 * @param string $size_name Nom de la mida.
	 * @return bool
	 */
	private static function is_core_protected_size_name( $size_name ) {
		return (bool) preg_match( '/^site_icon-\d+$/', (string) $size_name );
	}

	/**
	 * Escaneja tota la biblioteca de mitjans i agrupa els fitxers de mida
	 * generats per nom de mida.
	 *
	 * @return array{sizes: array, total_images: int, registered_names: string[]}
	 */
	public static function scan() {
		$registered_names = self::get_registered_size_names();

		$by_name      = array();
		$total_images = 0;
		$offset       = 0;

		do {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'posts_per_page' => self::SCAN_BATCH,
					'offset'         => $offset,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			$batch_count = count( $ids );
			if ( ! $batch_count ) {
				break;
			}
			update_meta_cache( 'post', $ids );

			foreach ( $ids as $id ) {
				$meta = wp_get_attachment_metadata( $id );
				if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
					continue;
				}
				$file = get_attached_file( $id );
				$dir  = $file ? trailingslashit( dirname( $file ) ) : '';

				foreach ( $meta['sizes'] as $size_name => $size_data ) {
					$size_name = (string) $size_name;
					if ( '' === $size_name || empty( $size_data['file'] ) ) {
						continue;
					}
					// Count only sizes whose file actually exists on disk — a
					// stale metadata entry pointing at an already-missing file
					// is not something the admin can delete, and counting it
					// anyway inflated the total compared to a real disk audit
					// (e.g. TSO Swiss Knife's "Auditoria de mides d'imatge",
					// which only counts files it finds on disk).
					if ( '' === $dir ) {
						continue;
					}
					$path = $dir . $size_data['file'];
					if ( ! file_exists( $path ) ) {
						continue;
					}

					if ( ! isset( $by_name[ $size_name ] ) ) {
						$by_name[ $size_name ] = array(
							'name'       => $size_name,
							'count'      => 0,
							'bytes'      => 0,
							'width'      => isset( $size_data['width'] ) ? (int) $size_data['width'] : 0,
							'height'     => isset( $size_data['height'] ) ? (int) $size_data['height'] : 0,
							'registered' => in_array( $size_name, $registered_names, true ),
							'protected'  => self::is_core_protected_size_name( $size_name ),
						);
					}
					++$by_name[ $size_name ]['count'];
					$by_name[ $size_name ]['bytes'] += filesize( $path );
				}
			}

			$total_images += $batch_count;
			$offset       += self::SCAN_BATCH;
		} while ( self::SCAN_BATCH === $batch_count );

		$sizes = array_values( $by_name );
		foreach ( $sizes as &$row ) {
			$row['bytes_h'] = size_format( $row['bytes'] );
		}
		unset( $row );

		usort(
			$sizes,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return array(
			'sizes'            => $sizes,
			'total_images'     => $total_images,
			'registered_names' => $registered_names,
		);
	}

	/**
	 * Llista paginada d'attachments que tenen generada una mida concreta.
	 *
	 * @param string $size_name Nom simbòlic de la mida (p.ex. "wp_review").
	 * @param int    $limit     Elements per pàgina.
	 * @param int    $offset    Offset.
	 * @return array{items: array, total: int}
	 */
	public static function list_for_size( $size_name, $limit = 60, $offset = 0 ) {
		global $wpdb;

		$size_name = sanitize_key( $size_name );
		$limit     = max( 1, (int) $limit );
		$offset    = max( 0, (int) $offset );

		if ( '' === $size_name ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		// Pre-filtre ràpid amb LIKE sobre el meta serialitzat; la coincidència
		// real es confirma tot seguit desserialitzant amb wp_get_attachment_metadata().
		$like = '%"' . $wpdb->esc_like( $size_name ) . '";a:%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pre-filter only; every candidate is re-verified below via wp_get_attachment_metadata().
		$candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s ORDER BY post_id DESC",
				$like
			)
		);

		$matched = array();
		foreach ( $candidate_ids as $id ) {
			$id   = (int) $id;
			$meta = wp_get_attachment_metadata( $id );
			if ( empty( $meta['sizes'][ $size_name ]['file'] ) ) {
				continue;
			}
			// Same rule as scan(): only count/list a size file that actually
			// exists on disk, so the total here matches the summary table
			// and a stale metadata entry (file already gone) never shows up
			// as something the admin can select and delete.
			$file = get_attached_file( $id );
			if ( ! $file ) {
				continue;
			}
			$path = trailingslashit( dirname( $file ) ) . $meta['sizes'][ $size_name ]['file'];
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$matched[] = $id;
		}

		$total    = count( $matched );
		$page_ids = array_slice( $matched, $offset, $limit );
		$items    = array();

		$upload_dir  = wp_upload_dir();
		$base_dir    = trailingslashit( $upload_dir['basedir'] );
		$base_url    = trailingslashit( $upload_dir['baseurl'] );

		foreach ( $page_ids as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			$sz   = $meta['sizes'][ $size_name ];
			$file = get_attached_file( $id );
			if ( ! $file ) {
				continue;
			}
			$dir      = trailingslashit( dirname( $file ) );
			$path     = $dir . $sz['file'];
			$rel_dir  = trailingslashit( dirname( str_replace( $base_dir, '', wp_normalize_path( $file ) ) ) );
			$rel_dir  = ( '.' === trim( $rel_dir, '/' ) ) ? '' : $rel_dir;
			$bytes    = file_exists( $path ) ? filesize( $path ) : 0;

			$items[] = array(
				'id'        => $id,
				'filename'  => basename( $file ),
				'size_file' => $sz['file'],
				'url'       => $base_url . ltrim( $rel_dir, '/' ) . $sz['file'],
				'edit_url'  => get_edit_post_link( $id, 'raw' ),
				'bytes'     => $bytes,
				'bytes_h'   => $bytes ? size_format( $bytes ) : '',
				'width'     => isset( $sz['width'] ) ? (int) $sz['width'] : 0,
				'height'    => isset( $sz['height'] ) ? (int) $sz['height'] : 0,
			);
		}

		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Tots els IDs d'attachment que tenen generada una mida concreta,
	 * sense paginar. Fa servir el mateix pre-filtre + verificació que
	 * list_for_size(), perquè el botó "Eliminar totes" esborri exactament
	 * el mateix conjunt de fitxers que la taula mostra, encara que hi hagi
	 * centenars i l'admin no hagi obert mai el llistat pàgina a pàgina.
	 *
	 * @param string $size_name Nom simbòlic de la mida.
	 * @return int[] IDs d'attachment, verificats (el fitxer d'aquesta mida existeix al disc).
	 */
	public static function get_all_ids_for_size( $size_name ) {
		global $wpdb;

		$size_name = sanitize_key( $size_name );
		if ( '' === $size_name ) {
			return array();
		}

		$like = '%"' . $wpdb->esc_like( $size_name ) . '";a:%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pre-filter only; every candidate is re-verified below via wp_get_attachment_metadata().
		$candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s ORDER BY post_id DESC",
				$like
			)
		);

		$matched = array();
		foreach ( $candidate_ids as $id ) {
			$id   = (int) $id;
			$meta = wp_get_attachment_metadata( $id );
			if ( empty( $meta['sizes'][ $size_name ]['file'] ) ) {
				continue;
			}
			$file = get_attached_file( $id );
			if ( ! $file ) {
				continue;
			}
			$path = trailingslashit( dirname( $file ) ) . $meta['sizes'][ $size_name ]['file'];
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$matched[] = $id;
		}

		return $matched;
	}

	/**
	 * Elimina, per a un conjunt d'attachments, únicament el fitxer generat
	 * d'una mida concreta i la seva entrada a metadata['sizes']. Mai toca
	 * el fitxer original de l'attachment ni les altres mides.
	 *
	 * @param string $size_name      Nom simbòlic de la mida.
	 * @param array  $attachment_ids IDs d'attachments.
	 * @return array{deleted: array, errors: array, bytes_freed: int, bytes_freed_h: string}
	 */
	public static function delete_size_files( $size_name, $attachment_ids ) {
		$size_name = sanitize_key( $size_name );
		$deleted   = array();
		$errors    = array();
		$bytes     = 0;

		if ( '' === $size_name || ! is_array( $attachment_ids ) ) {
			return array(
				'deleted'       => $deleted,
				'errors'        => $errors,
				'bytes_freed'   => 0,
				'bytes_freed_h' => size_format( 0 ),
				'backup_ok'     => false,
				'backup_zip'    => '',
				'backup_error'  => '',
			);
		}

		// First pass: validate every candidate and resolve its on-disk path
		// WITHOUT deleting anything yet, so the backup zip below can be
		// built (and verified) from the exact same file list that will be
		// removed afterwards.
		$candidates = array();
		foreach ( $attachment_ids as $id ) {
			$id = absint( $id );
			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				$errors[] = array( 'id' => $id, 'error' => __( 'Invalid attachment ID.', 'tso-image-master' ) );
				continue;
			}

			$meta = wp_get_attachment_metadata( $id );
			if ( empty( $meta['sizes'][ $size_name ]['file'] ) ) {
				$errors[] = array( 'id' => $id, 'error' => __( 'This size no longer exists for this image (already removed?).', 'tso-image-master' ) );
				continue;
			}

			$file = get_attached_file( $id );
			if ( ! $file ) {
				$errors[] = array( 'id' => $id, 'error' => __( 'Original file not found.', 'tso-image-master' ) );
				continue;
			}

			$size_filename = (string) $meta['sizes'][ $size_name ]['file'];
			$dir           = trailingslashit( dirname( $file ) );
			$path          = $dir . $size_filename;

			if ( ! file_exists( $path ) ) {
				$errors[] = array( 'id' => $id, 'error' => __( 'File already missing on disk.', 'tso-image-master' ) );
				continue;
			}

			$candidates[] = array(
				'id'       => $id,
				'file'     => $size_filename,
				'path'     => $path,
				'meta'     => $meta,
			);
		}

		if ( empty( $candidates ) ) {
			return array(
				'deleted'       => $deleted,
				'errors'        => $errors,
				'bytes_freed'   => 0,
				'bytes_freed_h' => size_format( 0 ),
				'backup_ok'     => false,
				'backup_zip'    => '',
				'backup_error'  => '',
			);
		}

		$backup = self::create_backup_zip( $size_name, $candidates );

		// A batch this size (hundreds of files per size name) is exactly
		// what the safety backup exists for — if we could build the zip but
		// something went wrong partway (disk full, permissions...), abort
		// the whole delete rather than removing files with no safety copy.
		if ( $backup['attempted'] && ! $backup['ok'] ) {
			foreach ( $candidates as $candidate ) {
				$errors[] = array(
					'id'    => $candidate['id'],
					'error' => __( 'Skipped: the safety backup could not be created for this batch.', 'tso-image-master' ),
				);
			}
			return array(
				'deleted'       => $deleted,
				'errors'        => $errors,
				'bytes_freed'   => 0,
				'bytes_freed_h' => size_format( 0 ),
				'backup_ok'     => false,
				'backup_zip'    => '',
				'backup_error'  => $backup['error'],
			);
		}

		foreach ( $candidates as $candidate ) {
			$id            = $candidate['id'];
			$path          = $candidate['path'];
			$size_filename = $candidate['file'];
			$meta          = $candidate['meta'];
			$freed         = filesize( $path );

			wp_delete_file( $path );

			if ( file_exists( $path ) ) {
				$errors[] = array( 'id' => $id, 'error' => __( 'Could not delete the file from disk.', 'tso-image-master' ) );
				continue;
			}

			unset( $meta['sizes'][ $size_name ] );
			wp_update_attachment_metadata( $id, $meta );

			$bytes    += $freed;
			$deleted[] = array(
				'id'     => $id,
				'file'   => $size_filename,
				'bytes'  => $freed,
			);
		}

		if ( ! empty( $deleted ) ) {
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache integration hook, name is defined by that plugin.
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
			}
			if ( function_exists( 'wpfc_clear_all_cache' ) ) {
				wpfc_clear_all_cache();
			}
			if ( class_exists( 'TSOIMMA_Dashboard' ) ) {
				TSOIMMA_Dashboard::flush_backup_stats_cache();
			}
		}

		return array(
			'deleted'       => $deleted,
			'errors'        => $errors,
			'bytes_freed'   => $bytes,
			'bytes_freed_h' => size_format( $bytes ),
			'backup_ok'     => $backup['ok'],
			'backup_zip'    => $backup['relative_path'],
			'backup_error'  => $backup['error'],
		);
	}

	/**
	 * Bundles every file about to be deleted for one batch into a single
	 * zip, instead of one small backup file per attachment.
	 *
	 * A single size name can cover hundreds of attachments (wp_review_large
	 * alone had 674 on this site) — copying each subsize file out
	 * individually, the way the optimizer backs up a single original
	 * before converting it, would scatter hundreds of tiny files across the
	 * backup tree per click and make the daily retention sweep (which
	 * walks that whole tree) far slower for no real benefit, since these
	 * files are only ever restored together as "what I deleted just now".
	 * One zip per delete batch keeps that sweep, and any manual recovery,
	 * to a single file.
	 *
	 * The zip is named with the same `_tso_im_backup.<ext>` suffix the rest
	 * of the plugin already uses for backups, so it's picked up for free by
	 * the existing retention purge (days / max MB) and by the "Còpies de
	 * seguretat TSO" dashboard counter — no separate storage or cleanup
	 * system to build or maintain.
	 *
	 * @param string $size_name  Size name being cleaned up.
	 * @param array  $candidates Each: array{id:int, file:string, path:string}.
	 * @return array{attempted: bool, ok: bool, relative_path: string, error: string}
	 */
	private static function create_backup_zip( $size_name, $candidates ) {
		$result = array(
			'attempted'     => false,
			'ok'            => false,
			'relative_path' => '',
			'error'         => '',
		);

		if ( ! class_exists( 'ZipArchive' ) ) {
			$result['error'] = __( 'The PHP ZIP extension is not available on this server, so no backup could be made before deleting.', 'tso-image-master' );
			return $result;
		}

		$upload_dir  = wp_upload_dir();
		$backup_base = trailingslashit( $upload_dir['basedir'] ) . 'tso-image-master/size-backups/' . $size_name;

		if ( ! wp_mkdir_p( $backup_base ) ) {
			$result['attempted'] = true;
			$result['error']     = __( 'Could not create the backup folder.', 'tso-image-master' );
			return $result;
		}

		$filename  = $size_name . '-' . gmdate( 'Y-m-d-His' ) . '-' . count( $candidates ) . 'files_tso_im_backup.zip';
		$zip_path  = trailingslashit( $backup_base ) . $filename;

		$result['attempted'] = true;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$result['error'] = __( 'Could not create the backup zip file.', 'tso-image-master' );
			return $result;
		}

		foreach ( $candidates as $candidate ) {
			// Prefix with the attachment ID so files with the same generated
			// name (e.g. two different photos both producing "img-65x65.jpg")
			// never collide inside the archive.
			$entry_name = $candidate['id'] . '__' . wp_basename( $candidate['path'] );
			$zip->addFile( $candidate['path'], $entry_name );
		}

		$ok = $zip->close();

		if ( ! $ok || ! file_exists( $zip_path ) || 0 === (int) filesize( $zip_path ) ) {
			self::delete_file_quietly( $zip_path );
			$result['error'] = __( 'The backup zip file could not be finalized.', 'tso-image-master' );
			return $result;
		}

		$result['ok']            = true;
		$result['relative_path'] = 'tso-image-master/size-backups/' . $size_name . '/' . $filename;
		return $result;
	}

	/**
	 * @param string $path Absolute path.
	 * @return void
	 */
	private static function delete_file_quietly( $path ) {
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
