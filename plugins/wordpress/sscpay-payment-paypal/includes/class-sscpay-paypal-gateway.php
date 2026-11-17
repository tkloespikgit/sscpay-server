<?php
/**
 * WooCommerce 支付网关。
 *
 * @package SSCPay_Paypal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SSCPay_Paypal_Gateway
 */
class SSCPay_Paypal_Gateway extends WC_Payment_Gateway {

	/**
	 * 默认支付渠道 method_code。复制成其它渠道插件时改这里。
	 */
	const DEFAULT_PAYMENT_METHOD_KEY = 'paypal';

	/**
	 * 构造函数。
	 */
	public function __construct() {
		$this->id                 = SSCPAY_PAYPAL_GATEWAY_ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'SSCPay - PayPal', 'sscpay-payment-paypal' );
		$this->method_description = __( '通过 SSCPay 订单系统收款。下单后调用 /order/create 拿到支付链接并跳转，支付结果由 webhook 异步通知，并有定时查单兜底。', 'sscpay-payment-paypal' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'PayPal', 'sscpay-payment-paypal' ) );
		$this->description = $this->get_option( 'description', '' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
	}

	/**
	 * 是否沙箱模式。
	 *
	 * @return bool
	 */
	public function is_testmode() {
		return 'yes' === $this->get_option( 'testmode', 'yes' );
	}

	/**
	 * 取当前环境的凭据。
	 *
	 * @return array{base_url:string,app_id:string,api_key:string}
	 */
	public function get_credentials() {
		if ( $this->is_testmode() ) {
			return array(
				'base_url' => (string) $this->get_option( 'sandbox_base_url' ),
				'app_id'   => (string) $this->get_option( 'sandbox_app_id' ),
				'api_key'  => (string) $this->get_option( 'sandbox_api_key' ),
			);
		}

		return array(
			'base_url' => (string) $this->get_option( 'live_base_url' ),
			'app_id'   => (string) $this->get_option( 'live_app_id' ),
			'api_key'  => (string) $this->get_option( 'live_api_key' ),
		);
	}

	/**
	 * 取指定环境的凭据（webhook 验签时按订单当时的环境取）。
	 *
	 * @param bool $testmode 是否沙箱。
	 * @return array{base_url:string,app_id:string,api_key:string}
	 */
	public function get_credentials_for( $testmode ) {
		if ( $testmode ) {
			return array(
				'base_url' => (string) $this->get_option( 'sandbox_base_url' ),
				'app_id'   => (string) $this->get_option( 'sandbox_app_id' ),
				'api_key'  => (string) $this->get_option( 'sandbox_api_key' ),
			);
		}

		return array(
			'base_url' => (string) $this->get_option( 'live_base_url' ),
			'app_id'   => (string) $this->get_option( 'live_app_id' ),
			'api_key'  => (string) $this->get_option( 'live_api_key' ),
		);
	}

	/**
	 * 取 API 客户端。
	 *
	 * @param bool|null $testmode 指定环境，null 表示按当前设置。
	 * @return SSCPay_Paypal_Api_Client
	 */
	public function get_client( $testmode = null ) {
		$credentials = ( null === $testmode ) ? $this->get_credentials() : $this->get_credentials_for( (bool) $testmode );

		return new SSCPay_Paypal_Api_Client(
			$credentials['base_url'],
			$credentials['app_id'],
			$credentials['api_key'],
			(int) $this->get_option( 'timeout', 30 )
		);
	}

	/**
	 * 商户订单号前缀。
	 *
	 * @return string
	 */
	public function get_order_prefix() {
		$prefix = trim( (string) $this->get_option( 'order_prefix', 'WC' ) );

		return SSCPay_Paypal_Order_Helper::truncate( $prefix, 16 );
	}

	/**
	 * 网关是否可用。
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! $this->get_client()->is_configured() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * 结账页 Logo。
	 *
	 * @return string
	 */
	public function get_icon() {
		$logo = trim( (string) $this->get_option( 'logo_url' ) );

		if ( '' === $logo ) {
			return apply_filters( 'woocommerce_gateway_icon', '', $this->id );
		}

		$height = (int) $this->get_option( 'logo_height', 24 );
		$height = $height > 0 ? $height : 24;

		$icon = sprintf(
			'<img src="%1$s" alt="%2$s" style="max-height:%3$dpx;width:auto;vertical-align:middle;" />',
			esc_url( $logo ),
			esc_attr( $this->get_title() ),
			$height
		);

		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * 后台设置字段。
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$notify_url = $this->get_notify_url();
		$return_url = $this->get_return_endpoint_url();
		$host       = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( '启用/停用', 'sscpay-payment-paypal' ),
				'type'    => 'checkbox',
				'label'   => __( '在结账页启用该支付方式', 'sscpay-payment-paypal' ),
				'default' => 'no',
			),

			'display_section'    => array(
				'title'       => __( '前台展示', 'sscpay-payment-paypal' ),
				'type'        => 'title',
				'description' => __( '结账页上顾客看到的名称、说明和图标，全部可自定义。', 'sscpay-payment-paypal' ),
			),
			'title'              => array(
				'title'       => __( '支付方式名称', 'sscpay-payment-paypal' ),
				'type'        => 'text',
				'description' => __( '结账页显示的名称。', 'sscpay-payment-paypal' ),
				'default'     => __( 'PayPal', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __( '支付方式描述', 'sscpay-payment-paypal' ),
				'type'        => 'textarea',
				'description' => __( '名称下方的说明文字，支持简单 HTML。', 'sscpay-payment-paypal' ),
				'default'     => __( '将跳转到安全收银台完成支付。', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
			),
			'logo_url'           => array(
				'title'       => __( '支付方式 Logo', 'sscpay-payment-paypal' ),
				'type'        => 'image_upload',
				'description' => __( '从媒体库选择或上传一张图片，显示在支付方式名称旁边。建议高度 48px 以内的 PNG/SVG。', 'sscpay-payment-paypal' ),
				'default'     => '',
			),
			'logo_height'        => array(
				'title'             => __( 'Logo 显示高度（px）', 'sscpay-payment-paypal' ),
				'type'              => 'number',
				'default'           => '24',
				'custom_attributes' => array(
					'min'  => '12',
					'max'  => '80',
					'step' => '1',
				),
			),

			'api_section'        => array(
				'title'       => __( '订单系统接口', 'sscpay-payment-paypal' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1: notify_url, 2: return_url, 3: 站点 host */
					__( '把下面两个地址交给订单系统管理员，加入该商户的回调域名白名单（精确 host 匹配，不做大小写/www./端口归一化，本站 host 为 <code>%3$s</code>）：<br>异步通知 notify_url：<br><code>%1$s</code><br>同步跳转 return_url：<br><code>%2$s</code>', 'sscpay-payment-paypal' ),
					esc_html( $notify_url ),
					esc_html( $return_url ),
					esc_html( (string) $host )
				),
			),
			'testmode'           => array(
				'title'       => __( '沙箱模式', 'sscpay-payment-paypal' ),
				'type'        => 'checkbox',
				'label'       => __( '使用沙箱环境的地址与凭据', 'sscpay-payment-paypal' ),
				'description' => __( '勾选后走下方"沙箱环境"的一套配置，结账页会显示测试模式提示。', 'sscpay-payment-paypal' ),
				'default'     => 'yes',
			),
			'live_base_url'      => array(
				'title'       => __( '生产环境 API Base URL', 'sscpay-payment-paypal' ),
				'type'        => 'text',
				'placeholder' => 'https://order.example.com',
				'description' => __( '不带尾部斜杠，插件会自动拼 /api/order/create 等路径。', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'live_app_id'        => array(
				'title'   => __( '生产环境 App-ID', 'sscpay-payment-paypal' ),
				'type'    => 'text',
				'default' => '',
			),
			'live_api_key'       => array(
				'title'       => __( '生产环境 API Key', 'sscpay-payment-paypal' ),
				'type'        => 'password',
				'description' => __( '只在订单系统创建应用时展示一次，务必妥善保存。', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'sandbox_base_url'   => array(
				'title'       => __( '沙箱环境 API Base URL', 'sscpay-payment-paypal' ),
				'type'        => 'text',
				'placeholder' => 'https://sandbox-order.example.com',
				'default'     => '',
			),
			'sandbox_app_id'     => array(
				'title'   => __( '沙箱环境 App-ID', 'sscpay-payment-paypal' ),
				'type'    => 'text',
				'default' => '',
			),
			'sandbox_api_key'    => array(
				'title'   => __( '沙箱环境 API Key', 'sscpay-payment-paypal' ),
				'type'    => 'password',
				'default' => '',
			),

			'routing_section'    => array(
				'title' => __( '下单参数', 'sscpay-payment-paypal' ),
				'type'  => 'title',
			),
			'group_key'          => array(
				'title'       => __( '支付组 group_key', 'sscpay-payment-paypal' ),
				'type'        => 'text',
				'description' => __( '必填。订单系统后台"支付组"里配置的标识，即使指定了支付方式也仍然必填。', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
				'default'     => 'group_default',
			),
			'payment_method_key' => array(
				'title'       => __( '指定支付渠道 payment_method_key', 'sscpay-payment-paypal' ),
				'type'        => 'text',
				'description' => __( '订单系统后台"支付方式"的 method_code。填了会跳过支付组路由与限额风控直接走该渠道，代价是三个回跳地址的域名必须与该渠道绑定站点一致。留空则由支付组路由决定。', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
				'default'     => self::DEFAULT_PAYMENT_METHOD_KEY,
			),
			'platform'           => array(
				'title'       => __( '平台标识 platform', 'sscpay-payment-paypal' ),
				'type'        => 'text',
				'description' => __( '需在订单系统 order.platforms 配置的枚举内，不在列表内会直接拒单。', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
				'default'     => 'wordpress',
			),
			'order_prefix'       => array(
				'title'       => __( '商户订单号前缀', 'sscpay-payment-paypal' ),
				'type'        => 'text',
				'description' => __( '商户订单号 = 前缀 + WooCommerce 订单 ID（重复支付时追加 -2、-3）。多个站点共用同一个商户账号时，务必设置成不同前缀，否则订单号会撞车。', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
				'default'     => 'WC',
			),

			'behaviour_section'   => array(
				'title' => __( '订单行为', 'sscpay-payment-paypal' ),
				'type'  => 'title',
			),
			'paid_order_status'   => array(
				'title'       => __( '收到支付成功回调后的订单状态', 'sscpay-payment-paypal' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( '默认交给 WooCommerce 按商品类型自行判定（实物货 → 处理中，虚拟可下载 → 已完成）。', 'sscpay-payment-paypal' ),
				'desc_tip'    => true,
				'default'     => 'default',
				'options'     => array(
					'default'    => __( '由 WooCommerce 默认判定', 'sscpay-payment-paypal' ),
					'processing' => __( '处理中 processing', 'sscpay-payment-paypal' ),
					'completed'  => __( '已完成 completed', 'sscpay-payment-paypal' ),
				),
			),
			'reconcile'           => array(
				'title'       => __( '定时查单兜底', 'sscpay-payment-paypal' ),
				'type'        => 'checkbox',
				'label'       => __( '每小时主动查询未完成订单的最终状态', 'sscpay-payment-paypal' ),
				'description' => __( '订单系统只在订单变为 paid 时推 webhook，failed/cancelled/expired/refunded 等状态不会通知，需要靠查单感知。强烈建议开启。', 'sscpay-payment-paypal' ),
				'default'     => 'yes',
			),
			'reconcile_window'    => array(
				'title'             => __( '查单回溯窗口（小时）', 'sscpay-payment-paypal' ),
				'type'              => 'number',
				'description'       => __( '只查这个时间范围内创建的待支付订单。', 'sscpay-payment-paypal' ),
				'desc_tip'          => true,
				'default'           => '48',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '720',
					'step' => '1',
				),
			),
			'timeout'             => array(
				'title'             => __( '接口超时（秒）', 'sscpay-payment-paypal' ),
				'type'              => 'number',
				'default'           => '30',
				'custom_attributes' => array(
					'min'  => '5',
					'max'  => '120',
					'step' => '1',
				),
			),
			'debug'               => array(
				'title'       => __( '调试日志', 'sscpay-payment-paypal' ),
				'type'        => 'checkbox',
				'label'       => __( '记录请求与回调报文', 'sscpay-payment-paypal' ),
				'description' => sprintf(
					/* translators: 1: 日志页面链接, 2: 日志来源标识 */
					__( '日志位于 %1$s，来源选 <code>%2$s</code>。签名对不上时先看这里。API Key 等敏感字段会自动脱敏。', 'sscpay-payment-paypal' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '" target="_blank">WooCommerce &rsaquo; 状态 &rsaquo; 日志</a>',
					esc_html( SSCPAY_PAYPAL_GATEWAY_ID )
				),
				'default'     => 'yes',
			),

			'shipment_callback' => array(
				'title' => __( '物流回传接收地址', 'sscpay-payment-paypal' ),
				'type' => 'title',
				'description' => esc_url( WC()->api_request_url( 'sscpay_shipment' ) ),
			),
			'update_section' => array(
				'title' => __( '插件在线更新', 'sscpay-payment-paypal' ),
				'type' => 'title',
				'description' => __( '从订单系统检查稳定版本，无需 GitHub Token。默认使用生产 API 地址，未填写时使用沙箱地址。', 'sscpay-payment-paypal' ),
			),
			'update_base_url' => array(
				'title' => __( '插件更新服务地址', 'sscpay-payment-paypal' ),
				'type' => 'text',
				'description' => __( '可选，填写 HTTPS 根地址，不带 /plugins。', 'sscpay-payment-paypal' ),
				'default' => '',
			),
		);
	}

	/**
	 * 自定义字段类型：图片上传。
	 *
	 * @param string $key  字段 key。
	 * @param array  $data 字段配置。
	 * @return string
	 */
	public function generate_image_upload_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'description' => '',
			'desc_tip'    => false,
			'default'     => '',
		);
		$data      = wp_parse_args( $data, $defaults );
		$value     = (string) $this->get_option( $key );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
				<?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</th>
			<td class="forminp">
				<fieldset class="sscpay-image-upload">
					<input type="url"
						class="input-text regular-input sscpay-image-upload__input"
						id="<?php echo esc_attr( $field_key ); ?>"
						name="<?php echo esc_attr( $field_key ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						placeholder="https://" />
					<button type="button" class="button sscpay-image-upload__select">
						<?php esc_html_e( '选择图片', 'sscpay-payment-paypal' ); ?>
					</button>
					<button type="button" class="button sscpay-image-upload__remove"<?php echo '' === $value ? ' style="display:none;"' : ''; ?>>
						<?php esc_html_e( '移除', 'sscpay-payment-paypal' ); ?>
					</button>
					<div class="sscpay-image-upload__preview">
						<?php if ( '' !== $value ) : ?>
							<img src="<?php echo esc_url( $value ); ?>" alt="" />
						<?php endif; ?>
					</div>
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * 校验图片上传字段。
	 *
	 * @param string $key   字段 key。
	 * @param string $value 提交值。
	 * @return string
	 */
	public function validate_image_upload_field( $key, $value ) {
		$value = trim( (string) $value );

		return '' === $value ? '' : esc_url_raw( $value );
	}

	/**
	 * 后台资源。
	 *
	 * @param string $hook 当前页面。
	 * @return void
	 */
	public function admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $this->id !== $section ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'sscpay-paypal-admin',
			SSCPAY_PAYPAL_URL . 'assets/css/admin.css',
			array(),
			SSCPAY_PAYPAL_VERSION
		);

		wp_enqueue_script(
			'sscpay-paypal-admin',
			SSCPAY_PAYPAL_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			SSCPAY_PAYPAL_VERSION,
			true
		);

		wp_localize_script(
			'sscpay-paypal-admin',
			'sscpayPaypalAdmin',
			array(
				'frameTitle'  => __( '选择支付方式 Logo', 'sscpay-payment-paypal' ),
				'frameButton' => __( '使用这张图片', 'sscpay-payment-paypal' ),
			)
		);
	}

	/**
	 * 保存设置后重置日志开关缓存并清掉更新缓存。
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		SSCPay_Paypal_Logger::reset();
		delete_site_transient( 'update_plugins' );

		return $saved;
	}

	/**
	 * 结账页描述（沙箱模式加提示）。
	 *
	 * @return void
	 */
	public function payment_fields() {
		$description = $this->get_description();

		if ( $this->is_testmode() ) {
			$description = trim(
				$description . ' ' . __( '（沙箱测试模式，不会产生真实扣款）', 'sscpay-payment-paypal' )
			);
		}

		if ( '' !== $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}
	}

	/**
	 * 异步通知地址。
	 *
	 * @return string
	 */
	public function get_notify_url() {
		return WC()->api_request_url( $this->id . '_notify' );
	}

	/**
	 * 同步跳转地址（不带订单参数的基础地址，用于在设置页展示 host）。
	 *
	 * @return string
	 */
	public function get_return_endpoint_url() {
		return WC()->api_request_url( $this->id . '_return' );
	}

	/**
	 * 处理支付：调用 /order/create 并跳转到支付链接。
	 *
	 * @param int $order_id 订单 ID。
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( '订单不存在，请重新下单。', 'sscpay-payment-paypal' ), 'error' );

			return array( 'result' => 'failure' );
		}

		if ( $order->is_paid() ) {
			return array( 'result' => 'success', 'redirect' => $order->get_checkout_order_received_url() );
		}
		$progress = (array) $order->get_meta( SSCPay_Paypal_Order_State::META_PROGRESS );
		$saved_url = (string) $order->get_meta( SSCPay_Paypal_Order_Helper::META_PAYMENT_URL );
		if ( 'combine' === ( $progress['order_type'] ?? '' ) && '' !== $saved_url ) {
			return array( 'result' => 'success', 'redirect' => $saved_url );
		}

		$client = $this->get_client();

		if ( ! $client->is_configured() ) {
			wc_add_notice( __( '支付方式尚未配置完整，请联系店铺管理员。', 'sscpay-payment-paypal' ), 'error' );
			SSCPay_Paypal_Logger::log( '下单失败：网关凭据未配置完整。订单 #' . $order->get_id(), 'error' );

			return array( 'result' => 'failure' );
		}

		$testmode          = $this->is_testmode();
		$prefix            = $this->get_order_prefix();
		$merchant_order_no = SSCPay_Paypal_Order_Helper::build_merchant_order_no( $order, $prefix );

		$amounts = SSCPay_Paypal_Order_Helper::build_amounts( $order );

		$payload = array(
			'merchant_order_no' => $merchant_order_no,
			'platform'          => (string) $this->get_option( 'platform', 'wordpress' ),
			'currency'          => strtoupper( (string) $order->get_currency() ),
			'group_key'         => (string) $this->get_option( 'group_key' ),
			'subtotal'          => $amounts['subtotal'],
			'shipping_fee'      => $amounts['shipping_fee'],
			'discount'          => $amounts['discount'],
			'tax'               => $amounts['tax'],
			'amount'            => $amounts['amount'],
			'customer'          => SSCPay_Paypal_Order_Helper::build_customer( $order ),
			'shipping_address'  => SSCPay_Paypal_Order_Helper::build_address( $order ),
			'items'             => $amounts['items'],
			'notify_url'        => $this->get_notify_url(),
			'return_url'        => add_query_arg(
				array(
					'order_id'  => $order->get_id(),
					'order_key' => $order->get_order_key(),
				),
				$this->get_return_endpoint_url()
			),
			'cancel_url'        => $order->get_checkout_payment_url( false ),
		);

		$ad_params = SSCPay_Paypal_Ad_Tracking::get_ad_params();

		if ( ! empty( $ad_params ) ) {
			$payload['ad_params'] = $ad_params;
		}

		$method_key = trim( (string) $this->get_option( 'payment_method_key' ) );

		if ( '' !== $method_key ) {
			$payload['payment_method_key'] = $method_key;
		}

		/**
		 * 允许自定义下单报文。
		 *
		 * @param array    $payload 报文。
		 * @param WC_Order $order   订单。
		 */
		$payload = apply_filters( 'sscpay_paypal_create_order_payload', $payload, $order );

		$order->update_meta_data( SSCPay_Paypal_Order_Helper::META_MERCHANT_ORDER_NO, $merchant_order_no );
		$order->update_meta_data( SSCPay_Paypal_Order_Helper::META_TESTMODE, $testmode ? 'yes' : 'no' );
		$order->save();

		$result = $client->create_order( $payload );

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();

			$order->add_order_note(
				sprintf(
					/* translators: %s: 错误信息 */
					__( 'SSCPay 下单失败：%s', 'sscpay-payment-paypal' ),
					$message
				)
			);
			$order->save();

			SSCPay_Paypal_Logger::log( '下单失败 订单 #' . $order->get_id() . ' :: ' . $message, 'error' );

			wc_add_notice(
				sprintf(
					/* translators: %s: 错误信息 */
					__( '发起支付失败：%s', 'sscpay-payment-paypal' ),
					$message
				),
				'error'
			);

			return array( 'result' => 'failure' );
		}

		$pay_url = '';

		// 优先用渠道收银台 pay_url，为空时退回订单系统托管收银页 payment_url。
		if ( ! empty( $result['pay_url'] ) ) {
			$pay_url = (string) $result['pay_url'];
		} elseif ( ! empty( $result['payment_url'] ) ) {
			$pay_url = (string) $result['payment_url'];
		}

		if ( '' === $pay_url || ! wc_is_valid_url( $pay_url ) ) {
			$order->add_order_note( __( 'SSCPay 下单成功但没有返回可用的支付链接。', 'sscpay-payment-paypal' ) );
			$order->save();

			SSCPay_Paypal_Logger::dump( '下单成功但缺少支付链接 订单 #' . $order->get_id(), $result, 'error' );

			wc_add_notice( __( '支付渠道没有返回支付链接，请稍后重试或更换支付方式。', 'sscpay-payment-paypal' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( SSCPay_Paypal_Order_Helper::META_ORDER_NO, isset( $result['order_no'] ) ? (string) $result['order_no'] : '' );
		$order->update_meta_data( SSCPay_Paypal_Order_Helper::META_PAYMENT_URL, $pay_url );
		$order->update_meta_data( SSCPay_Paypal_Order_Helper::META_PAYMENT_METHOD, isset( $result['payment_method'] ) ? (string) $result['payment_method'] : '' );

		if ( isset( $result['converted_currency'], $result['converted_amount'] ) ) {
			$order->update_meta_data(
				SSCPay_Paypal_Order_Helper::META_CONVERTED,
				array(
					'currency'      => (string) $result['converted_currency'],
					'amount'        => (string) $result['converted_amount'],
					'exchange_rate' => isset( $result['exchange_rate'] ) ? (string) $result['exchange_rate'] : '',
				)
			);
		}

		$note = sprintf(
			/* translators: 1: 系统订单号, 2: 锁定渠道, 3: 环境 */
			__( 'SSCPay 下单成功。系统订单号：%1$s，锁定渠道：%2$s，环境：%3$s。已引导顾客跳转支付。', 'sscpay-payment-paypal' ),
			isset( $result['order_no'] ) ? (string) $result['order_no'] : '-',
			isset( $result['payment_method'] ) ? (string) $result['payment_method'] : '-',
			$testmode ? __( '沙箱', 'sscpay-payment-paypal' ) : __( '生产', 'sscpay-payment-paypal' )
		);

		if ( isset( $result['converted_amount'], $result['converted_currency'] ) ) {
			$note .= ' ' . sprintf(
				/* translators: 1: 折算金额, 2: 折算币种, 3: 汇率 */
				__( '折算：%1$s %2$s（汇率 %3$s）。', 'sscpay-payment-paypal' ),
				(string) $result['converted_amount'],
				(string) $result['converted_currency'],
				isset( $result['exchange_rate'] ) ? (string) $result['exchange_rate'] : '-'
			);
		}

		$order->add_order_note( $note );
		$order->save();
		$applied = SSCPay_Paypal_Order_State::apply( $this, $order, $result, 'create' );
		if ( is_wp_error( $applied ) ) {
			SSCPay_Paypal_Logger::log( $applied->get_error_message(), 'warning' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $pay_url,
		);
	}

	/**
	 * 把订单标记为已支付（webhook 与查单共用）。
	 *
	 * @param WC_Order $order    订单。
	 * @param array    $payload  订单系统返回的数据。
	 * @param string   $source   来源标识（webhook / query）。
	 * @return bool 是否发生了状态变更。
	 */
	public function mark_order_paid( WC_Order $order, array $payload, $source = 'webhook' ) {
		// 幂等：已支付过就直接返回。
		if ( 'yes' === $order->get_meta( SSCPay_Paypal_Order_Helper::META_PAID_HANDLED ) || $order->is_paid() ) {
			SSCPay_Paypal_Logger::log( '订单 #' . $order->get_id() . ' 已处理过支付成功，跳过（来源：' . $source . '）。', 'info' );

			return false;
		}

		$order_no = isset( $payload['order_no'] ) ? (string) $payload['order_no'] : (string) $order->get_meta( SSCPay_Paypal_Order_Helper::META_ORDER_NO );

		// 金额与币种核对，不一致时不自动放行，转人工。
		$mismatch = $this->detect_amount_mismatch( $order, $payload );

		if ( $mismatch ) {
			$order->update_meta_data( SSCPay_Paypal_Order_Helper::META_NEEDS_REVIEW, 'yes' );
			$order->add_order_note( $mismatch );
			$order->update_status( 'on-hold', __( 'SSCPay 回调金额与订单不符，已转人工核对。', 'sscpay-payment-paypal' ) );
			$order->save();

			SSCPay_Paypal_Logger::log( '订单 #' . $order->get_id() . ' 金额校验不通过：' . $mismatch, 'error' );

			return true;
		}

		$order->update_meta_data( SSCPay_Paypal_Order_Helper::META_PAID_HANDLED, 'yes' );

		if ( isset( $payload['payment_method'] ) ) {
			$order->update_meta_data( SSCPay_Paypal_Order_Helper::META_PAYMENT_METHOD, (string) $payload['payment_method'] );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: 系统订单号, 2: 金额, 3: 币种, 4: 渠道, 5: 来源 */
				__( 'SSCPay 支付成功。系统订单号：%1$s，金额：%2$s %3$s，渠道：%4$s，来源：%5$s。', 'sscpay-payment-paypal' ),
				$order_no ? $order_no : '-',
				isset( $payload['amount'] ) ? (string) $payload['amount'] : (string) $order->get_total(),
				isset( $payload['currency'] ) ? (string) $payload['currency'] : (string) $order->get_currency(),
				isset( $payload['payment_method'] ) ? (string) $payload['payment_method'] : '-',
				'webhook' === $source ? __( '异步通知', 'sscpay-payment-paypal' ) : __( '主动查单', 'sscpay-payment-paypal' )
			)
		);

		$order->payment_complete( $order_no );

		$forced = (string) $this->get_option( 'paid_order_status', 'default' );

		if ( in_array( $forced, array( 'processing', 'completed' ), true ) && ! $order->has_status( $forced ) ) {
			$order->update_status( $forced, __( '按支付网关设置强制指定状态。', 'sscpay-payment-paypal' ) );
		}

		$order->save();

		return true;
	}

	/**
	 * 金额/币种核对。
	 *
	 * @param WC_Order $order   订单。
	 * @param array    $payload 报文。
	 * @return string 有问题时返回描述，正常返回空串。
	 */
	protected function detect_amount_mismatch( WC_Order $order, array $payload ) {
		if ( SSCPay_Paypal_Order_State::cents( $payload['amount'] ?? null ) !== SSCPay_Paypal_Order_State::cents( (string) $order->get_total() )
			|| ( $payload['currency'] ?? '' ) !== strtoupper( (string) $order->get_currency() ) ) {
			return __( 'SSCPay 金额或币种与商城订单不符，请人工核对。', 'sscpay-payment-paypal' );
		}
		return '';
	}

	/**
	 * 处理非 paid 的终态（查单兜底时用）。
	 *
	 * @param WC_Order $order  订单。
	 * @param string   $status 订单系统状态。
	 * @return bool
	 */
	public function apply_remote_status( WC_Order $order, $status ) {
		$status = strtolower( (string) $status );

		if ( 'paid' === $status || 'shipped' === $status || 'completed' === $status ) {
			return false;
		}

		$map = array(
			'failed'    => 'failed',
			'cancelled' => 'cancelled',
			'expired'   => 'cancelled',
		);

		if ( ! isset( $map[ $status ] ) ) {
			return false;
		}

		if ( $order->is_paid() || $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
			return false;
		}

		$order->update_status(
			$map[ $status ],
			sprintf(
				/* translators: %s: 订单系统状态 */
				__( 'SSCPay 主动查单结果：%s。', 'sscpay-payment-paypal' ),
				$status
			)
		);
		$order->save();

		return true;
	}

	/** @return array|WP_Error 上传物流到普通单或每个真实子单，供商城物流集成调用。 */
	public function sync_shipping( WC_Order $order, array $shipment ) {
		$payloads = SSCPay_Paypal_Order_State::shipping_payloads( $order, $shipment );
		if ( is_wp_error( $payloads ) ) {
			return $payloads;
		}
		$client = $this->get_client( 'yes' === $order->get_meta( SSCPay_Paypal_Order_Helper::META_TESTMODE ) );
		$results = array();
		foreach ( $payloads as $payload ) {
			$result = $client->ship_order( $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$results[] = $result;
		}
		return $results;
	}
}
