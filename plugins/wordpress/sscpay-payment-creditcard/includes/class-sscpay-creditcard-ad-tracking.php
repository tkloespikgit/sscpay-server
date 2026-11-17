<?php
/**
 * 广告点击 ID 采集：落地页把 gclid/ttclid/fbclid 写进首方 Cookie，
 * 下单时从 Cookie 里取出来拼成订单系统要求的 ad_params 结构。
 *
 * fbc/fbp 优先读 Meta Pixel 自己维护的 _fbc/_fbp（存在的话最准确），
 * 没有 Pixel 时用 fbclid 按 Meta 官方算法现算一份兜底存到自己的 Cookie 里。
 * gclid/ttclid 没有对应的官方 Cookie，全靠这里自己采集。
 *
 * @package SSCPay_Creditcard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SSCPay_Creditcard_Ad_Tracking
 */
class SSCPay_Creditcard_Ad_Tracking {

	const COOKIE_GCLID  = 'sscpay_gclid';
	const COOKIE_TTCLID = 'sscpay_ttclid';
	const COOKIE_FBC    = 'sscpay_fbc';

	/**
	 * 注册采集钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture_click_ids' ), 20 );
	}

	/**
	 * 落地页采集：URL 里带 gclid/ttclid/fbclid 时写入首方 Cookie。
	 *
	 * 用 init 钩子而不是 template_redirect，保证在任何输出之前执行，setcookie 不会失败。
	 *
	 * @return void
	 */
	public static function capture_click_ids() {
		if ( headers_sent() ) {
			return;
		}

		$ttl = (int) apply_filters( 'sscpay_creditcard_ad_click_id_ttl', 90 * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$gclid = isset( $_GET['gclid'] ) ? sanitize_text_field( wp_unslash( $_GET['gclid'] ) ) : '';

		if ( '' !== $gclid ) {
			self::set_cookie( self::COOKIE_GCLID, $gclid, $ttl );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ttclid = isset( $_GET['ttclid'] ) ? sanitize_text_field( wp_unslash( $_GET['ttclid'] ) ) : '';

		if ( '' !== $ttclid ) {
			self::set_cookie( self::COOKIE_TTCLID, $ttclid, $ttl );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fbclid = isset( $_GET['fbclid'] ) ? sanitize_text_field( wp_unslash( $_GET['fbclid'] ) ) : '';

		if ( '' !== $fbclid ) {
			// 官方拼接规则：fb.<子域名深度>.<毫秒时间戳>.<fbclid>，本站场景固定用 1。
			$fbc = 'fb.1.' . round( microtime( true ) * 1000 ) . '.' . $fbclid;
			self::set_cookie( self::COOKIE_FBC, $fbc, $ttl );
		}
	}

	/**
	 * 写 Cookie（同时写 $_COOKIE，避免同一请求内后续读取拿到旧值）。
	 *
	 * @param string $name  Cookie 名。
	 * @param string $value 值。
	 * @param int    $ttl   有效期（秒）。
	 * @return void
	 */
	protected static function set_cookie( $name, $value, $ttl ) {
		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + max( 0, $ttl ),
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ $name ] = $value;
	}

	/**
	 * 组装 /order/create 需要的 ad_params。三个平台各自独立判断，任何一个缺失
	 * 追踪 ID 都不影响其它平台上报（Google 强依赖 gclid，缺失时按文档直接不传该 key）。
	 *
	 * @return array 没有任何可用追踪信息时返回空数组（调用方应据此跳过 ad_params 字段）。
	 */
	public static function get_ad_params() {
		$ad_params = array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fbc = isset( $_COOKIE['_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) : '';

		if ( '' === $fbc ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$fbc = isset( $_COOKIE[ self::COOKIE_FBC ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_FBC ] ) ) : '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fbp = isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '';

		$meta = array_filter(
			array(
				'fbc' => $fbc,
				'fbp' => $fbp,
			)
		);

		if ( $meta ) {
			$ad_params['meta'] = $meta;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$gclid = isset( $_COOKIE[ self::COOKIE_GCLID ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_GCLID ] ) ) : '';

		if ( '' !== $gclid ) {
			$ad_params['google'] = array( 'gclid' => $gclid );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ttclid = isset( $_COOKIE[ self::COOKIE_TTCLID ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_TTCLID ] ) ) : '';

		if ( '' !== $ttclid ) {
			$ad_params['tiktok'] = array( 'ttclid' => $ttclid );
		}

		/**
		 * 允许站点自定义/覆盖广告追踪参数（例如从别的插件的 Cookie/Session 里取值）。
		 *
		 * @param array $ad_params 已采集的 ad_params。
		 */
		return apply_filters( 'sscpay_creditcard_ad_params', $ad_params );
	}
}
