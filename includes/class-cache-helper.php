<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central cache purge helpers after URL or media changes.
 */
class TSOIMMA_Cache_Helper {

	/**
	 * Purge page/post related caches.
	 *
	 * @param int $post_id Optional post ID.
	 * @return void
	 */
	public static function purge_after_change( $post_id = 0 ) {
		$post_id = absint( $post_id );

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		if ( $post_id > 0 ) {
			clean_post_cache( $post_id );
			clean_attachment_cache( $post_id );
			wp_cache_delete( $post_id, 'posts' );
			do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook
		}

		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache();
		}
		if ( function_exists( 'breeze_clear_all_cache' ) ) {
			breeze_clear_all_cache();
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}
	}
}
