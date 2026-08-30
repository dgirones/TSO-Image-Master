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
		if ( ! self::acquire_enqueue_lock() ) {
			usleep( 100000 );
			if ( ! self::acquire_enqueue_lock() ) {
				return self::get_status();
			}
		}

		try {
			$format  = sanitize_key( $format );
			$quality = min( 100, max( 50, absint( $quality ) ) );
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

			foreach ( array_map( 'absint', (array) $attachment_ids ) as $attachment_id ) {
				if ( $attachment_id <= 0 || isset( $busy[ $attachment_id ] ) ) {
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
			}

			$queue['updated'] = time();
			update_option( self::OPTION_KEY, $queue, false );
			self::schedule();

			return self::get_status();
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
		$result = TSOIMMA_Optimizer::run_optimize_pipeline(
			absint( $job['attachment_id'] ),
			sanitize_key( $job['format'] ?? 'webp' ),
			absint( $job['quality'] ?? 82 ),
			! empty( $job['replace'] ),
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

		if ( ! empty( $result['thumbnails_pending'] ) ) {
			self::update_job(
				$job_id,
				array(
					'status'  => 'thumbs_pending',
					'error'   => '',
					'started' => 0,
					'phase'   => 'thumbs',
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
			)
		);
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
		$quality       = absint( $job['quality'] ?? 82 );

		try {
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
			self::update_job(
				$job_id,
				array(
					'status'  => 'error',
					'error'   => 'Thumbnails: ' . $ex->getMessage(),
					'started' => 0,
					'phase'   => 'thumbs',
				)
			);
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
			if ( isset( $job['status'] ) && 'pending' === $job['status'] ) {
				$jobs[ $index ]['status'] = 'cancelled';
			}
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
		$now   = time();
		$token = wp_generate_password( 12, false, false );
		$payload = array(
			'token' => $token,
			'until' => $now + self::LOCK_TTL,
		);

		if ( add_option( self::LOCK_OPTION, $payload, '', 'no' ) ) {
			self::$lock_token = $token;
			return true;
		}

		$lock = get_option( self::LOCK_OPTION );
		if ( is_array( $lock ) && isset( $lock['until'] ) && (int) $lock['until'] > $now ) {
			return false;
		}

		update_option( self::LOCK_OPTION, $payload, false );
		$check = get_option( self::LOCK_OPTION );
		if ( is_array( $check ) && isset( $check['token'] ) && $check['token'] === $token ) {
			self::$lock_token = $token;
			return true;
		}

		return false;
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
		self::$lock_token = '';
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Short-lived mutex for enqueue read-modify-write.
	 *
	 * @return bool
	 */
	private static function acquire_enqueue_lock() {
		$now     = time();
		$token   = wp_generate_password( 12, false, false );
		$payload = array(
			'token' => $token,
			'until' => $now + self::ENQUEUE_LOCK_TTL,
		);

		if ( add_option( self::ENQUEUE_LOCK_OPTION, $payload, '', 'no' ) ) {
			return true;
		}

		$lock = get_option( self::ENQUEUE_LOCK_OPTION );
		if ( is_array( $lock ) && isset( $lock['until'] ) && (int) $lock['until'] > $now ) {
			return false;
		}

		update_option( self::ENQUEUE_LOCK_OPTION, $payload, false );
		$check = get_option( self::ENQUEUE_LOCK_OPTION );
		return is_array( $check ) && isset( $check['token'] ) && $check['token'] === $token;
	}

	/**
	 * @return void
	 */
	private static function release_enqueue_lock() {
		delete_option( self::ENQUEUE_LOCK_OPTION );
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
