<?php
/**
 * 支付网关抽象基类 —— 职责收窄版（对应评审第8点 / 设计文档v2第十节）。
 *
 * 明确不属于这里的职责：创建WordPress/WooCommerce订单、交易记录落库、
 * 状态迁移合法性校验、Webhook去重、金额一致性校验——这些是所有网关共用的，
 * 已经分别放进 OrderManager / TransactionManager / TransactionStateMachine /
 * WebhookRouter，不要在子类里重新实现一遍。
 *
 * 子类只需要回答"这个网关自己特有的部分该怎么做"。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

abstract class PaymentBase
{
    /** @var array 解密后的网关配置，由GatewayFactory注入 */
    protected $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * 在三方平台发起一次支付会话。不负责创建WP订单/落库交易，
     * transaction参数是已经由TransactionManager创建好的记录。
     *
     * @param array $transaction wp_payment_transactions 的一行（数组）；payment_attempt_key
     *                           是调用方已持久化的本次会话创建幂等键
     * @return array 网关会话数据，例如：
     *               ['redirect_url' => '...']                     // 跳转类网关
     *               ['session_id' => '...', 'publishable_key'=>..] // 需要前端SDK的网关
     */
    abstract public function preparePayment(array $transaction);

    /**
     * 渲染支付页面的网关特有部分（比如"去支付"按钮、PayPal按钮容器、Antom表单）。
     * 页面的通用外壳（订单状态提示、页面样式等）由 Frontend/PaymentPage.php 负责，
     * 这里只返回网关相关的HTML片段。
     *
     * @param array $transaction
     * @param array $sessionData preparePayment()的返回值
     * @return string HTML
     */
    abstract public function renderPaymentPage(array $transaction, array $sessionData);

    /**
     * 在验签之前，从原始payload中提取外部交易标识（payment_intent/order id等），
     * 用于反查是哪笔交易、进而取出该笔交易对应的密钥。
     *
     * 注意：此时签名还没验证，返回值只能用来"查表定位"，不能相信它的真实性——
     * 后续 verifyCallbackSignature() 通过之后，业务上才能信任这个payload。
     *
     * @param string $rawBody
     * @param array $headers
     * @return string|null 找不到则返回null
     */
    abstract public function extractExternalRef($rawBody, array $headers);

    /**
     * 验证Webhook签名。只做验签这一件事，不做状态变更、不做业务判断——
     * 那些是WebhookRouter的职责。
     *
     * @param string $rawBody 原始请求体字节，不能是反序列化后又重新json_encode的结果
     * @param array $headers 全部请求头（小写key）
     * @param array $config 该笔交易对应的、已解密的网关配置（含webhook secret）
     * @return bool
     */
    abstract public function verifyCallbackSignature($rawBody, array $headers, array $config);

    /**
     * 把验签通过的原始payload解析成标准化事件结构，供WebhookRouter统一处理。
     *
     * @param string $rawBody
     * @param array $headers
     * @return array{
     *     event_id: string,
     *     external_ref: string,
     *     event_status: string,   // 'paid' | 'failed' | 'refunded' | 'ignored'
     *     amount_minor: int|null,
     *     currency: string|null,
     *     transaction_id: string|null
     * }
     */
    abstract public function parseCallbackEvent($rawBody, array $headers);

    /**
     * 健康检测：调用三方平台的账户验证类接口。
     *
     * @param array $config 解密后的网关配置
     * @return array{status:string,message:string,account_id:?string,account_name:?string}
     */
    abstract public function healthCheck(array $config);

    /**
     * 主动向三方平台核实这笔交易当前的真实状态（对应 /v1/order-query 每次调用都要
     * 实时核实的需求，见 Core/OrderStatusReconciler）。与 parseCallbackEvent() 不同，
     * 这里没有一个现成的webhook payload可解析，需要网关自己发起一次查询类API调用
     * （PayPal GET Order / Stripe Retrieve Checkout Session / Airwallex Retrieve
     * Payment Intent / Antom inquiryPayment）。
     *
     * @param array $transaction wp_payment_transactions 的一行，payment_intent 字段
     *                           是preparePayment()阶段落库的三方会话/订单标识
     *                           （见TransactionManager::setExternalRef()）。
     * @return array{status:?string,transaction_id:?string,amount_minor:?int,currency:?string,session_state?:string}
     *               status 取值为 TransactionStateMachine 的 PAID/FAILED/CANCELLED/REFUNDED
     *               之一，表示三方认定的终态；null 表示三方侧仍在处理中/状态未知，
     *               调用方不应据此变更本地状态。
     * @throws \RuntimeException 查询本身失败（网络错误/鉴权失败/三方返回异常），
     *                           调用方应捕获后降级使用本地缓存状态。
     */
    abstract public function queryOrderStatus(array $transaction);

    /**
     * 只读检查平台会话；未知状态不能作为创建新会话的依据。
     * reusable表示仍可继续，processing表示扣款进行中，closed表示平台已关闭。
     * requires_approval表示仅能恢复原PayPal Order的买家操作，不能轮换为新Order。
     * 具体会话期限和是否轮换由协调层决定，不能只凭本方法的status为空推断过期。
     *
     * @return array{status:?string,transaction_id:?string,amount_minor:?int,currency:?string,session_state:string}
     */
    public function inspectPaymentSession(array $transaction)
    {
        $result = array_merge([
            'status' => null,
            'transaction_id' => null,
            'amount_minor' => null,
            'currency' => null,
            'session_state' => 'unknown',
        ], $this->queryOrderStatus($transaction));
        if (!in_array($result['session_state'], ['reusable', 'requires_approval', 'processing', 'closed', 'unknown'], true)) {
            $result['session_state'] = 'unknown';
        }
        return $result;
    }

    /** 平台明确停止旧会话后才返回true；默认不假设平台支持取消未付款会话。 */
    public function retirePaymentSession(array $transaction): bool
    {
        return false;
    }

    /**
     * 把物流信息同步给三方支付渠道（对应 /v1/sync-tracking 的 need_sync_to_remote=Y
     * 分支，见 API/Controllers/SyncTrackingController）。不是所有网关都提供这类API——
     * 没有的网关（目前是Stripe/Airwallex/Antom）直接返回 supported=false，
     * 由调用方据此如实回复S系统"这个渠道不支持"，而不是假装同步成功。
     *
     * @param array $transaction wp_payment_transactions 的一行
     * @param array $trackingData /v1/sync-tracking 的请求体：tracking_number/
     *                            carrier_code/is_other_carrier(Y/N)/tracking_url/shipped_at
     * @return array{supported:bool,success:bool,message:string}
     * @throws \RuntimeException 仅用于调用方决定按失败处理的异常场景；网关实现也可以
     *                           直接在返回值里用 success=false 表达失败，两种方式都允许，
     *                           调用方两边都会兜底。
     */
    abstract public function syncTrackingToRemote(array $transaction, array $trackingData);

    /** 环境取值：只有这两个是合法的（见 resolveMode() / requireMode()）。 */
    const MODE_LIVE = 'live';
    const MODE_SANDBOX = 'sandbox';

    /**
     * 解析配置里的"沙箱还是生产"。所有网关都必须通过这个方法取环境，
     * 不要再各自写 $config['mode'] ?? 'sandbox'。
     *
     * 历史包袱：S系统注册配置时字段名一直用的是 env，而插件内部一直读 mode，
     * 两边从来没对齐过。旧写法 `$config['mode'] ?? 'sandbox'` 读不到时会静默
     * 降级到沙箱，于是 env=live 的生产配置被当成沙箱，拿生产凭据去请求 sandbox
     * 域名，PayPal 直接回 invalid_client / Client Authentication failed——这个
     * 报错完全指不到真正的原因。这里同时认 mode 和 env（mode 优先），已经用
     * env 入库的存量配置不需要重新注册就能恢复正常。
     *
     * 只有显式的 'live' 才判为生产。非法取值在注册时已被 requireMode() 拦掉，
     * 这里保守落到沙箱，是为了不让脏数据把沙箱配置误判成生产（那会真的扣钱）。
     *
     * @param array $config 已解密的网关配置
     * @return string self::MODE_LIVE | self::MODE_SANDBOX
     */
    public static function resolveMode(array $config)
    {
        $mode = $config['mode'] ?? ($config['env'] ?? '');
        $mode = strtolower(trim((string) $mode));

        return $mode === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_SANDBOX;
    }

    /**
     * 供各网关 validateConfig() 复用：环境字段必须显式给出且取值合法。
     *
     * 为什么要在注册配置的那一刻就硬性拦下来，而不是继续依赖 resolveMode()
     * 的默认值：环境配错不报任何错，只会安静地跑到沙箱去——钱收不到，但表面
     * 一切正常，一直要到三方返回 invalid_client 才暴露。宁可让
     * /v1/gateway-config 当场返回 10006。
     *
     * 注意 mode/env 两个字段名都接受，否则 S 系统现有的注册请求会当场失败。
     *
     * @param array $config
     * @return string[] 合法时返回空数组，否则返回用于报错的字段名
     */
    public static function requireMode(array $config)
    {
        $raw = $config['mode'] ?? ($config['env'] ?? null);

        if ($raw === null || $raw === '') {
            return ['mode'];
        }

        if (!in_array(strtolower(trim((string) $raw)), [self::MODE_LIVE, self::MODE_SANDBOX], true)) {
            return ['mode（取值只能是 live 或 sandbox，收到的是：' . (string) $raw . '）'];
        }

        return [];
    }

    /**
     * 校验配置是否包含该网关的全部必填字段。
     *
     * @param array $config
     * @return array 缺失的字段名列表，空数组表示校验通过
     */
    abstract public function validateConfig(array $config);

    /**
     * 网关代码，如 'stripe'，供GatewayFactory/日志使用。子类必须覆盖。
     *
     * @return string
     */
    abstract public function code();
}
