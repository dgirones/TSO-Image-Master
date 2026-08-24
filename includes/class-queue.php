<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background job queue (WP-Cron) for long-running bulk tasks.
 */
class TSOIMMA_Queue {

	const OPTION_KEY   = 'tsoimma_job_queue';
	const CRON_HOOK    = 'tsoimma_process_queue';
	const LOCK_OPTION  = 'tsoimma_queue_lock';
	const BATCH_SIZE   = 5;
	const LOCK_TTL     = 180;
	const STUCK_SECONDS = 300;

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
		$format  = sanitize_key( $format );
		$quality = min( 100, max( 50, absint( $quality ) ) );
		$queue   = self::get_queue();
		self::prune_finished_jobs( $queue );

		$busy = array();
		foreach ( $queue['jobs'] as $job ) {
			if ( ! isset( $job['status'], $job['attachment_id'] ) ) {
				continue;
			}
			if ( in_array( $job['status'], array( 'pending', 'processing' ), true ) ) {
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
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_status() {
		$queue = self::get_queue();
		$jobs  = isset( $queue['jobs'] ) && is_array( $queue['jobs'] ) ? $queue['jobs'] : array();

		$pending    = 0;
		$processing = 0;
		$done       = 0;
		$errors     = 0;
		$total      = 0;
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
			} elseif ( 'error' === $status ) {
				++$errors;
			} else {
				++$done;
			}
		}

		return array(
			'total'      => $total,
			'pending'    => $pending + $processing,
			'processing' => $processing,
			'done'       => $done,
			'errors'     => $errors,
			'running'    => ( $pending + $processing ) > 0,
			'updated'    => isset( $queue['updated'] ) ? (int) $queue['updated'] : 0,
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
			$claimed_ids = self::claim_next_batch();
			if ( empty( $claimed_ids ) ) {
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

			foreach ( $claimed_ids as $job_id ) {
				$job = self::find_job( $job_id );
				if ( ! $job || 'processing' !== $job['status'] ) {
					continue;
				}

				$result = TSOIMMA_Optimizer::run_optimize_pipeline(
					absint( $job['attachment_id'] ),
					sanitize_key( $job['format'] ?? 'webp' ),
					absint( $job['quality'] ?? 82 ),
					! empty( $job['replace'] )
				);

				if ( is_wp_error( $result ) ) {
					self::update_job(
						$job_id,
						array(
							'status'  => 'error',
							'error'   => $result->get_error_message(),
							'started' => 0,
						)
					);
				} else {
					self::update_job(
						$job_id,
						array(
							'status'  => 'done',
							'error'   => '',
							'started' => 0,
						)
					);
				}
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
					return in_array( $status, array( 'pending', 'processing' ), true );
				}
			)
		);
		$queue['jobs']    = $jobs;
		$queue['updated'] = time();
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
			if ( in_array( $job['status'], array( 'pending', 'processing' ), true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Reclaim stuck jobs and mark the next batch as processing.
	 *
	 * @return string[] Claimed job IDs.
	 */
	private static function claim_next_batch() {
		$now          = time();
		$queue        = self::get_queue();
		$candidate_ids = array();

		foreach ( $queue['jobs'] as $index => $job ) {
			if ( ! isset( $job['status'], $job['id'] ) ) {
				continue;
			}
			if ( 'processing' === $job['status'] ) {
				$started = isset( $job['started'] ) ? (int) $job['started'] : 0;
				if ( $started <= 0 || ( $now - $started ) >= self::STUCK_SECONDS ) {
					$queue['jobs'][ $index ]['status']  = 'pending';
					$queue['jobs'][ $index ]['started'] = 0;
					$queue['jobs'][ $index ]['error']   = '';
				}
			}
		}

		foreach ( $queue['jobs'] as $job ) {
			if ( count( $candidate_ids ) >= self::BATCH_SIZE ) {
				break;
			}
			if ( isset( $job['status'], $job['id'] ) && 'pending' === $job['status'] ) {
				$candidate_ids[] = (string) $job['id'];
			}
		}

		if ( empty( $candidate_ids ) ) {
			$queue['updated'] = $now;
			update_option( self::OPTION_KEY, $queue, false );
			return array();
		}

		// Re-read so concurrent enqueue() is not wiped, then claim by job id.
		$fresh       = self::get_queue();
		$claimed_ids = array();
		foreach ( $fresh['jobs'] as $index => $job ) {
			if ( ! isset( $job['id'], $job['status'] ) ) {
				continue;
			}
			$job_id = (string) $job['id'];
			if ( 'processing' === $job['status'] ) {
				$started = isset( $job['started'] ) ? (int) $job['started'] : 0;
				if ( $started <= 0 || ( $now - $started ) >= self::STUCK_SECONDS ) {
					$fresh['jobs'][ $index ]['status']  = 'pending';
					$fresh['jobs'][ $index ]['started'] = 0;
				}
			}
		}
		foreach ( $fresh['jobs'] as $index => $job ) {
			if ( count( $claimed_ids ) >= self::BATCH_SIZE ) {
				break;
			}
			if ( ! isset( $job['id'], $job['status'] ) || 'pending' !== $job['status'] ) {
				continue;
			}
			$job_id = (string) $job['id'];
			if ( ! in_array( $job_id, $candidate_ids, true ) ) {
				continue;
			}
			$fresh['jobs'][ $index ]['status']  = 'processing';
			$fresh['jobs'][ $index ]['started'] = $now;
			$claimed_ids[]                      = $job_id;
		}

		$fresh['updated'] = $now;
		update_option( self::OPTION_KEY, $fresh, false );
		return $claimed_ids;
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
			return true;
		}

		$lock = get_option( self::LOCK_OPTION );
		if ( is_array( $lock ) && isset( $lock['until'] ) && (int) $lock['until'] > $now ) {
			return false;
		}

		update_option( self::LOCK_OPTION, $payload, false );
		$check = get_option( self::LOCK_OPTION );
		return is_array( $check ) && isset( $check['token'] ) && $check['token'] === $token;
	}

	/**
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
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
