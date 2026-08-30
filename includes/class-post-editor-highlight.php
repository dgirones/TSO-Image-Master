<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deep-link post editor to a specific attachment ID (visual block editor or code view).
 */
class TSOIMMA_Post_Editor_Highlight {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Build post edit URL that highlights one attachment in the block or code editor.
	 *
	 * @param int    $post_id       Post ID.
	 * @param int    $attachment_id Attachment ID to highlight.
	 * @param string $mode          visual|code (default visual).
	 * @return string
	 */
	public static function get_post_edit_highlight_url( $post_id, $attachment_id, $mode = 'visual' ) {
		$post_id       = absint( $post_id );
		$attachment_id = absint( $attachment_id );
		if ( $post_id <= 0 ) {
			return admin_url( 'edit.php' );
		}

		$args = array(
			'tsoimma_highlight' => $attachment_id,
		);

		$mode = sanitize_key( (string) $mode );
		if ( in_array( $mode, array( 'visual', 'code' ), true ) ) {
			$args['tsoimma_mode'] = $mode;
		}

		return add_query_arg(
			$args,
			admin_url( 'post.php?post=' . $post_id . '&action=edit' )
		);
	}

	/**
	 * @param string $hook Admin screen hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only deep-link arg.
		if ( empty( $_GET['tsoimma_highlight'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only deep-link arg.
		$attachment_id = absint( wp_unslash( $_GET['tsoimma_highlight'] ) );
		if ( $attachment_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only deep-link arg.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode = 'visual';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only deep-link arg.
		if ( ! empty( $_GET['tsoimma_mode'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only deep-link arg.
			$mode_raw = sanitize_key( wp_unslash( $_GET['tsoimma_mode'] ) );
			if ( in_array( $mode_raw, array( 'visual', 'code' ), true ) ) {
				$mode = $mode_raw;
			}
		}

		$js_file  = TSOIMMA_PLUGIN_DIR . 'admin/js/post-highlight.js';
		$css_file = TSOIMMA_PLUGIN_DIR . 'admin/css/post-highlight.css';
		$js_ver   = TSOIMMA_VERSION . '.' . ( file_exists( $js_file ) ? (string) filemtime( $js_file ) : '0' );
		$css_ver  = TSOIMMA_VERSION . '.' . ( file_exists( $css_file ) ? (string) filemtime( $css_file ) : '0' );
		$deps     = function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post_id )
			? array( 'wp-data', 'wp-edit-post' )
			: array();

		wp_enqueue_style(
			'tsoimma-post-highlight',
			TSOIMMA_PLUGIN_URL . 'admin/css/post-highlight.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'tsoimma-post-highlight',
			TSOIMMA_PLUGIN_URL . 'admin/js/post-highlight.js',
			$deps,
			$js_ver,
			true
		);

		wp_localize_script(
			'tsoimma-post-highlight',
			'TSOIMMAHighlight',
			array(
				'attachmentId' => $attachment_id,
				'mode'         => $mode,
				'needles'      => self::build_search_needles( $attachment_id ),
				'visualLabel'  => sprintf(
					/* translators: %d: attachment ID */
					__( 'Highlighting attachment #%d in the block editor.', 'tso-image-master' ),
					$attachment_id
				),
				'codeLabel'    => sprintf(
					/* translators: %d: attachment ID */
					__( 'Highlighting attachment #%d in code.', 'tso-image-master' ),
					$attachment_id
				),
			)
		);
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	private static function build_search_needles( $attachment_id ) {
		$id = (string) absint( $attachment_id );

		return array(
			'data-id="' . $id . '"',
			'"id":' . $id,
			'"id": ' . $id,
			',' . $id . ',',
			',' . $id . ']',
			'[' . $id . ',',
			'wp-image-' . $id,
		);
	}
}
