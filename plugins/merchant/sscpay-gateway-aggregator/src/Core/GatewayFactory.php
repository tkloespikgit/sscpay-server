<?php
/**
 * 根据 payment_method 字符串创建对应的网关实例。
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Gateways\StripeGateway;
use PaymentGatewayAggregator\Gateways\PayPalJsGateway;
use PaymentGatewayAggregator\Gateways\PayPalRestGateway;
use PaymentGatewayAggregator\Gateways\AirwallexGateway;
use PaymentGatewayAggregator\Gateways\AntomGateway;

if (!defined('ABSPATH')) {
    exit;
}

class GatewayFactory
{
    private static $map = [
        'stripe' => StripeGateway::class,
        'paypal_js' => PayPalJsGateway::class,
        'paypal_rest' => PayPalRestGateway::class,
        'airwallex' => AirwallexGateway::class,
        'antom' => AntomGateway::class,
    ];

    /**
     * @param string $paymentMethod
     * @param array $config 解密后的网关配置，直接注入给网关实例
     * @return PaymentBase
     * @throws \RuntimeException 不支持的payment_method
     */
    public static function create($paymentMethod, array $config = [])
    {
        if (!isset(self::$map[$paymentMethod])) {
            throw new \RuntimeException('UNSUPPORTED_GATEWAY');
        }

        $class = self::$map[$paymentMethod];
        return new $class($config);
    }

    public static function isSupported($paymentMethod)
    {
        return isset(self::$map[$paymentMethod]);
    }

    public static function supportedMethods()
    {
        return array_keys(self::$map);
    }
}
