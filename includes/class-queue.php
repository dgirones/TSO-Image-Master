<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background job queue (WP-Cron) for long-running bulk tasks.
 */
class TSOIMMA_Queue {

	const OPTION_KEY      = 'tsoimma_job_queue';
	const CRON_HOOK       = 'tsoimma_process_queue';
	const LOCK_OPTION     = 'tsoimma_queue_lock';
	const ENQUEUE_LOCK_OPTION = 'tsoimma_queue_enqueue_lock';
	const ENQUEUE_LOCK_TTL    = 30;
	const ATTACHMENT_LOCK_PREFIX = 'tsoimma_opt_lock_';
	const BATCH_SIZE      = 5;
	const THUMBS_BATCH    = 2;
	const LOCK_TTL        = 900;
	const STUCK_SECONDS   = 900;

	/**
	 * Token held by the current batch worker (refreshed while jobs run).
	 *
	 * @var string
	 */
	private static $lock_token = '';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_batch' ) );
	}

	/**
	 * Enqueue optimize jobs.
	 *
	 * @param int[]  $attachment_ids Attachment IDs.
	 * @param string $format         Output format.
	 * @param int    $quality        Quality.
	 * @param bool   $replace        Replace original.
	 * @return array<string, mixed>
	 */
	public static function enqueue_optimize( $attachment_ids, $format, $quality, $replace = true ) {
		$requested = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
		$queued    = 0;
		$skipped   = 0;

		if ( ! self::acquire_enqueue_lock() ) {
			usleep( 100000 );
			if ( ! self::acquire_enqueue_lock() ) {
				$status             = self::get_status();
				$status['queued']   = 0;
				$status['skipped']  = count( $requested );
				$status['requested'] = count( $requested );
				return $status;
			}
		}

		try {
			$format  = sanitize_key( $format );
			$quality = tsoimma_clamp_image_quality( $quality );
			$queue   = self::get_queue();
			self::prune_finished_jobs( $queue );

			$busy = array();
			foreach ( $queue['jobs'] as $job ) {
				if ( ! isset( $job['status'], $job['attachment_id'] ) ) {
					continue;
				}
				if ( self::is_active_status( (string) $job['status'] ) ) {
					$busy[ absint( $job['attachment_id'] ) ] = true;
				}
			}

			foreach ( $requested as $attachment_id ) {
				if ( $attachment_id <= 0 ) {
					continue;
				}
				if ( isset( $busy[ $attachment_id ] ) || self::has_attachment_lock( $attachment_id ) ) {
					++$skipped;
					continue;
				}
				$queue['jobs'][] = array(
					'id'            => uniqid( 'job_', true ),
					'type'          => 'optimize',
					'attachment_id' => $attachment_id,
					'format'        => $format,
					'quality'       => $quality,
					'replace'       => (bool) $replace,
					'status'        => 'pending',
					'phase'         => 'convert',
					'error'         => '',
					'added'         => time(),
					'started'       => 0,
				);
				$busy[ $attachment_id ] = true;
				++$queued;
			}

			$queue['updated'] = time();
			update_option( self::OPTION_KEY, $queue, false );
			self::schedule();

			$status              = self::get_status();
			$status['queued']    = $queued;
			$status['skipped']   = $skipped;
			$status['requested'] = count( $requested );
			return $status;
		} finally {
			self::release_enqueue_lock();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_status() {
		$queue = self::get_queue();
		$jobs  = isset( $queue['jobs'] ) && is_array( $queue['jobs'] ) ? $queue['jobs'] : array();

		$pending         = 0;
		$processing      = 0;
		$thumbs_pending  = 0;
		$done            = 0;
		$errors          = 0;
		$total           = 0;
		foreach ( $jobs as $job ) {
			$status = isset( $job['status'] ) ? (string) $job['status'] : 'pending';
			if ( 'cancelled' === $status ) {
				continue;
			}
			++$total;
			if ( 'pending' === $status ) {
				++$pending;
			} elseif ( 'processing' === $status ) {
				++$processing;
			} elseif ( 'thumbs_pending' === $status ) {
				++$thumbs_pending;
			} elseif ( 'error' === $status ) {
				++$errors;
			} else {
				++$done;
			}
		}

		return array(
			'total'          => $total,
			'pending'        => $pending,
			'processing'     => $processing,
			'thumbs_pending' => $thumbs_pending,
			'done'           => $done,
			'errors'         => $errors,
			'running'        => ( $pending + $processing + $thumbs_pending ) > 0,
			'updated'        => isset( $queue['updated'] ) ? (int) $queue['updated'] : 0,
		);
	}

	/**
	 * Process next batch of pending jobs.
	 *
	 * @return void
	 */
	public static function process_batch() {
		if ( ! self::acquire_lock() ) {
			$queue = self::get_queue();
			if ( self::count_active( $queue['jobs'] ) > 0 ) {
				self::schedule( true );
			}
			return;
		}

		try {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 300 );

			$claimed_jobs = self::claim_next_batch();
			if ( empty( $claimed_jobs ) ) {
				$queue = self::get_queue();
				if ( self::count_active( $queue['jobs'] ) > 0 ) {
					self::schedule( true );
				} else {
					self::prune_finished_jobs( $queue );
					update_option( self::OPTION_KEY, $queue, false );
					self::unschedule();
				}
				return;
			}

			foreach ( $claimed_jobs as $claimed ) {
				self::refresh_lock();

				$job_id = isset( $claimed['id'] ) ? (string) $claimed['id'] : '';
				$phase  = isset( $claimed['phase'] ) ? (string) $claimed['phase'] : 'convert';
				$job    = self::find_job( $job_id );

				if ( ! $job || 'processing' !== $job['status'] ) {
					continue;
				}

				if ( 'thumbs' === $phase ) {
					self::process_job_thumbnails( $job_id, $job );
					continue;
				}

				self::process_job_convert( $job_id, $job );
			}

			$queue = self::get_queue();
			if ( self::count_active( $queue['jobs'] ) > 0 ) {
				self::schedule();
			} else {
				self::prune_finished_jobs( $queue );
				update_option( self::OPTION_KEY, $queue, false );
				self::unschedule();
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Run convert + metadata for one queue job.
	 *
	 * @param string               $job_id Job ID.
	 * @param array<string, mixed> $job    Job payload.
	 * @return void
	 */
	private static function process_job_convert( $job_id, $job ) {
		$attachment_id = absint( $job['attachment_id'] );
		$format        = sanitize_key( $job['format'] ?? 'webp' );
		$quality       = tsoimma_clamp_image_quality( $job['quality'] ?? 82 );
		$replace       = ! empty( $job['replace'] );

		$lock_token = self::acquire_attachment_lock( $attachment_id );
		if ( '' === $lock_token ) {
			// AJAX (or another worker) owns this attachment — defer.
			self::update_job(
				$job_id,
				array(
					'status'  => 'pending',
					'started' => 0,
					'phase'   => 'convert',
				)
			);
			return;
		}

		$keep_lock_for_thumbs = false;
		try {
			self::refresh_attachment_lock( $attachment_id, $lock_token );

			// Stuck reclaim: convert already applied (target ext + backup) → skip to thumbs.
			if ( self::job_convert_already_applied( $attachment_id, $format ) ) {
				$keep_lock_for_thumbs = true;
				// Do not re-log History — pipeline already logged before the worker died.
				self::update_job(
					$job_id,
					array(
						'status'  => 'thumbs_pending',
						'error'   => '',
						'started' => 0,
						'phase'   => 'thumbs',
						'format'  => $format,
					)
				);
				return;
			}

			$result = TSOIMMA_Optimizer::run_optimize_pipeline(
				$attachment_id,
				$format,
				$quality,
				$replace,
				true
			);

			if ( is_wp_error( $result ) ) {
				self::update_job(
					$job_id,
					array(
						'status'  => 'error',
						'error'   => $result->get_error_message(),
						'started' => 0,
						'phase'   => 'convert',
					)
				);
				return;
			}

			if ( ! empty( $result['format'] ) ) {
				$format = sanitize_key( (string) $result['format'] );
			}

			if ( ! empty( $result['thumbnails_pending'] ) ) {
				$keep_lock_for_thumbs = true;
				self::update_job(
					$job_id,
					array(
						'status'  => 'thumbs_pending',
						'error'   => '',
						'started' => 0,
						'phase'   => 'thumbs',
						'format'  => $format,
					)
				);
				return;
			}

			self::update_job(
				$job_id,
				array(
					'status'  => 'done',
					'error'   => '',
					'started' => 0,
					'phase'   => 'convert',
					'format'  => $format,
				)
			);
		} finally {
			if ( ! $keep_lock_for_thumbs ) {
				self::release_attachment_lock( $attachment_id, $lock_token );
			}
		}
	}

	/**
	 * Whether convert already produced the target file (used after stuck reclaim).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Requested format.
	 * @return bool
	 */
	private static function job_convert_already_applied( $attachment_id, $format ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		$backup = TSOIMMA_Optimizer::get_backup_status( $attachment_id, false );
		if ( empty( $backup['has_backup'] ) ) {
			return false;
		}

		$current_ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		$format      = sanitize_key( $format );
		if ( 'jpeg' === $format ) {
			$format = 'jpg';
		}
		if ( 'original' === $format ) {
			return false;
		}

		return TSOIMMA_Optimizer::extensions_match( $current_ext, $format );
	}

	/**
	 * Run thumbnail regeneration for one queue job.
	 *
	 * @param string               $job_id Job ID.
	 * @param array<string, mixed> $job    Job payload.
	 * @return void
	 */
	private static function process_job_thumbnails( $job_id, $job ) {
		$attachment_id = absint( $job['attachment_id'] );
		$format        = sanitize_key( $job['format'] ?? 'webp' );
		$quality       = tsoimma_clamp_image_quality( $job['quality'] ?? 82 );

		// Convert phase may already hold the lock; otherwise take it.
		$lock_token = self::get_attachment_lock_token( $attachment_id );
		if ( '' === $lock_token ) {
			$lock_token = self::acquire_attachment_lock( $attachment_id );
		}
		if ( '' === $lock_token ) {
			self::update_job(
				$job_id,
				array(
					'status'  => 'thumbs_pending',
					'started' => 0,
					'phase'   => 'thumbs',
				)
			);
			return;
		}

		try {
			self::refresh_attachment_lock( $attachment_id, $lock_token );
			TSOIMMA_Optimizer::run_optimize_thumbnails_phase( $attachment_id, $format, $quality );
			TSOIMMA_Cache_Helper::purge_after_change( $attachment_id );
			self::update_job(
				$job_id,
				array(
					'status'  => 'done',
					'error'   => '',
					'started' => 0,
					'phase'   => 'thumbs',
				)
			);
        } catch ( \Throwable $ex ) {
            // Convert already succeeded — still record history; thumbs can be retried.
            TSOIMMA_History::flush_pending( $attachment_id );
            self::update_job(
				$job_id,
				array(
					'status'  => 'error',
					'error'   => 'Thumbnails: ' . $ex->getMessage(),
					'started' => 0,
					'phase'   => 'thumbs',
				)
			);
		} finally {
			self::release_attachment_lock( $attachment_id, $lock_token );
		}
	}

	/**
	 * Clear finished and errored jobs.
	 *
	 * @return void
	 */
	public static function clear_completed() {
		$queue = self::get_queue();
		self::prune_finished_jobs( $queue );
		update_option( self::OPTION_KEY, $queue, false );
		if ( self::count_active( $queue['jobs'] ) <= 0 ) {
			self::unschedule();
		}
	}

	/**
	 * Cancel all pending jobs (does not interrupt in-flight processing).
	 *
	 * @return void
	 */
	public static function cancel_pending() {
		$queue = self::get_queue();
		$jobs  = isset( $queue['jobs'] ) && is_array( $queue['jobs'] ) ? $queue['jobs'] : array();
		foreach ( $jobs as $index => $job ) {
			$status = isset( $job['status'] ) ? (string) $job['status'] : '';
			if ( 'pending' !== $status && 'thumbs_pending' !== $status ) {
				continue;
			}
			// Free attachment locks held for deferred thumbs so other work can proceed.
			if ( 'thumbs_pending' === $status && ! empty( $job['attachment_id'] ) ) {
				$aid   = absint( $job['attachment_id'] );
				$token = self::get_attachment_lock_token( $aid );
				if ( '' !== $token ) {
					self::release_attachment_lock( $aid, $token );
				}
			}
			$jobs[ $index ]['status'] = 'cancelled';
		}
		$queue['jobs']    = $jobs;
		$queue['updated'] = time();
		self::prune_finished_jobs( $queue );
		update_option( self::OPTION_KEY, $queue, false );

		if ( self::count_active( $queue['jobs'] ) <= 0 ) {
			self::unschedule();
		} else {
			self::schedule();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_queue() {
		$queue = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		if ( empty( $queue['jobs'] ) || ! is_array( $queue['jobs'] ) ) {
			$queue['jobs'] = array();
		}
		return $queue;
	}

	/**
	 * @param array<string, mixed> $queue Queue by reference.
	 * @return void
	 */
	private static function prune_finished_jobs( &$queue ) {
		$jobs = isset( $queue['jobs'] ) && is_array( $queue['jobs'] ) ? $queue['jobs'] : array();
		$jobs = array_values(
			array_filter(
				$jobs,
				function ( $job ) {
					$status = isset( $job['status'] ) ? (string) $job['status'] : '';
					return self::is_active_status( $status );
				}
			)
		);
		$queue['jobs']    = $jobs;
		$queue['updated'] = time();
	}

	/**
	 * @param string $status Job status.
	 * @return bool
	 */
	private static function is_active_status( $status ) {
		return in_array( $status, array( 'pending', 'processing', 'thumbs_pending' ), true );
	}

	/**
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|null
	 */
	private static function find_job( $job_id ) {
		$queue = self::get_queue();
		foreach ( $queue['jobs'] as $job ) {
			if ( isset( $job['id'] ) && (string) $job['id'] === (string) $job_id ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * @param string               $job_id  Job ID.
	 * @param array<string, mixed> $updates Fields to merge.
	 * @return void
	 */
	private static function update_job( $job_id, $updates ) {
		$queue = self::get_queue();
		foreach ( $queue['jobs'] as $index => $job ) {
			if ( ! isset( $job['id'] ) || (string) $job['id'] !== (string) $job_id ) {
				continue;
			}
			$queue['jobs'][ $index ] = array_merge( $job, $updates );
			break;
		}
		$queue['updated'] = time();
		update_option( self::OPTION_KEY, $queue, false );
	}

	/**
	 * @param array<int, array<string, mixed>> $jobs Jobs list.
	 * @return int
	 */
	private static function count_active( $jobs ) {
		$count = 0;
		foreach ( (array) $jobs as $job ) {
			if ( ! isset( $job['status'] ) ) {
				continue;
			}
			if ( self::is_active_status( (string) $job['status'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Reclaim stuck jobs and mark the next batch as processing.
	 *
	 * @return array<int, array<string, string>> Claimed jobs with id + phase.
	 */
	private static function claim_next_batch() {
		$now           = time();
		$queue         = self::get_queue();
		$convert_ids   = array();
		$thumb_ids     = array();

		foreach ( $queue['jobs'] as $index => $job ) {
			if ( ! isset( $job['status'], $job['id'] ) ) {
				continue;
			}
			if ( 'processing' === $job['status'] ) {
				$started = isset( $job['started'] ) ? (int) $job['started'] : 0;
				if ( $started <= 0 || ( $now - $started ) >= self::STUCK_SECONDS ) {
					$reset_status = ( isset( $job['phase'] ) && 'thumbs' === $job['phase'] ) ? 'thumbs_pending' : 'pending';
					$queue['jobs'][ $index ]['status']  = $reset_status;
					$queue['jobs'][ $index ]['started'] = 0;
					$queue['jobs'][ $index ]['error']   = '';
				}
			}
		}
		$queue['updated'] = $now;
		update_option( self::OPTION_KEY, $queue, false );

		foreach ( $queue['jobs'] as $job ) {
			if ( ! isset( $job['status'], $job['id'] ) ) {
				continue;
			}
			if ( 'thumbs_pending' === $job['status'] && count( $thumb_ids ) < self::THUMBS_BATCH ) {
				$thumb_ids[] = (string) $job['id'];
			}
		}

		foreach ( $queue['jobs'] as $job ) {
			if ( count( $convert_ids ) + count( $thumb_ids ) >= self::BATCH_SIZE ) {
				break;
			}
			if ( ! isset( $job['status'], $job['id'] ) || 'pending' !== $job['status'] ) {
				continue;
			}
			$convert_ids[] = (string) $job['id'];
		}

		if ( empty( $convert_ids ) && empty( $thumb_ids ) ) {
			$queue['updated'] = $now;
			update_option( self::OPTION_KEY, $queue, false );
			return array();
		}

		$fresh        = self::get_queue();
		$claimed_jobs = array();

		foreach ( $fresh['jobs'] as $index => $job ) {
			if ( ! isset( $job['id'], $job['status'] ) || 'processing' !== $job['status'] ) {
				continue;
			}
			$started = isset( $job['started'] ) ? (int) $job['started'] : 0;
			if ( $started <= 0 || ( $now - $started ) >= self::STUCK_SECONDS ) {
				$reset_status = ( isset( $job['phase'] ) && 'thumbs' === $job['phase'] ) ? 'thumbs_pending' : 'pending';
				$fresh['jobs'][ $index ]['status']  = $reset_status;
				$fresh['jobs'][ $index ]['started'] = 0;
			}
		}

		foreach ( $fresh['jobs'] as $index => $job ) {
			if ( ! isset( $job['id'], $job['status'] ) ) {
				continue;
			}
			$job_id = (string) $job['id'];

			if ( 'thumbs_pending' === $job['status'] && in_array( $job_id, $thumb_ids, true ) ) {
				$fresh['jobs'][ $index ]['status']  = 'processing';
				$fresh['jobs'][ $index ]['started'] = $now;
				$fresh['jobs'][ $index ]['phase']   = 'thumbs';
				$claimed_jobs[]                     = array(
					'id'    => $job_id,
					'phase' => 'thumbs',
				);
				continue;
			}

			if ( 'pending' === $job['status'] && in_array( $job_id, $convert_ids, true ) ) {
				$fresh['jobs'][ $index ]['status']  = 'processing';
				$fresh['jobs'][ $index ]['started'] = $now;
				$fresh['jobs'][ $index ]['phase']   = 'convert';
				$claimed_jobs[]                     = array(
					'id'    => $job_id,
					'phase' => 'convert',
				);
			}
		}

		$fresh['updated'] = $now;
		update_option( self::OPTION_KEY, $fresh, false );
		return $claimed_jobs;
	}

	/**
	 * @return bool
	 */
	private static function acquire_lock() {
		$token = self::try_acquire_option_lock( self::LOCK_OPTION, self::LOCK_TTL );
		if ( '' === $token ) {
			return false;
		}
		self::$lock_token = $token;
		return true;
	}

	/**
	 * Extend lock TTL while a batch is still running.
	 *
	 * @return void
	 */
	private static function refresh_lock() {
		if ( '' === self::$lock_token ) {
			return;
		}

		$lock = get_option( self::LOCK_OPTION );
		if ( ! is_array( $lock ) || ! isset( $lock['token'] ) || $lock['token'] !== self::$lock_token ) {
			return;
		}

		$lock['until'] = time() + self::LOCK_TTL;
		update_option( self::LOCK_OPTION, $lock, false );
	}

	/**
	 * @return void
	 */
	private static function release_lock() {
		$token = self::$lock_token;
		self::$lock_token = '';
		if ( '' === $token ) {
			return;
		}
		if ( ! self::option_lock_owned( self::LOCK_OPTION, $token ) ) {
			return;
		}
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Short-lived mutex for enqueue read-modify-write.
	 *
	 * @return bool
	 */
	private static function acquire_enqueue_lock() {
		return '' !== self::try_acquire_option_lock( self::ENQUEUE_LOCK_OPTION, self::ENQUEUE_LOCK_TTL );
	}

	/**
	 * @return void
	 */
	private static function release_enqueue_lock() {
		delete_option( self::ENQUEUE_LOCK_OPTION );
	}

	/**
	 * Atomic-ish option lock: add_option, or delete+add when expired.
	 *
	 * @param string $option Option name.
	 * @param int    $ttl    Seconds.
	 * @return string Token on success, empty string on failure.
	 */
	private static function try_acquire_option_lock( $option, $ttl ) {
		$now     = time();
		$token   = wp_generate_password( 12, false, false );
		$payload = array(
			'token' => $token,
			'until' => $now + absint( $ttl ),
		);

		if ( add_option( $option, $payload, '', 'no' ) ) {
			return self::option_lock_owned( $option, $token ) ? $token : '';
		}

		$lock = get_option( $option );
		if ( is_array( $lock ) && isset( $lock['until'] ) && (int) $lock['until'] > $now ) {
			return '';
		}

		// Expired/corrupt: claim with update_option, then verify we still own the token
		// (two reclaimers → last writer wins; the other fails verification).
		update_option( $option, $payload, false );
		return self::option_lock_owned( $option, $token ) ? $token : '';
	}

	/**
	 * @param string $option Option name.
	 * @param string $token  Expected token.
	 * @return bool
	 */
	private static function option_lock_owned( $option, $token ) {
		$check = get_option( $option );
		return is_array( $check ) && isset( $check['token'] ) && (string) $check['token'] === (string) $token;
	}

	/**
	 * Option key for a per-attachment optimize lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function attachment_lock_option( $attachment_id ) {
		return self::ATTACHMENT_LOCK_PREFIX . absint( $attachment_id );
	}

	/**
	 * Whether an attachment has an active optimize lock (AJAX or queue).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function has_attachment_lock( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}
		$lock = get_option( self::attachment_lock_option( $attachment_id ) );
		return is_array( $lock ) && isset( $lock['until'] ) && (int) $lock['until'] > time();
	}

	/**
	 * Whether the attachment is in an active queue job or has an optimize lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_attachment_busy( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}
		if ( self::has_attachment_lock( $attachment_id ) ) {
			return true;
		}
		$queue = self::get_queue();
		foreach ( $queue['jobs'] as $job ) {
			if ( ! isset( $job['status'], $job['attachment_id'] ) ) {
				continue;
			}
			if ( absint( $job['attachment_id'] ) !== $attachment_id ) {
				continue;
			}
			if ( self::is_active_status( (string) $job['status'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extend per-attachment lock TTL while convert/thumbs work continues.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $token         Lock ownership token.
	 * @return void
	 */
	public static function refresh_attachment_lock( $attachment_id, $token ) {
		$attachment_id = absint( $attachment_id );
		$token         = (string) $token;
		if ( $attachment_id <= 0 || '' === $token ) {
			return;
		}
		$option = self::attachment_lock_option( $attachment_id );
		if ( ! self::option_lock_owned( $option, $token ) ) {
			return;
		}
		$lock = get_option( $option );
		if ( ! is_array( $lock ) ) {
			return;
		}
		$lock['until'] = time() + self::LOCK_TTL;
		update_option( $option, $lock, false );
	}

	/**
	 * Acquire a short-lived per-attachment optimize lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Lock token on success, empty string on failure.
	 */
	public static function acquire_attachment_lock( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return '';
		}
		return self::try_acquire_option_lock( self::attachment_lock_option( $attachment_id ), self::LOCK_TTL );
	}

	/**
	 * Current attachment lock token when the lock is still active.
	 *
	 * Used to hand off ownership from convert → thumbs (AJAX/cron/queue).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Token or empty string.
	 */
	public static function get_attachment_lock_token( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return '';
		}
		$lock = get_option( self::attachment_lock_option( $attachment_id ) );
		if ( ! is_array( $lock ) || empty( $lock['token'] ) ) {
			return '';
		}
		if ( ! isset( $lock['until'] ) || (int) $lock['until'] <= time() ) {
			return '';
		}
		return (string) $lock['token'];
	}

	/**
	 * Release per-attachment optimize lock only when $token still owns it.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $token         Token returned by acquire_attachment_lock() / get_attachment_lock_token().
	 * @return void
	 */
	public static function release_attachment_lock( $attachment_id, $token = '' ) {
		$attachment_id = absint( $attachment_id );
		$token         = (string) $token;
		if ( $attachment_id <= 0 || '' === $token ) {
			return;
		}
		$option = self::attachment_lock_option( $attachment_id );
		if ( ! self::option_lock_owned( $option, $token ) ) {
			return;
		}
		delete_option( $option );
	}

	/**
	 * @param bool $force Force a new schedule even if one exists.
	 * @return void
	 */
	private static function schedule( $force = false ) {
		if ( $force ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + ( $force ? 30 : 1 ), self::CRON_HOOK );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			wp_remote_post(
				site_url( 'wp-cron.php' ),
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, not defined by this plugin
				)
			);
		}
	}

	/**
	 * @return void
	 */
	private static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
