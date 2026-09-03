<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway\Facades;

use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Support\Facades\Facade;

/**
 * 可选的门面写法，纯粹图方便；更推荐用构造函数注入 PaymentGatewayService，
 * 尤其是在需要写单测的地方（注入更容易mock）。
 *
 * @method static array registerGatewayConfig(string $configKey, string $paymentMethod, array $config)
 * @method static array createPayment(array $payload)
 * @method static array syncTracking(array $payload)
 * @method static array checkHealth(string $paymentMethod, ?int $gatewayConfigId = null, ?array $gatewayConfig = null)
 * @method static array orderLogs(string $sOrderId)
 * @method static array orderQuery(string $sOrderId)
 * @method static bool verifyWebhookSignature(string $rawBody, ?string $signatureHeader)
 *
 * @see PaymentGatewayService
 */
class PaymentGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentGatewayService::class;
    }
}
