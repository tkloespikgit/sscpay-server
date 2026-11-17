<?php
/**
 * 订单工具：把 WooCommerce 订单拆解成订单系统要求的报文，以及反查订单。
 *
 * @package SSCPay_Creditcard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SSCPay_Creditcard_Order_Helper
 */
class SSCPay_Creditcard_Order_Helper {

	const META_ORDER_NO          = '_sscpay_order_no';
	const META_MERCHANT_ORDER_NO = '_sscpay_merchant_order_no';
	const META_PAYMENT_URL       = '_sscpay_payment_url';
	const META_PAYMENT_METHOD    = '_sscpay_payment_method';
	const META_TESTMODE          = '_sscpay_testmode';
	const META_ATTEMPT           = '_sscpay_attempt';
	const META_CONVERTED         = '_sscpay_converted';
	const META_PAID_HANDLED      = '_sscpay_paid_handled';
	const META_NEEDS_REVIEW      = '_sscpay_needs_review';

	/**
	 * 金额格式化成字符串（如 "190.00"）。
	 *
	 * @param mixed $value 数值。
	 * @param int   $dp    小数位。
	 * @return string
	 */
	public static function amount( $value, $dp = null ) {
		if ( null === $dp ) {
			$dp = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		}

		return number_format( (float) $value, (int) $dp, '.', '' );
	}

