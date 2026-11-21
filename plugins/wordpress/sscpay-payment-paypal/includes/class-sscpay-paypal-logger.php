<?php
/**
 * 日志封装。写入 WooCommerce 日志（WooCommerce > 状态 > 日志）。
 *
 * @package SSCPay_Paypal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SSCPay_Paypal_Logger
 */
class SSCPay_Paypal_Logger {

	/**
	 * WC_Logger 实例。
	 *
	 * @var WC_Logger|null
	 */
	protected static $logger = null;

	/**
	 * 是否开启调试日志。
	 *
	 * @var bool|null
	 */
	protected static $enabled = null;

	/**
	 * 日志来源。
	 *
	 * @return array
	 */
	protected static function context() {
		return array( 'source' => SSCPAY_PAYPAL_GATEWAY_ID );
	}

	/**
	 * 是否开启调试。
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( null === self::$enabled ) {
			$settings      = get_option( 'woocommerce_' . SSCPAY_PAYPAL_GATEWAY_ID . '_settings', array() );
			self::$enabled = is_array( $settings ) && isset( $settings['debug'] ) && 'yes' === $settings['debug'];
		}

		return self::$enabled;
	}

	/**
	 * 重置缓存的开关状态（保存设置后调用）。
	 *
	 * @return void
	 */
	public static function reset() {
		self::$enabled = null;
	}

	/**
	 * 写日志。
	 *
	 * @param string $message 内容。
	 * @param string $level   级别：debug/info/notice/warning/error/critical。
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		// error 及以上无论是否开调试都记录，方便事后排查。
		$always = in_array( $level, array( 'warning', 'error', 'critical', 'alert', 'emergency' ), true );

		if ( ! $always && ! self::enabled() ) {
			return;
		}

		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}

		if ( ! self::$logger ) {
			return;
		}

		self::$logger->log( $level, $message, self::context() );
	}

	/**
	 * 记录数组/对象，自动脱敏。
	 *
	 * @param string $label 标签。
	 * @param mixed  $data  数据。
	 * @param string $level 级别。
	 * @return void
	 */
	public static function dump( $label, $data, $level = 'debug' ) {
		self::log( $label . ' ' . wp_json_encode( self::mask( $data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $level );
	}

	/**
	 * 敏感字段脱敏。
	 *
	 * @param mixed $data 数据。
	 * @return mixed
	 */
	public static function mask( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sensitive = array( 'api_key', 'apikey', 'token', 'authorization', 'password', 'secret', 'customer_ip' );
		$masked    = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), $sensitive, true ) ) {
				$masked[ $key ] = '***';
				continue;
			}

			$masked[ $key ] = is_array( $value ) ? self::mask( $value ) : $value;
		}

		return $masked;
	}
}
