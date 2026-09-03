<?php

namespace App\Exceptions;

use Exception;

/**
 * API 下单显式指定 payment_method_key 时，商户传入的 notify_url / return_url /
 * cancel_url 与该支付方式绑定的电商网站域名（payment_methods.domain）不一致
 * （或地址缺失）。
 *
 * 指定渠道模式下不再走单笔/日/月限额等风控阈值，作为对价必须保证这笔交易确实
 * 发生在该渠道绑定的站点上，否则拒单。API 层应捕获后返回业务错误码
 * PAYMENT_METHOD_DOMAIN_MISMATCH。
 */
class PaymentMethodDomainMismatchException extends Exception
{
    public function __construct(
        public readonly string $field,
        public readonly string $url,
        public readonly string $paymentMethodKey,
        public readonly string $boundDomain,
    ) {
        // 地址缺失时给个可读占位，方便商户看错误信息就知道是哪个字段没传
        // （readonly 提升属性在构造体执行前已赋值，这里只影响消息文案）。
        $shownUrl = $url === '' ? '(empty)' : $url;

        parent::__construct("Callback URL does not match the payment method's bound site domain: {$field}={$shownUrl}, payment_method_key={$paymentMethodKey} is bound to {$boundDomain}");
    }

    public function errorCode(): string
    {
        return 'PAYMENT_METHOD_DOMAIN_MISMATCH';
    }
}
