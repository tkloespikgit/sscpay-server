/* global window */
( function () {
	'use strict';

	var wc = window.wc || {};
	var registry = wc.wcBlocksRegistry;
	var wcSettings = wc.wcSettings;

	if ( ! registry || ! registry.registerPaymentMethod || ! wcSettings ) {
		return;
	}

	var element = window.wp && window.wp.element;
	var htmlEntities = window.wp && window.wp.htmlEntities;

	if ( ! element ) {
		return;
	}

	var createElement = element.createElement;
	var Fragment = element.Fragment;
	var decode = htmlEntities && htmlEntities.decodeEntities ? htmlEntities.decodeEntities : function ( value ) {
		return value;
	};

	var METHOD = 'sscpay_creditcard';
	var settings = wcSettings.getSetting( METHOD + '_data', {} ) || {};

	var title = decode( settings.title || 'SSCPay' );
	var description = settings.description || '';

	/**
	 * 结账页支付方式标签：Logo + 名称。
	 */
	function Label() {
		var children = [];

		if ( settings.logoUrl ) {
			children.push(
				createElement( 'img', {
					key: 'logo',
					src: settings.logoUrl,
					alt: title,
					style: {
						maxHeight: ( settings.logoHeight || 24 ) + 'px',
						width: 'auto',
						marginRight: '8px',
						verticalAlign: 'middle',
					},
				} )
			);
		}

		children.push(
			createElement( 'span', { key: 'title', className: 'wc-block-components-payment-method-label' }, title )
		);

		return createElement(
			'span',
			{
				style: { display: 'inline-flex', alignItems: 'center', gap: '0' },
			},
			children
		);
	}

	/**
	 * 展开后的说明区域。description 允许简单 HTML，所以直接注入。
	 */
	function Content() {
		if ( ! description ) {
			return createElement( Fragment, null );
		}

		return createElement( 'div', {
			className: 'sscpay-blocks-description',
			dangerouslySetInnerHTML: { __html: description },
		} );
	}

	registry.registerPaymentMethod( {
		name: METHOD,
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
