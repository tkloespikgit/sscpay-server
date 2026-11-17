<?php
/**
 * 商户订单协议状态：V1 / V2、父单进度及通知幂等。
 *
 * @package SSCPay_Creditcard
 */

defined( 'ABSPATH' ) || exit;

final class SSCPay_Creditcard_Order_State {
	const META_PROGRESS = '_sscpay_payment_progress';
	const META_EVENTS = '_sscpay_payment_events';

	/** @return string|null 精确的整数分字符串，不依赖浮点或 bcmath 扩展。 */
	public static function cents( $amount ) {
		if ( ! is_string( $amount ) || ! preg_match( '/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $amount, $matches ) ) {
			return null;
		}
		$value = ltrim( $matches[1] . str_pad( isset( $matches[2] ) ? $matches[2] : '', 2, '0' ), '0' );
		return '' === $value ? '0' : $value;
	}

	/** @return string */
	private static function add( $left, $right ) {
		$result = '';
		$carry = 0;
		for ( $i = strlen( $left ) - 1, $j = strlen( $right ) - 1; $i >= 0 || $j >= 0 || $carry; --$i, --$j ) {
			$sum = ( $i >= 0 ? (int) $left[$i] : 0 ) + ( $j >= 0 ? (int) $right[$j] : 0 ) + $carry;
			$result = (string) ( $sum % 10 ) . $result;
			$carry = intdiv( $sum, 10 );
		}
		return ltrim( $result, '0' ) ?: '0';
	}

	/**
	 * MySQL 连接级锁覆盖读取、版本比较和 WooCommerce 保存，兼容 HPOS。
	 * @return bool|WP_Error
	 */
	public static function apply( SSCPay_Creditcard_Gateway $gateway, WC_Order $order, array $payload, $source ) {
		return self::with_lock( $order, static function ( WC_Order $fresh ) use ( $gateway, $payload, $source ) {
			return self::apply_locked( $gateway, $fresh, $payload, $source );
		} );
	}

	/** @return mixed 串行化本插件对同一商城订单的支付与物流更新。 */
	public static function with_lock( WC_Order $order, callable $operation ) {
		global $wpdb;
		$key = 'sscpay_order_' . md5( $wpdb->prefix . ':' . $order->get_id() );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
			return new WP_Error( 'sscpay_busy', 'Order is being updated; retry later.' );
		}
		try {
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh instanceof WC_Order ) {
				return new WP_Error( 'sscpay_missing_order', 'Order not found.' );
			}
			$fresh->read_meta_data( true );
			return $operation( $fresh );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/** @return bool|WP_Error */
	private static function apply_locked( SSCPay_Creditcard_Gateway $gateway, WC_Order $order, array $payload, $source ) {
		$helper = 'SSCPay_Creditcard_Order_Helper';
		if ( $order->get_payment_method() !== SSCPAY_CREDITCARD_GATEWAY_ID ) {
			return new WP_Error( 'sscpay_wrong_gateway', 'Payment method mismatch.' );
		}
		$merchant_no = (string) $order->get_meta( $helper::META_MERCHANT_ORDER_NO );
		$system_no = (string) $order->get_meta( $helper::META_ORDER_NO );
		if ( '' === $merchant_no || ( $payload['merchant_order_no'] ?? '' ) !== $merchant_no
			|| empty( $payload['order_no'] ) || ( '' !== $system_no && $payload['order_no'] !== $system_no ) ) {
			return new WP_Error( 'sscpay_identity_mismatch', 'Order references do not match.' );
		}
		$expected = self::cents( (string) $order->get_total() );
		$total = self::cents( $payload['amount'] ?? null );
		if ( null === $total || null === $expected || $total !== $expected
			|| ( $payload['currency'] ?? '' ) !== strtoupper( (string) $order->get_currency() ) ) {
			$order->update_meta_data( $helper::META_NEEDS_REVIEW, 'yes' );
			if ( ! $order->is_paid() ) {
				$order->update_status( 'on-hold', 'SSCPay 金额或币种不一致，请人工核对。' );
			}
			$order->save();
			return new WP_Error( 'sscpay_amount_mismatch', 'Amount or currency mismatch.' );
		}
		$type = $payload['order_type'] ?? 'single';
		if ( ! in_array( $type, array( 'single', 'combine' ), true ) ) {
			return new WP_Error( 'sscpay_bad_type', 'Invalid order type.' );
		}
		$is_v2 = 'combine' === $type || isset( $payload['event_id'] );
		$version = $payload['version'] ?? null;
		$event = $payload['event_id'] ?? '';
		if ( $is_v2 && ( ! is_int( $version ) || $version < 0 || ( 'webhook' === $source && ( $version < 1 || ! is_string( $event ) || '' === $event || strlen( $event ) > 128 ) ) ) ) {
			return new WP_Error( 'sscpay_bad_version', 'Invalid v2 event or version.' );
		}
		$progress = (array) $order->get_meta( self::META_PROGRESS );
		$events = $order->get_meta( self::META_EVENTS );
		$events = is_array( $events ) ? $events : array();
		if ( ( '' !== $event && in_array( $event, $events, true ) )
			|| ( $is_v2 && isset( $progress['version'] ) && ( $version < $progress['version'] || ( 'webhook' === $source && $version === $progress['version'] ) ) ) ) {
			return false;
		}
		$status = $payload['status'] ?? '';
		if ( $is_v2 ) {
			$paid = self::cents( $payload['paid_amount'] ?? null );
			$remaining = self::cents( $payload['remaining_amount'] ?? null );
			if ( null === $paid || null === $remaining || self::add( $paid, $remaining ) !== $total
				|| ! is_array( $payload['children'] ?? null )
				|| ( 'paid' === $status && ( '0' !== $remaining || $paid !== $total ) ) ) {
				return new WP_Error( 'sscpay_bad_progress', 'Invalid v2 payment totals.' );
			}
			if ( $order->is_paid() && 'paid' !== $status ) {
				return false;
			}
			$order->update_meta_data( self::META_PROGRESS, array(
				'order_type' => $type, 'version' => $version, 'status' => $status,
				'order_status' => $payload['order_status'] ?? $status,
				'paid_amount' => $payload['paid_amount'], 'remaining_amount' => $payload['remaining_amount'],
				'children' => $payload['children'],
			) );
			if ( '' !== $event ) {
				$events[] = $event;
				$order->update_meta_data( self::META_EVENTS, array_slice( $events, -128 ) );
			}
		}
		$order->update_meta_data( $helper::META_ORDER_NO, $payload['order_no'] );
		if ( 'paid' === $status || ( 'single' === $type && in_array( $status, array( 'shipped', 'completed' ), true ) ) ) {
			$changed = $gateway->mark_order_paid( $order, $payload, $source );
		} elseif ( 'combine' === $type && in_array( $status, array( 'pending', 'review', 'expired' ), true ) ) {
			$partial = '0' !== self::cents( $payload['paid_amount'] );
			$target = $partial || 'review' === $status ? 'on-hold' : ( 'expired' === $status ? 'cancelled' : 'pending' );
			$changed = ! $order->has_status( $target );
			if ( $changed ) {
				$order->update_status( $target, 'SSCPay 组合付款尚未付清，请勿发货。' );
			}
			if ( 'review' === $status || ( 'expired' === $status && $partial ) ) {
				$order->update_meta_data( $helper::META_NEEDS_REVIEW, 'yes' );
			}
		} else {
			$changed = $gateway->apply_remote_status( $order, $status );
		}
		$order->save();
		return $changed;
	}

	/** @return array|WP_Error 将同一真实运单分别上传到每个已付真实子单。 */
	public static function shipping_payloads( WC_Order $order, array $shipment ) {
		$progress = (array) $order->get_meta( self::META_PROGRESS );
		$numbers = array( (string) $order->get_meta( SSCPay_Creditcard_Order_Helper::META_MERCHANT_ORDER_NO ) );
		if ( 'combine' === ( $progress['order_type'] ?? '' ) ) {
			if ( 'paid' !== ( $progress['status'] ?? '' ) ) {
				return new WP_Error( 'sscpay_unpaid_parent', 'Combined order is not paid in full.' );
			}
			$numbers = array();
			foreach ( $progress['children'] as $child ) {
				if ( 'paid' === ( $child['payment_status'] ?? '' ) && ! empty( $child['merchant_order_no'] ) ) {
					$numbers[] = $child['merchant_order_no'];
				}
			}
		}
		if ( empty( $numbers ) || in_array( '', $numbers, true ) ) {
			return new WP_Error( 'sscpay_no_shipping_order', 'No payable order reference.' );
		}
		$payloads = array();
		foreach ( array_unique( $numbers ) as $number ) {
			$payloads[] = array_merge( $shipment, array( 'merchant_order_no' => $number ) );
		}
		return $payloads;
	}
}
