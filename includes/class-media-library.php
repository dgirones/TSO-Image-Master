<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Media Library integrations.
 */
class TSOIMMA_Media_Library {

	/**
	 * @return void
	 */
	public static function init() {
		add_filter( 'media_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload.php', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload.php', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'attachment_submitbox_misc_actions', array( __CLASS__, 'attachment_box_note' ) );
	}

	/**
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Attachment post.
	 * @return array<string, string>
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! current_user_can( 'manage_options' ) || 'attachment' !== $post->post_type ) {
			return $actions;
		}
		if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return $actions;
		}

		$url = add_query_arg(
			array(
				'page'       => 'tso-image-master',
				'tsoimma_id' => $post->ID,
			),
			admin_url( 'admin.php' )
		);

		$actions['tsoimma_optimize'] = '<a href="' . esc_url( $url ) . '#tab-optimize">' . esc_html__( 'Image Master', 'tso-image-master' ) . '</a>';
		return $actions;
	}

	/**
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( $actions ) {
		if ( current_user_can( 'manage_options' ) ) {
			$actions['tsoimma_queue_optimize'] = __( 'Queue optimize (Image Master)', 'tso-image-master' );
		}
		return $actions;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action name.
	 * @param int[]  $post_ids Post IDs.
	 * @return string
	 */
	public static function handle_bulk_actions( $redirect, $action, $post_ids ) {
		if ( 'tsoimma_queue_optimize' !== $action || ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}

		$auto = TSOIMMA_Auto_Optimizer::get_settings();
		TSOIMMA_Queue::enqueue_optimize(
			$post_ids,
			isset( $auto['format'] ) ? $auto['format'] : 'webp',
			isset( $auto['quality'] ) ? (int) $auto['quality'] : 82,
			true
		);

		return add_query_arg( 'tsoimma_queued', count( $post_ids ), $redirect );
	}

	/**
	 * @param WP_Post $post Attachment post.
	 * @return void
	 */
	public static function attachment_box_note( $post ) {
		if ( ! current_user_can( 'manage_options' ) || 'attachment' !== $post->post_type ) {
			return;
		}
		if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return;
		}

		$optimized = get_post_meta( $post->ID, '_tso_im_auto_optimized', true );
		$backup    = TSOIMMA_Optimizer::get_backup_status( $post->ID );
		if ( ! $optimized && empty( $backup['has_backup'] ) ) {
			return;
		}

		echo '<div class="misc-pub-section">';
		echo '<strong>' . esc_html__( 'TSO Image Master', 'tso-image-master' ) . '</strong><br>';
		if ( $optimized ) {
			echo esc_html__( 'Auto-optimized on upload.', 'tso-image-master' ) . '<br>';
		}
		if ( ! empty( $backup['has_backup'] ) ) {
			echo esc_html__( 'Backup available for revert.', 'tso-image-master' );
		}
		echo '</div>';
	}
}
