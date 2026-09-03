<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 插件接口返回的业务错误（code != 0），或HTTP层面的非2xx响应，
 * 统一包装成这个异常抛出，调用方 catch 这一个类型就够了。
 *
 * 用法：
 *   try {
 *       $result = $paymentGateway->createPayment($payload);
 *   } catch (PaymentGatewayException $e) {
 *       if ($e->pgaCode === 10011) { ... } // 网关配置引用不存在或已吊销
 *       Log::error('创建支付订单失败', ['code' => $e->pgaCode, 'message' => $e->getMessage()]);
 *   }
 */
class PaymentGatewayException extends RuntimeException
{
    /**
     * @param  string  $message  插件返回的message，或HTTP层错误描述
     * @param  int  $pgaCode  插件响应体里的业务code（见API文档"错误码"一节），
     *                        HTTP层面的错误（超时、连接失败等）用 -1 表示，不是插件定义的业务码
     * @param  int  $httpStatus  HTTP状态码，HTTP层错误时可能是0
     * @param  array|null  $response  插件返回的完整响应体（已解码），便于排查问题
     */
    public function __construct(
        string $message,
        public readonly int $pgaCode = -1,
        public readonly int $httpStatus = 0,
        public readonly ?array $response = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isAuthFailure(): bool
    {
        return $this->pgaCode === 10004;
    }

    public function isValidationFailure(): bool
    {
        return $this->pgaCode === 10005;
    }

    /** /order-query 专属：s_order_id 在插件侧查无此单 */
    public function isOrderNotFound(): bool
    {
        return $this->pgaCode === 10001;
    }

    /** 网关配置引用（gateway_config_id）不存在或已被吊销 */
    public function isGatewayConfigMissing(): bool
    {
        return $this->pgaCode === 10011;
    }

    /** 纯网络/超时层面的失败，不是插件业务逻辑拒绝的 */
    public function isTransportFailure(): bool
    {
        return $this->pgaCode === -1;
    }
}
