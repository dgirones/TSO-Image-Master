<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background job queue (WP-Cron) for long-running bulk tasks.
 */
class TSOIMMA_Queue {

	const OPTION_KEY = 'tsoimma_job_queue';
	const CRON_HOOK  = 'tsoimma_process_queue';
	const BATCH_SIZE = 5;

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

		foreach ( array_map( 'absint', (array) $attachment_ids ) as $attachment_id ) {
			if ( $attachment_id <= 0 ) {
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
			);
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

		$pending = 0;
		$done    = 0;
		$errors  = 0;
		foreach ( $jobs as $job ) {
			$status = isset( $job['status'] ) ? (string) $job['status'] : 'pending';
			if ( 'pending' === $status ) {
				++$pending;
			} elseif ( 'error' === $status ) {
				++$errors;
			} else {
				++$done;
			}
		}

		return array(
			'total'   => count( $jobs ),
			'pending' => $pending,
			'done'    => $done,
			'errors'  => $errors,
			'running' => $pending > 0,
			'updated' => isset( $queue['updated'] ) ? (int) $queue['updated'] : 0,
		);
	}

	/**
	 * Process next batch of pending jobs.
	 *
	 * @return void
	 */
	public static function process_batch() {
		$queue = self::get_queue();
		$jobs  = isset( $queue['jobs'] ) && is_array( $queue['jobs'] ) ? $queue['jobs'] : array();
		if ( empty( $jobs ) ) {
			self::unschedule();
			return;
		}

		$processed = 0;
		foreach ( $jobs as $index => $job ) {
			if ( $processed >= self::BATCH_SIZE ) {
				break;
			}
			if ( ! isset( $job['status'] ) || 'pending' !== $job['status'] ) {
				continue;
			}

			$result = TSOIMMA_Optimizer::run_optimize_pipeline(
				absint( $job['attachment_id'] ),
				sanitize_key( $job['format'] ?? 'webp' ),
				absint( $job['quality'] ?? 82 ),
				! empty( $job['replace'] )
			);

			if ( is_wp_error( $result ) ) {
				$jobs[ $index ]['status'] = 'error';
				$jobs[ $index ]['error']  = $result->get_error_message();
			} else {
				$jobs[ $index ]['status'] = 'done';
			}
			++$processed;
		}

		$queue['jobs']    = $jobs;
		$queue['updated'] = time();
		update_option( self::OPTION_KEY, $queue, false );

		if ( self::count_pending( $jobs ) > 0 ) {
			self::schedule();
		} else {
			self::unschedule();
		}
	}

	/**
	 * Clear finished and errored jobs.
	 *
	 * @return void
	 */
	public static function clear_completed() {
		$queue = self::get_queue();
		$jobs  = isset( $queue['jobs'] ) && is_array( $queue['jobs'] ) ? $queue['jobs'] : array();
		$jobs  = array_values(
			array_filter(
				$jobs,
				function ( $job ) {
					return isset( $job['status'] ) && 'pending' === $job['status'];
				}
			)
		);
		$queue['jobs']    = $jobs;
		$queue['updated'] = time();
		update_option( self::OPTION_KEY, $queue, false );
		if ( empty( $jobs ) ) {
			self::unschedule();
		}
	}

	/**
	 * Cancel all pending jobs.
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
		update_option( self::OPTION_KEY, $queue, false );
		self::unschedule();
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
	 * @param array<int, array<string, mixed>> $jobs Jobs list.
	 * @return int
	 */
	private static function count_pending( $jobs ) {
		$count = 0;
		foreach ( (array) $jobs as $job ) {
			if ( isset( $job['status'] ) && 'pending' === $job['status'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @return void
	 */
	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::CRON_HOOK );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			wp_remote_post(
				site_url( 'wp-cron.php' ),
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
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
