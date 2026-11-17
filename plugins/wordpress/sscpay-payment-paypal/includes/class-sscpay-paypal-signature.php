<?php
/**
 * 签名与验签。
 *
 * 出站请求（/order/create、/order/query、/order/ship）与入站 webhook 验签用的是
 * 同一套算法，这里只实现一次，两个方向复用。
 *
 * StringToSign = App-ID + "\n" + Timestamp + "\n" + X-Nonce + "\n" + 规范化请求体（不含 sign）
 * Signature    = HMAC-SHA256(StringToSign, api_key)  小写十六进制
 *
 * @package SSCPay_Paypal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SSCPay_Paypal_Signature
 */
class SSCPay_Paypal_Signature {

	/**
	 * 规范化请求体。
	 *
	 * 1. 移除 sign 字段。
	 * 2. 关联数组按 key 递归 ksort；数字索引列表保持原顺序。
	 * 3. json_encode(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)。
	 *
	 * 注意：不要在 payload 里使用 stdClass 或强制对象编码。服务端拿到 JSON 后用
	 * json_decode($raw, true) 转成 PHP 数组再重新编码，空对象 {} 会变成 []，
	 * 我们这边全程使用 PHP 数组，空数组同样编码为 []，两边才能逐字节一致。
	 *
	 * @param array $body 请求体。
	 * @return string
	 */
	public static function canonicalize( array $body ) {
		unset( $body['sign'] );

		$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			self::ksort_recursive( $body ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return is_string( $json ) ? $json : '';
	}

	/**
	 * 递归排序：关联数组 ksort，列表保持原序。
	 *
	 * @param mixed $value 值。
	 * @return mixed
	 */
	protected static function ksort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = self::is_list( $value );
		$result  = array();

		foreach ( $value as $key => $item ) {
			$result[ $key ] = self::ksort_recursive( $item );
		}

		if ( ! $is_list ) {
			ksort( $result );
		}

		return $result;
	}

	/**
	 * array_is_list() 的 PHP 7.4 兼容实现。
	 *
	 * @param array $arr 数组。
	 * @return bool
	 */
	public static function is_list( array $arr ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}

		if ( array() === $arr ) {
			return true;
		}

		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * 计算签名。
	 *
	 * @param string $app_id    App-ID。
	 * @param string $timestamp Unix 秒级时间戳。
	 * @param string $nonce     随机串。
	 * @param array  $body      请求体（含或不含 sign 均可）。
	 * @param string $api_key   API Key。
	 * @return string 小写十六进制签名。
	 */
	public static function sign( $app_id, $timestamp, $nonce, array $body, $api_key ) {
		$string_to_sign = $app_id . "\n" . $timestamp . "\n" . $nonce . "\n" . self::canonicalize( $body );

		return hash_hmac( 'sha256', $string_to_sign, $api_key );
	}

	/**
	 * 校验签名（用于 webhook 验签）。
	 *
	 * @param string $app_id    Header 里的 App-ID。
	 * @param string $timestamp Header 里的 Timestamp。
	 * @param string $nonce     Header 里的 X-Nonce。
	 * @param array  $body      解析后的请求体（含 sign）。
	 * @param string $api_key   API Key。
	 * @return bool
	 */
	public static function verify( $app_id, $timestamp, $nonce, array $body, $api_key ) {
		if ( '' === (string) $app_id || '' === (string) $timestamp || '' === (string) $nonce || '' === (string) $api_key ) {
			return false;
		}

		$received = isset( $body['sign'] ) ? (string) $body['sign'] : '';

		if ( '' === $received ) {
			return false;
		}

		$expected = self::sign( $app_id, $timestamp, $nonce, $body, $api_key );

		return hash_equals( $expected, $received );
	}

	/**
	 * 生成随机 nonce。
	 *
	 * @return string
	 */
	public static function nonce() {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( Exception $e ) {
			return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
		}
	}
}
