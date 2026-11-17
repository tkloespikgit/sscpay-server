<?php
/**
 * 异步通知（webhook）与同步跳转处理。
 *
 * @package SSCPay_Paypal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SSCPay_Paypal_Webhook
 */
class SSCPay_Paypal_Webhook {

	/**
	 * 单例。
	 *
	 * @var SSCPay_Paypal_Webhook|null
	 */
	protected static $instance = null;

	/**
	 * 取得单例。
	 *
	 * @return SSCPay_Paypal_Webhook
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * 构造函数。
	 */
	protected function __construct() {
		add_action( 'woocommerce_api_' . SSCPAY_PAYPAL_GATEWAY_ID . '_notify', array( $this, 'handle_notify' ) );
		add_action( 'woocommerce_api_' . SSCPAY_PAYPAL_GATEWAY_ID . '_return', array( $this, 'handle_return' ) );
		add_action( 'woocommerce_api_' . SSCPAY_PAYPAL_GATEWAY_ID . '_shipment', array( $this, 'handle_shipment' ) );
		add_action( 'woocommerce_api_sscpay_shipment', array( $this, 'handle_shipment' ) );
	}

	/**
	 * 取网关实例。
	 *
	 * @return SSCPay_Paypal_Gateway|null
	 */
	public static function get_gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		if ( isset( $gateways[ SSCPAY_PAYPAL_GATEWAY_ID ] ) && $gateways[ SSCPAY_PAYPAL_GATEWAY_ID ] instanceof SSCPay_Paypal_Gateway ) {
			return $gateways[ SSCPAY_PAYPAL_GATEWAY_ID ];
		}

		if ( class_exists( 'SSCPay_Paypal_Gateway' ) ) {
			return new SSCPay_Paypal_Gateway();
		}

