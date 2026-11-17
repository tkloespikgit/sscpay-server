/* global jQuery, wp, sscpayCreditcardAdmin */
( function ( $ ) {
	'use strict';

	$( function () {
		var frame = null;

		$( document ).on( 'click', '.sscpay-image-upload__select', function ( event ) {
			event.preventDefault();

			var $wrap = $( this ).closest( '.sscpay-image-upload' );
			var $input = $wrap.find( '.sscpay-image-upload__input' );
			var $preview = $wrap.find( '.sscpay-image-upload__preview' );
			var $remove = $wrap.find( '.sscpay-image-upload__remove' );

			if ( ! wp || ! wp.media ) {
				window.alert( 'WordPress 媒体库未加载，请刷新页面重试。' );
				return;
			}

			frame = wp.media( {
				title: ( window.sscpayCreditcardAdmin && sscpayCreditcardAdmin.frameTitle ) || '选择图片',
				button: {
					text: ( window.sscpayCreditcardAdmin && sscpayCreditcardAdmin.frameButton ) || '使用这张图片',
				},
				library: { type: 'image' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.url;

				// 有中等尺寸就用中等尺寸，避免把原图直接塞进结账页。
				if ( attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url ) {
					url = attachment.sizes.medium.url;
				}

				$input.val( url );
				$preview.html( $( '<img>' ).attr( 'src', url ) );
				$remove.show();
			} );

			frame.open();
		} );

		$( document ).on( 'click', '.sscpay-image-upload__remove', function ( event ) {
			event.preventDefault();

			var $wrap = $( this ).closest( '.sscpay-image-upload' );

			$wrap.find( '.sscpay-image-upload__input' ).val( '' );
			$wrap.find( '.sscpay-image-upload__preview' ).empty();
			$( this ).hide();
		} );

		// 手动粘贴地址时同步预览。
		$( document ).on( 'change', '.sscpay-image-upload__input', function () {
			var $wrap = $( this ).closest( '.sscpay-image-upload' );
			var url = $.trim( $( this ).val() );
			var $preview = $wrap.find( '.sscpay-image-upload__preview' );

			if ( url ) {
				$preview.html( $( '<img>' ).attr( 'src', url ) );
				$wrap.find( '.sscpay-image-upload__remove' ).show();
			} else {
				$preview.empty();
				$wrap.find( '.sscpay-image-upload__remove' ).hide();
			}
		} );
	} );
} )( jQuery );
