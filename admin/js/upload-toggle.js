/**
 * TSO Image Master — per-upload "optimize automatically" checkbox.
 *
 * Sends tsoimma_auto=1|0 with every file uploaded through plupload
 * (Media > Add New and the wp.media modal). Uploads that do not go through
 * plupload (block editor drag & drop via REST) keep the global setting.
 */
( function ( $, window ) {
	'use strict';

	var cfg     = window.tsoimmaUploadConfig || {};
	var enabled = '1' === String( cfg.enabled );

	function syncCheckboxes( $scope ) {
		( $scope || $( document ) ).find( '.tsoimma-upload-auto-cb' ).prop( 'checked', enabled );
	}

	function beforeUpload( up ) {
		if ( ! up || ! up.settings ) {
			return;
		}
		up.settings.multipart_params = up.settings.multipart_params || {};
		up.settings.multipart_params.tsoimma_auto = enabled ? '1' : '0';
	}

	// Keep every rendered checkbox (page + modal templates) in the same state.
	// Remember the choice right away (not only on the next upload).
	function savePreference() {
		if ( ! cfg.ajax_url || ! cfg.nonce ) {
			return;
		}
		$.post( cfg.ajax_url, {
			action: 'tsoimma_save_upload_auto_pref',
			nonce: cfg.nonce,
			enabled: enabled ? '1' : ''
		} );
	}

	$( document ).on( 'change', '.tsoimma-upload-auto-cb', function () {
		enabled = $( this ).is( ':checked' );
		syncCheckboxes();
		savePreference();
	} );

	// wp.media uploaders (modal, Media Library grid). Patch before any instance is created.
	if ( window.wp && window.wp.Uploader && window.wp.Uploader.prototype ) {
		var origInit = window.wp.Uploader.prototype.init;
		window.wp.Uploader.prototype.init = function () {
			if ( 'function' === typeof origInit ) {
				origInit.apply( this, arguments );
			}
			if ( this.uploader && 'function' === typeof this.uploader.bind ) {
				this.uploader.bind( 'BeforeUpload', beforeUpload );
			}
		};
	}

	// Modal inline uploader is rendered from a template: reflect the current state on render.
	if ( window.wp && window.wp.media && window.wp.media.view && window.wp.media.view.UploaderInline ) {
		var origReady = window.wp.media.view.UploaderInline.prototype.ready;
		window.wp.media.view.UploaderInline.prototype.ready = function () {
			var out = 'function' === typeof origReady ? origReady.apply( this, arguments ) : this;
			syncCheckboxes( this.$el );
			return out;
		};
	}

	// Media > Add New: plupload-handlers.js creates the global `uploader` on DOM ready.
	// Retry briefly in case our ready callback runs before it.
	function bindGlobalUploader( tries ) {
		if ( window.uploader && 'function' === typeof window.uploader.bind ) {
			window.uploader.bind( 'BeforeUpload', beforeUpload );
			return;
		}
		if ( tries > 0 ) {
			window.setTimeout( function () {
				bindGlobalUploader( tries - 1 );
			}, 100 );
		}
	}

	$( function () {
		syncCheckboxes();
		if ( document.getElementById( 'plupload-upload-ui' ) ) {
			bindGlobalUploader( 30 );
		}
	} );
}( jQuery, window ) );