		return null;
	}

	/**
	 * 读取请求头（兼容不同 SAPI）。
	 *
	 * @param string $name Header 名，如 App-ID。
	 * @return string
	 */
	protected function header( $name ) {
		$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		$value = '';

		if ( isset( $_SERVER[ $key ] ) ) {
			// WordPress 会给 $_SERVER 加转义，这里必须 unslash 拿回原值。
			// 注意不要用 sanitize_text_field：验签要求逐字节一致，任何改写都会导致签名对不上。
			$value = (string) wp_unslash( $_SERVER[ $key ] );
		} elseif ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $header => $header_value ) {
				if ( 0 === strcasecmp( (string) $header, $name ) ) {
					$value = (string) $header_value;
					break;
				}
			}
		}

		$value = trim( $value );

		// 只保留可见 ASCII，挡掉换行等注入字符，同时不改动正常取值。
		return preg_replace( '/[^\x20-\x7E]/', '', $value );
	}

	/**
	 * 输出响应并结束。
	 *
	 * @param int    $status  HTTP 状态码。
	 * @param string $message 提示。
	 * @return void
	 */
	protected function respond( $status, $message ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );

		echo wp_json_encode(
			array(
				'code' => 200 === $status ? 0 : $status,
				'msg'  => $message,
			),
			JSON_UNESCAPED_UNICODE
		);

		exit;
	}

	/** @return void */
	public function handle_notify() {
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) {
			$this->respond( 400, 'Invalid JSON body.' );
		}
		$result = $this->process_notification( $body, $this->header( 'App-ID' ), $this->header( 'Timestamp' ), $this->header( 'X-Nonce' ) );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			$this->respond( isset( $data['status'] ) ? $data['status'] : 503, $result->get_error_message() );
		}
		$this->respond( 200, 'success' );
	}

	/** @return bool|WP_Error V1 与 V2 均对完整请求体验签，再处理父单状态。 */
	public function process_notification( array $body, $app_id, $timestamp, $nonce ) {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'sscpay_unavailable', 'Gateway unavailable.', array( 'status' => 503 ) );
		}
		$order = SSCPay_Paypal_Order_Helper::locate_order( $body['merchant_order_no'] ?? '', $body['order_no'] ?? '', $gateway->get_order_prefix() );
		if ( ! $order ) {
			return new WP_Error( 'sscpay_missing', 'Order not found.', array( 'status' => 404 ) );
		}
		$error = $this->authenticate( $order, $body, $app_id, $timestamp, $nonce );
		if ( $error ) {
			return $error;
		}
		$replay_key = 'sscpay_paypal_nonce_' . hash( 'sha256', $app_id . ':' . $nonce );
		if ( get_transient( $replay_key ) ) {
			return false;
		}
		$result = SSCPay_Paypal_Order_State::apply( $gateway, $order, $body, 'webhook' );
		if ( ! is_wp_error( $result ) ) {
			set_transient( $replay_key, true, 10 * MINUTE_IN_SECONDS );
			$order = wc_get_order( $order->get_id() ) ?: $order;
			do_action( 'sscpay_paypal_payment_progress', $order, $body, $result );
			if ( 'paid' === ( $body['status'] ?? '' ) ) {
				do_action( 'sscpay_paypal_notify_processed', $order, $body, $result );
			}
		}
		return $result;
	}

	/** @return void */
	public function handle_shipment() {
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) {
			$this->respond( 400, 'Invalid JSON body.' );
		}
		$result = $this->process_shipment( $body, $this->header( 'App-ID' ), $this->header( 'Timestamp' ), $this->header( 'X-Nonce' ) );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			$this->respond( isset( $data['status'] ) ? $data['status'] : 503, $result->get_error_message() );
		}
		$this->respond( 200, 'success' );
	}

	/** @return WP_Error|null */
	private function authenticate( WC_Order $order, array $body, $app_id, $timestamp, $nonce, $gateway = null ) {
		$gateway = $gateway ?: self::get_gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'sscpay_unavailable', 'Gateway unavailable.', array( 'status' => 503 ) );
		}
		$credentials = $gateway->get_credentials_for( 'yes' === $order->get_meta( SSCPay_Paypal_Order_Helper::META_TESTMODE ) );
		if ( '' === $credentials['app_id'] || '' === $credentials['api_key'] || ! hash_equals( $credentials['app_id'], $app_id )
			|| ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 || '' === $nonce
			|| ! SSCPay_Paypal_Signature::verify( $app_id, $timestamp, $nonce, $body, $credentials['api_key'] ) ) {
			return new WP_Error( 'sscpay_signature', 'Signature verification failed.', array( 'status' => 401 ) );
		}
		return null;
	}

	/** @return bool|WP_Error 验签后按原商户号和运单号去重，不改变付款或履约状态。 */
	public function process_shipment( array $body, $app_id, $timestamp, $nonce ) {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'sscpay_unavailable', 'Gateway unavailable.', array( 'status' => 503 ) );
		}
		$reference = $body['parent_order_no'] ?? $body['order_no'] ?? '';
		$order = SSCPay_Paypal_Order_Helper::locate_order( $body['merchant_order_no'] ?? '', $reference, $gateway->get_order_prefix() );
		if ( ! $order ) {
			return new WP_Error( 'sscpay_missing', 'Order not found.', array( 'status' => 404 ) );
		}
		$method = $order->get_payment_method();
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway = in_array( $method, array( 'sscpay_paypal', 'sscpay_creditcard' ), true ) ? ( $gateways[$method] ?? null ) : null;
		if ( ! $gateway || ! method_exists( $gateway, 'get_credentials_for' ) ) {
			return new WP_Error( 'sscpay_unavailable', 'Order payment gateway unavailable.', array( 'status' => 503 ) );
		}
		$error = $this->authenticate( $order, $body, $app_id, $timestamp, $nonce, $gateway );
		if ( $error ) {
			return $error;
		}
		foreach ( array( 'merchant_order_no', 'carrier', 'tracking_number', 'shipped_at' ) as $field ) {
			if ( ! is_string( $body[$field] ?? null ) || '' === trim( $body[$field] ) || strlen( $body[$field] ) > 255 ) {
				return new WP_Error( 'sscpay_bad_shipment', 'Invalid shipment fields.', array( 'status' => 422 ) );
			}
		}
		return SSCPay_Paypal_Order_State::with_lock( $order, static function ( WC_Order $fresh ) use ( $body, $reference, $method ) {
			$merchant = (string) $fresh->get_meta( SSCPay_Paypal_Order_Helper::META_MERCHANT_ORDER_NO );
			if ( $fresh->get_payment_method() !== $method || $merchant !== $body['merchant_order_no']
				|| ( '' !== $reference && $reference !== (string) $fresh->get_meta( SSCPay_Paypal_Order_Helper::META_ORDER_NO ) )
				|| ( 'combine' === ( $body['order_type'] ?? '' ) && ( ( $body['parent_merchant_order_no'] ?? '' ) !== $merchant || empty( $body['child_merchant_order_no'] ) ) ) ) {
				return new WP_Error( 'sscpay_identity_mismatch', 'Shipment order references do not match.', array( 'status' => 422 ) );
			}
			$key = hash( 'sha256', $merchant . "\0" . $body['tracking_number'] );
			$shipments = $fresh->get_meta( '_sscpay_shipments' );
			$shipments = is_array( $shipments ) ? $shipments : array();
			if ( isset( $shipments[$key] ) ) {
				return false;
			}
			$shipments[$key] = array_intersect_key( $body, array_flip( array(
				'merchant_order_no', 'child_merchant_order_no', 'order_no', 'parent_order_no',
				'carrier', 'carrier_code', 'tracking_number', 'tracking_url', 'shipped_at',
			) ) );
			$fresh->update_meta_data( '_sscpay_shipments', $shipments );
			$fresh->add_order_note( 'SSCPay 物流：' . wp_strip_all_tags( $body['carrier'] . ' ' . $body['tracking_number'] ) );
			$fresh->save();
			do_action( $method . '_shipment_received', $fresh, $shipments[$key] );
			return true;
		} );
	}

	/**
	 * 处理同步跳转：主动查一次订单状态再进感谢页。
	 *
	 * 文档明确说 return_url 只是展示，不可作为状态依据，所以这里一定要查一次。
	 *
	 * @return void
	 */
	public function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id  = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			SSCPay_Paypal_Logger::log( '[跳转] 订单校验失败：order_id=' . $order_id, 'warning' );
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}

		$gateway = self::get_gateway();

		if ( $gateway && ! $order->is_paid() ) {
			$this->sync_order( $gateway, $order );
			$order = wc_get_order( $order->get_id() ) ?: $order;
		}

		if ( function_exists( 'WC' ) && WC()->cart && $order->is_paid() ) {
			WC()->cart->empty_cart();
		}

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	/**
	 * 调 /order/query 同步一次订单状态。
	 *
	 * @param SSCPay_Paypal_Gateway $gateway 网关。
	 * @param WC_Order              $order   订单。
	 * @return bool 是否发生了状态变更。
	 */
	public function sync_order( SSCPay_Paypal_Gateway $gateway, WC_Order $order ) {
		$testmode = 'yes' === $order->get_meta( SSCPay_Paypal_Order_Helper::META_TESTMODE );
		$client   = $gateway->get_client( $testmode );

		if ( ! $client->is_configured() ) {
			return false;
		}

		$order_no          = (string) $order->get_meta( SSCPay_Paypal_Order_Helper::META_ORDER_NO );
		$merchant_order_no = (string) $order->get_meta( SSCPay_Paypal_Order_Helper::META_MERCHANT_ORDER_NO );

		if ( '' === $order_no && '' === $merchant_order_no ) {
			return false;
		}

		$payload = array();

		if ( '' !== $order_no ) {
			$payload['order_no'] = $order_no;
			if ( '' !== $merchant_order_no ) {
				$payload['merchant_order_no'] = $merchant_order_no;
			}
		} else {
			$payload['merchant_order_no'] = $merchant_order_no;
		}

		$result = $client->query_order( $payload );

		if ( is_wp_error( $result ) ) {
			SSCPay_Paypal_Logger::log( '[查单] 订单 #' . $order->get_id() . ' 查询失败：' . $result->get_error_message(), 'warning' );

			return false;
		}

		$applied = SSCPay_Paypal_Order_State::apply( $gateway, $order, $result, 'query' );
		if ( is_wp_error( $applied ) ) {
			SSCPay_Paypal_Logger::log( $applied->get_error_message(), 'warning' );
			return false;
		}
		return $applied;
	}
}
