<?php
/**
 * 区块结账（Checkout Block）支持。
 *
 * WooCommerce 8+ 的区块结账不会自动渲染传统 WC_Payment_Gateway，
 * 必须额外注册一个 payment method type 并提供前端脚本。
 *
 * @package SSCPay_Paypal
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class SSCPay_Paypal_Blocks_Support
 */
final class SSCPay_Paypal_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * 支付方式名（必须与网关 id 一致）。
	 *
	 * @var string
	 */
	protected $name = SSCPAY_PAYPAL_GATEWAY_ID;

	/**
	 * 网关实例。
	 *
	 * @var SSCPay_Paypal_Gateway|null
	 */
	protected $gateway = null;

	/**
	 * 初始化。
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . SSCPAY_PAYPAL_GATEWAY_ID . '_settings', array() );
		$this->settings = is_array( $this->settings ) ? $this->settings : array();
		$this->gateway  = $this->get_gateway();
	}

	/**
	 * 取网关。
	 *
	 * @return SSCPay_Paypal_Gateway|null
	 */
	protected function get_gateway() {
		if ( $this->gateway instanceof SSCPay_Paypal_Gateway ) {
			return $this->gateway;
		}

		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		if ( isset( $gateways[ SSCPAY_PAYPAL_GATEWAY_ID ] ) && $gateways[ SSCPAY_PAYPAL_GATEWAY_ID ] instanceof SSCPay_Paypal_Gateway ) {
			return $gateways[ SSCPAY_PAYPAL_GATEWAY_ID ];
		}

		return class_exists( 'SSCPay_Paypal_Gateway' ) ? new SSCPay_Paypal_Gateway() : null;
	}

	/**
	 * 是否可用。
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->get_gateway();

		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * 注册并返回前端脚本 handle。
	 *
	 * 这里刻意不用 @wordpress/scripts 构建，直接依赖 WooCommerce Blocks 暴露的
	 * 全局对象，省掉一整套构建链，改样式改文案不用重新打包。
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$handle = SSCPAY_PAYPAL_GATEWAY_ID . '-blocks';

		wp_register_script(
			$handle,
			SSCPAY_PAYPAL_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			SSCPAY_PAYPAL_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'sscpay-payment-paypal', SSCPAY_PAYPAL_PATH . 'languages' );
		}

		return array( $handle );
	}

	/**
	 * 传给前端的数据。
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->get_gateway();

		if ( ! $gateway ) {
			return array();
		}

		$description = (string) $gateway->get_description();

		if ( $gateway->is_testmode() ) {
			$description = trim( $description . ' ' . __( '（沙箱测试模式，不会产生真实扣款）', 'sscpay-payment-paypal' ) );
		}

		$logo   = trim( (string) $gateway->get_option( 'logo_url' ) );
		$height = (int) $gateway->get_option( 'logo_height', 24 );

		return array(
			'title'       => (string) $gateway->get_title(),
			'description' => $description,
			'logoUrl'     => $logo,
			'logoHeight'  => $height > 0 ? $height : 24,
			'supports'    => array_filter( $gateway->supports, array( $gateway, 'supports' ) ),
		);
	}
}
