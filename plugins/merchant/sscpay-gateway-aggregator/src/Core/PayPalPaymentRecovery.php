<?php
namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Core\Exceptions\PayPalCaptureException;

if (!defined('ABSPATH')) { exit; }

class PayPalPaymentRecovery
{
    /** Called under the same order lock as capture; never changes payment status or creates another Order. */
    public static function remember(array $transaction, PayPalCaptureException $error): array
    {
        if (!$error->isInstrumentDeclined()) { return $transaction; }
        $session = TransactionManager::getGatewaySession($transaction);
        $orderId = $transaction['payment_intent'] ?? null;
        if ($transaction['status'] !== TransactionStateMachine::PENDING || !$orderId
            || !in_array($transaction['payment_method'], ['paypal_js', 'paypal_rest'], true)
            || !empty($session['_creating']) || ($session['external_ref'] ?? null) !== $orderId
            || (isset($session['order_id']) && $session['order_id'] !== $orderId)) {
            throw new \RuntimeException('支付会话已更新，请重新打开付款链接');
        }
        $previous = $session;
        $session['_paypal_funding_recovery'] = [
            'order_id' => $orderId,
            'issue' => 'INSTRUMENT_DECLINED',
            'redirect_url' => $error->recoveryUrl() ?? self::redirectUrl($transaction),
        ];
        if ($session !== $previous && !TransactionManager::savePendingSession($transaction, $session)) {
            throw new \RuntimeException('支付会话已更新，请重新打开付款链接');
        }
        return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
    }

    public static function redirectUrl(array $transaction): ?string
    {
        $session = TransactionManager::getGatewaySession($transaction);
        $recovery = $session['_paypal_funding_recovery'] ?? [];
        if (($recovery['order_id'] ?? null) === ($transaction['payment_intent'] ?? null)
            && ($recovery['issue'] ?? '') === 'INSTRUMENT_DECLINED') {
            $url = PayPalCaptureException::validatedRedirectUrl($recovery['redirect_url'] ?? null);
            if ($url !== null) { return $url; }
        }
        return PayPalCaptureException::validatedRedirectUrl($session['redirect_url'] ?? null);
    }
}