	/**
	 * 截断字符串到指定长度。
	 *
	 * @param string $value  字符串。
	 * @param int    $length 最大长度。
	 * @return string
	 */
	public static function truncate( $value, $length ) {
		$value = trim( wp_strip_all_tags( (string) $value ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * 生成商户订单号。
	 *
	 * 同一商城订单复用已保存的商户订单号，网络失败或组合付款重试不另建订单。
	 *
	 * @param WC_Order $order  订单。
	 * @param string   $prefix 前缀。
	 * @return string
	 */
	public static function build_merchant_order_no( WC_Order $order, $prefix ) {
		$existing = (string) $order->get_meta( self::META_MERCHANT_ORDER_NO );
		if ( '' !== $existing ) {
			return $existing;
		}
		$attempt = (int) $order->get_meta( self::META_ATTEMPT );
		++$attempt;
		$order->update_meta_data( self::META_ATTEMPT, $attempt );

		$no = $prefix . $order->get_id();

		if ( $attempt > 1 ) {
			$no .= '-' . $attempt;
		}

		return self::truncate( $no, 64 );
	}

	/**
	 * 从商户订单号里解析出 WooCommerce 订单 ID。
	 *
	 * @param string $merchant_order_no 商户订单号。
	 * @param string $prefix            前缀。
	 * @return int 0 表示解析不出来。
	 */
	public static function parse_order_id( $merchant_order_no, $prefix ) {
		$value = (string) $merchant_order_no;

		if ( '' !== $prefix && 0 === strpos( $value, $prefix ) ) {
			$value = substr( $value, strlen( $prefix ) );
		}

		$parts = explode( '-', $value );
		$id    = isset( $parts[0] ) ? (int) $parts[0] : 0;

		return $id > 0 ? $id : 0;
	}

	/**
	 * 按 meta 反查订单（兼容 HPOS 与传统 post 存储）。
	 *
	 * @param string $meta_key   meta key。
	 * @param string $meta_value meta 值。
	 * @return WC_Order|null
	 */
	public static function find_order_by_meta( $meta_key, $meta_value ) {
		if ( '' === (string) $meta_value ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => 'any',
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $meta_key,
						'value'   => $meta_value,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		$order = wc_get_order( $orders[0] );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * 综合定位订单：先按商户订单号 meta，再按系统订单号 meta，最后从订单号里解析 ID。
	 *
	 * @param string $merchant_order_no 商户订单号。
	 * @param string $order_no          系统订单号。
	 * @param string $prefix            商户订单号前缀。
	 * @return WC_Order|null
	 */
	public static function locate_order( $merchant_order_no, $order_no, $prefix ) {
		$order = self::find_order_by_meta( self::META_MERCHANT_ORDER_NO, $merchant_order_no );

		if ( $order ) {
			return $order;
		}

		$order = self::find_order_by_meta( self::META_ORDER_NO, $order_no );

		if ( $order ) {
			return $order;
		}

		$order_id = self::parse_order_id( $merchant_order_no, $prefix );

		if ( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * 把订单拆成 items + 金额四件套。
	 *
	 * 订单系统的硬性约束：
	 *   subtotal = Σ(items[].unit_price × quantity)
	 *   amount   = subtotal + shipping_fee - discount + tax   （误差 > 0.01 直接拒单）
	 *   amount   必须等于用户实付金额
	 *
	 * WooCommerce 里除了商品还有手续费（fee）、优惠券、税等，这里的做法是：
	 *   - 正数手续费当作一条商品行并入 subtotal；
	 *   - 用 discount 反推吸收所有减项（优惠券、负数手续费、四舍五入误差）；
	 *   - 如果反推出来 discount 为负（加项多于减项），补一条"金额调整"行到 items 里。
	 *
	 * @param WC_Order $order 订单。
	 * @return array{items:array,subtotal:string,shipping_fee:string,discount:string,tax:string,amount:string}
	 */
	public static function build_amounts( WC_Order $order ) {
		$dp    = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$items = array();
		$sum   = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );
			// 用 subtotal（未扣优惠券）算单价，优惠券统一走 discount 字段。
			$unit_raw   = (float) $item->get_subtotal() / $quantity;
			$unit_price = self::amount( $unit_raw, $dp );
			$sum       += (float) $unit_price * $quantity;

			$product    = $item->get_product();
			$product_id = $item->get_product_id() ? (int) $item->get_product_id() : 0;
			$permalink  = $product_id ? get_permalink( $product_id ) : '';

			if ( ! $permalink ) {
				$permalink = home_url( '/' );
			}

			$sku = '';

			if ( $product instanceof WC_Product ) {
				$sku = (string) $product->get_sku();
			}

			$description = '';

			if ( $product instanceof WC_Product ) {
				$description = $product->get_short_description();

				if ( '' === $description ) {
					$description = $product->get_description();
				}
			}

			$line = array(
				'product_id'   => (string) ( $product_id ? $product_id : $item->get_id() ),
				'product_url'  => self::truncate( $permalink, 500 ),
				'product_name' => self::truncate( $item->get_name(), 255 ),
				'unit_price'   => $unit_price,
				'quantity'     => $quantity,
			);

			if ( '' !== $sku ) {
				$line['product_sku'] = self::truncate( $sku, 64 );
			}

			if ( '' !== $description ) {
				$line['product_description'] = self::truncate( $description, 500 );
			}

			$items[] = $line;
		}

		// 正数手续费并入 items；负数手续费当减项，后面被 discount 吸收。
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$total = (float) $fee->get_total();

			if ( $total <= 0 ) {
				continue;
			}

			$unit_price = self::amount( $total, $dp );
			$sum       += (float) $unit_price;

			$items[] = array(
				'product_id'   => 'fee-' . $fee->get_id(),
				'product_url'  => home_url( '/' ),
				'product_name' => self::truncate( $fee->get_name() ? $fee->get_name() : __( '附加费用', 'sscpay-payment-creditcard' ), 255 ),
				'unit_price'   => $unit_price,
				'quantity'     => 1,
			);
		}

		$shipping = round( (float) $order->get_shipping_total(), $dp );
		$tax      = round( (float) $order->get_total_tax(), $dp );
		$total    = round( (float) $order->get_total(), $dp );
		$subtotal = round( $sum, $dp );
		$discount = round( $subtotal + $shipping + $tax - $total, $dp );

		$epsilon = 1 / pow( 10, $dp + 1 );

		if ( $discount < -$epsilon ) {
			// 加项多于减项（例如含税显示导致的差额），补一条调整行。
			$adjust     = self::amount( -$discount, $dp );
			$sum       += (float) $adjust;
			$subtotal   = round( $sum, $dp );
			$discount   = 0.0;
			$items[]    = array(
				'product_id'   => 'adjustment',
				'product_url'  => home_url( '/' ),
				'product_name' => self::truncate( __( '金额调整', 'sscpay-payment-creditcard' ), 255 ),
				'unit_price'   => $adjust,
				'quantity'     => 1,
			);
		} elseif ( $discount < 0 ) {
			$discount = 0.0;
		}

		// items 至少要有 1 条：极端情况下（例如全是负数手续费）兜底补一条总额行。
		if ( ! $items ) {
			$items[] = array(
				'product_id'   => 'order-' . $order->get_id(),
				'product_url'  => home_url( '/' ),
				'product_name' => self::truncate(
					sprintf(
						/* translators: %s: 订单号 */
						__( '订单 %s', 'sscpay-payment-creditcard' ),
						$order->get_order_number()
					),
					255
				),
				'unit_price'   => self::amount( $total, $dp ),
				'quantity'     => 1,
			);

			$subtotal = round( $total, $dp );
			$shipping = 0.0;
			$tax      = 0.0;
			$discount = 0.0;
		}

		return array(
			'items'        => $items,
			'subtotal'     => self::amount( $subtotal, $dp ),
			'shipping_fee' => self::amount( $shipping, $dp ),
			'discount'     => self::amount( $discount, $dp ),
			'tax'          => self::amount( $tax, $dp ),
			'amount'       => self::amount( $total, $dp ),
		);
	}

	/**
	 * 客户信息。
	 *
	 * @param WC_Order $order 订单。
	 * @return array
	 */
	public static function build_customer( WC_Order $order ) {
		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();

		if ( '' === $first ) {
			$first = $order->get_shipping_first_name();
		}

		if ( '' === $last ) {
			$last = $order->get_shipping_last_name();
		}

		return array(
			'first_name' => self::truncate( $first, 100 ),
			'last_name'  => self::truncate( $last, 100 ),
			'email'      => self::truncate( $order->get_billing_email(), 255 ),
			'phone'      => self::truncate( $order->get_billing_phone(), 50 ),
		);
	}

	/**
	 * 收货地址（无收货地址时退回账单地址）。
	 *
	 * @param WC_Order $order 订单。
	 * @return array
	 */
	public static function build_address( WC_Order $order ) {
		$use_shipping = '' !== trim( (string) $order->get_shipping_address_1() );

		$line1   = $use_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$line2   = $use_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		$city    = $use_shipping ? $order->get_shipping_city() : $order->get_billing_city();
		$state   = $use_shipping ? $order->get_shipping_state() : $order->get_billing_state();
		$country = $use_shipping ? $order->get_shipping_country() : $order->get_billing_country();
		$zip     = $use_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode();

		$address = array(
			'line1'   => self::truncate( $line1, 255 ),
			'city'    => self::truncate( $city, 100 ),
			'country' => strtoupper( self::truncate( $country, 2 ) ),
			'zip'     => self::truncate( $zip, 20 ),
		);

		if ( '' !== trim( (string) $line2 ) ) {
			$address['line2'] = self::truncate( $line2, 255 );
		}

		if ( '' !== trim( (string) $state ) ) {
			$address['state'] = self::truncate( $state, 100 );
		}

		return $address;
	}
}
