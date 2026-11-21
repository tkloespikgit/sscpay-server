<?php
/**
 * Plugin Name:       SSCPay Payment - PayPal
 * Update URI:        false
 * Description:       通过 SSCPay 订单系统收款的 WooCommerce 支付网关（PayPal 渠道）。支持多币种、沙箱模式、异步回调验签、查单兜底、支付方式名称/描述/Logo 自定义，以及 API v2 组合付款与 SSCPay 在线更新。
 * Version:           1.2.0
 * Author:            SSCPay
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sscpay-payment-paypal
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * @package SSCPay_Paypal
 */

defined( 'ABSPATH' ) || exit;

define( 'SSCPAY_PAYPAL_VERSION', '1.2.0' );
define( 'SSCPAY_PAYPAL_FILE', __FILE__ );
define( 'SSCPAY_PAYPAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'SSCPAY_PAYPAL_URL', plugin_dir_url( __FILE__ ) );
define( 'SSCPAY_PAYPAL_BASENAME', plugin_basename( __FILE__ ) );
define( 'SSCPAY_PAYPAL_SLUG', 'sscpay-payment-paypal' );
define( 'SSCPAY_PAYPAL_GATEWAY_ID', 'sscpay_paypal' );

/**
 * 插件主控制器。
 */
final class SSCPay_Paypal_Plugin {

	/**
	 * 单例。
	 *
	 * @var SSCPay_Paypal_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * 更新器实例。
	 *
	 * @var SSCPay_Paypal_Updater|null
	 */
	protected $updater = null;

	/**
	 * 取得单例。
	 *
	 * @return SSCPay_Paypal_Plugin
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
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'bootstrap' ), 11 );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		register_activation_hook( __FILE__, array( __CLASS__, 'on_activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'on_deactivate' ) );
	}

	/**
	 * 声明 HPOS（高性能订单存储）与区块结账兼容。
	 *
	 * @return void
	 */
	public function declare_compatibility() {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}

	/**
	 * 载入语言包。
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'sscpay-payment-paypal', false, dirname( SSCPAY_PAYPAL_BASENAME ) . '/languages' );
	}

	/**
	 * 载入所有模块。
	 *
	 * @return void
	 */
	public function bootstrap() {
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-logger.php';
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-updater.php';
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-ad-tracking.php';

		// 广告点击 ID 采集要在所有页面生效（用户可能从落地页而非结账页带 gclid 进站），
		// 不依赖 WooCommerce 是否可用，所以放在下面的 WooCommerce 检测之前。
		SSCPay_Paypal_Ad_Tracking::init();

		$this->init_updater();

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );

			return;
		}

		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-signature.php';
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-api-client.php';
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-order-helper.php';
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-order-state.php';
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-gateway.php';
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-webhook.php';
		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-order-sync.php';

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . SSCPAY_PAYPAL_BASENAME, array( $this, 'plugin_action_links' ) );

		SSCPay_Paypal_Webhook::instance();
		SSCPay_Paypal_Order_Sync::instance();

		// WooCommerce 在 plugins_loaded 优先级 10 就触发了 woocommerce_blocks_loaded，
		// 而本插件的 bootstrap 挂在优先级 11，直接 add_action 会永远等不到这个钩子，
		// 区块结账因此拿不到支付方式数据。已经触发过就直接注册。
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_blocks_support();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );
		}
	}

	/**
	 * 初始化 SSCPay 更新器。设置存在网关 options 里，网关未加载也能读。
	 *
	 * @return void
	 */
	protected function init_updater() {
		$settings = (array) get_option( 'woocommerce_' . SSCPAY_PAYPAL_GATEWAY_ID . '_settings', array() );
		$base = trim( (string) ( $settings['update_base_url'] ?? '' ) );
		if ( '' === $base ) {
			$base = trim( (string) ( $settings['live_base_url'] ?? '' ) );
		}
		if ( '' === $base ) {
			$base = trim( (string) ( $settings['sandbox_base_url'] ?? '' ) );
		}
		if ( defined( 'SSCPAY_PAYPAL_UPDATE_BASE_URL' ) ) {
			$base = (string) SSCPAY_PAYPAL_UPDATE_BASE_URL;
		} elseif ( defined( 'SSCPAY_UPDATE_BASE_URL' ) ) {
			$base = (string) SSCPAY_UPDATE_BASE_URL;
		}
		if ( '' === $base ) {
			return;
		}
		$this->updater = new SSCPay_Paypal_Updater( array(
			'base_url' => $base, 'slug' => SSCPAY_PAYPAL_SLUG,
			'basename' => SSCPAY_PAYPAL_BASENAME, 'version' => SSCPAY_PAYPAL_VERSION,
		) );
	}

	/**
	 * 注册网关。
	 *
	 * @param array $gateways 已注册网关。
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'SSCPay_Paypal_Gateway';

		return $gateways;
	}

	/**
	 * 注册区块结账支持。
	 *
	 * @return void
	 */
	public function register_blocks_support() {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once SSCPAY_PAYPAL_PATH . 'includes/class-sscpay-paypal-blocks-support.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new SSCPay_Paypal_Blocks_Support() );
			}
		);
	}

	/**
	 * 插件列表页操作链接。
	 *
	 * @param array $links 链接。
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . SSCPAY_PAYPAL_GATEWAY_ID );

		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( '设置', 'sscpay-payment-paypal' ) . '</a>'
		);

		return $links;
	}

	/**
	 * 缺少 WooCommerce 时的提示。
	 *
	 * @return void
	 */
	public function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SSCPay Payment - PayPal 需要先安装并启用 WooCommerce 才能工作。', 'sscpay-payment-paypal' );
		echo '</p></div>';
	}

	/**
	 * 启用插件。
	 *
	 * @return void
	 */
	public static function on_activate() {
		if ( ! wp_next_scheduled( 'sscpay_paypal_reconcile_orders' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'sscpay_paypal_reconcile_orders' );
		}
	}

	/**
	 * 停用插件。
	 *
	 * @return void
	 */
	public static function on_deactivate() {
		$timestamp = wp_next_scheduled( 'sscpay_paypal_reconcile_orders' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'sscpay_paypal_reconcile_orders' );
		}

		delete_site_transient( 'update_plugins' );
	}
}

SSCPay_Paypal_Plugin::instance();
