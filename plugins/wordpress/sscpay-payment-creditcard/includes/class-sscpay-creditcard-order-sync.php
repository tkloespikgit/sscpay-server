<?php
/**
 * 定时查单兜底。
 *
 * 订单系统只在订单变为 paid 时推 webhook，failed / cancelled / expired / refunded
 * 等状态都不会通知，而且 webhook 本身也可能丢，所以需要定时主动查一遍。
 *
 * @package SSCPay_Creditcard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SSCPay_Creditcard_Order_Sync
 */
class SSCPay_Creditcard_Order_Sync {

	/**
	 * 单次任务最多处理的订单数，避免拖垮 cron。
	 */
	const BATCH_SIZE = 30;

	/**
	 * 单例。
	 *
	 * @var SSCPay_Creditcard_Order_Sync|null
	 */
	protected static $instance = null;

	/**
	 * 取得单例。
	 *
	 * @return SSCPay_Creditcard_Order_Sync
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
		add_action( 'sscpay_creditcard_reconcile_orders', array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'maybe_render_retry_link' ) );
	}

	/**
	 * 确保定时任务已注册（例如插件更新后）。
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( ! wp_next_scheduled( 'sscpay_creditcard_reconcile_orders' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'sscpay_creditcard_reconcile_orders' );
		}
	}

	/**
	 * 执行对账。
	 *
	 * @return void
	 */
	public function run() {
		$gateway = SSCPay_Creditcard_Webhook::get_gateway();

		if ( ! $gateway || 'yes' !== $gateway->get_option( 'reconcile', 'yes' ) ) {
			return;
		}

		$window = max( 1, (int) $gateway->get_option( 'reconcile_window', 48 ) );

		$orders = wc_get_orders(
			array(
				'limit'          => self::BATCH_SIZE,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'status'         => array( 'pending', 'on-hold' ),
				'payment_method' => SSCPAY_CREDITCARD_GATEWAY_ID,
				'date_created'   => '>' . ( time() - $window * HOUR_IN_SECONDS ),
				'return'         => 'ids',
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		SSCPay_Creditcard_Logger::log( '[对账] 本轮待查订单数：' . count( $orders ), 'info' );

		$webhook = SSCPay_Creditcard_Webhook::instance();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			// 人工核对中的订单不自动改状态。
			if ( 'yes' === $order->get_meta( SSCPay_Creditcard_Order_Helper::META_NEEDS_REVIEW ) ) {
				continue;
			}

			if ( '' === (string) $order->get_meta( SSCPay_Creditcard_Order_Helper::META_MERCHANT_ORDER_NO ) ) {
				continue;
			}

			$webhook->sync_order( $gateway, $order );
		}
	}

	/**
	 * 顾客侧订单详情页：未支付时给一个"重新支付"的入口。
	 *
	 * @param WC_Order $order 订单。
	 * @return void
	 */
	public function maybe_render_retry_link( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( SSCPAY_CREDITCARD_GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		if ( ! $order->has_status( 'pending' ) || ! $order->needs_payment() ) {
			return;
		}

		printf(
			'<p class="sscpay-retry"><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( $order->get_checkout_payment_url() ),
			esc_html__( '重新发起支付', 'sscpay-payment-creditcard' )
		);
	}
}
