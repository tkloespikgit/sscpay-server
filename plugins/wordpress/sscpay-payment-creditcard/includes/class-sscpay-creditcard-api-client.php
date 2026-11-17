<?php
/**
 * 订单系统 API 客户端。
 *
 * @package SSCPay_Creditcard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SSCPay_Creditcard_Api_Client
 */
class SSCPay_Creditcard_Api_Client {

	/**
	 * API Base URL（不含尾部斜杠）。
	 *
	 * @var string
	 */
	protected $base_url;

	/**
	 * App-ID。
	 *
	 * @var string
	 */
	protected $app_id;

	/**
	 * API Key。
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * 请求超时（秒）。
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * 构造函数。
	 *
	 * @param string $base_url Base URL。
	 * @param string $app_id   App-ID。
	 * @param string $api_key  API Key。
	 * @param int    $timeout  超时秒数。
	 */
	public function __construct( $base_url, $app_id, $api_key, $timeout = 30 ) {
		$this->base_url = untrailingslashit( trim( (string) $base_url ) );
		$this->app_id   = trim( (string) $app_id );
		$this->api_key  = trim( (string) $api_key );
		$this->timeout  = max( 5, (int) $timeout );
	}

	/**
	 * 凭据是否齐全。
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->base_url && '' !== $this->app_id && '' !== $this->api_key;
	}

	/**
	 * App-ID。
	 *
	 * @return string
	 */
	public function get_app_id() {
		return $this->app_id;
	}

	/**
	 * API Key。
	 *
	 * @return string
	 */
	public function get_api_key() {
		return $this->api_key;
	}

	/**
	 * 创建订单。
	 *
	 * @param array $body 请求体。
	 * @return array|WP_Error
	 */
	public function create_order( array $body ) {
		return $this->request( '/api/order/create', $body );
	}

	/**
	 * 查询订单。
	 *
	 * @param array $body 请求体。
	 * @return array|WP_Error
	 */
	public function query_order( array $body ) {
		return $this->request( '/api/order/query', $body );
	}

	/**
	 * 同步物流。
	 *
	 * @param array $body 请求体。
	 * @return array|WP_Error
	 */
	public function ship_order( array $body ) {
		return $this->request( '/api/order/ship', $body );
	}

	/**
	 * 发起签名请求。
	 *
	 * @param string $path 路径。
	 * @param array  $body 请求体（不含 sign）。
	 * @return array|WP_Error 成功返回 data 数组。
	 */
	protected function request( $path, array $body ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'sscpay_not_configured',
				__( '支付网关尚未配置完整（API 地址 / App-ID / API Key）。', 'sscpay-payment-creditcard' )
			);
		}

		unset( $body['sign'] );

		$timestamp = (string) time();
		$nonce     = SSCPay_Creditcard_Signature::nonce();
		$body['sign'] = SSCPay_Creditcard_Signature::sign( $this->app_id, $timestamp, $nonce, $body, $this->api_key );

		// 发出去的 JSON 用同样的编码选项，避免中文/斜杠转义差异。
		$payload = json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		if ( ! is_string( $payload ) ) {
			return new WP_Error( 'sscpay_encode_failed', __( '请求体序列化失败（可能含非法 UTF-8 字符）。', 'sscpay-payment-creditcard' ) );
		}

		$url = $this->base_url . $path;

		SSCPay_Creditcard_Logger::dump( '[请求] ' . $url, $body );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $this->timeout,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'sscpay_creditcard_sslverify', true ),
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'App-ID'       => $this->app_id,
					'Timestamp'    => $timestamp,
					'X-Nonce'      => $nonce,
					'User-Agent'   => 'SSCPay-WooCommerce/' . SSCPAY_CREDITCARD_VERSION . '; ' . home_url( '/' ),
				),
				'body'        => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			SSCPay_Creditcard_Logger::log( '[网络错误] ' . $url . ' :: ' . $response->get_error_message(), 'error' );

			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		SSCPay_Creditcard_Logger::log( '[响应 ' . $status . '] ' . $url . ' :: ' . $raw, 200 === $status ? 'debug' : 'warning' );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'sscpay_bad_response',
				sprintf(
					/* translators: 1: HTTP 状态码 */
					__( '订单系统返回了无法解析的内容（HTTP %d）。', 'sscpay-payment-creditcard' ),
					$status
				),
				array( 'status' => $status, 'raw' => $raw )
			);
		}

		$code = isset( $data['code'] ) ? (int) $data['code'] : -1;

		if ( 200 === $status && 0 === $code ) {
			return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		}

		$message    = isset( $data['msg'] ) ? (string) $data['msg'] : __( '订单系统返回了未知错误。', 'sscpay-payment-creditcard' );
		$error_code = isset( $data['error_code'] ) ? (string) $data['error_code'] : '';

		// 422 标准校验错误会带 errors 字段，拼进提示里方便定位。
		if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$details = array();

			foreach ( $data['errors'] as $field => $messages ) {
				$messages  = is_array( $messages ) ? $messages : array( $messages );
				$details[] = $field . ': ' . implode( '; ', array_map( 'strval', $messages ) );
			}

			if ( $details ) {
				$message .= ' (' . implode( ' | ', $details ) . ')';
			}
		}

		return new WP_Error(
			'sscpay_api_error',
			$message,
			array(
				'status'     => $status,
				'code'       => $code,
				'error_code' => $error_code,
				'response'   => $data,
			)
		);
	}
}
