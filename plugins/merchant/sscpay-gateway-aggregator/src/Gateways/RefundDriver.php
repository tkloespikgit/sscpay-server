<?php

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\GatewayFactory;

if (!defined('ABSPATH')) {
    exit;
}

/** Adapts a verified PSP notification or an authenticated, order-scoped query. */
final class RefundDriver
{
    public static function supports(string $method, string $rawBody): bool
    {
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            return false;
        }
        switch ($method) {
            case 'stripe':
                return in_array($event['type'] ?? '', ['refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated', 'charge.refunded'], true);
            case 'paypal':
            case 'paypal_js':
            case 'paypal_rest':
                return in_array($event['event_type'] ?? '', ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.REFUND.PENDING', 'PAYMENT.REFUND.FAILED'], true);
            case 'airwallex':
                return in_array($event['name'] ?? '', ['refund.received', 'refund.accepted', 'refund.settled', 'refund.succeeded', 'refund.failed'], true);
            case 'antom':
                return ($event['notifyType'] ?? '') === 'REFUND_RESULT' || ($event['paymentStatus'] ?? '') === 'REFUNDED';
        }
        return false;
    }

    public static function references(string $method, string $rawBody): array
    {
        if (!self::supports($method, $rawBody)) {
            return [];
        }
        return self::gateway($method, [])->refundReferences(RefundEvidence::decode($rawBody));
    }

    /** The caller must verify the original bytes with the selected account before calling this method. */
    public static function read(string $method, string $rawBody, array $headers, array $config, ?array $transaction): array
    {
        if (!self::supports($method, $rawBody)) {
            return RefundEvidence::result('ignored');
        }
        return self::gateway($method, $config)->readRefund(RefundEvidence::decode($rawBody), $transaction);
    }

    public static function query(string $method, array $config, array $transaction): array
    {
        return self::gateway($method, $config)->queryRefunds($transaction);
    }

    private static function gateway(string $method, array $config)
    {
        return GatewayFactory::create($method === 'paypal' ? 'paypal_rest' : $method, $config);
    }
}
