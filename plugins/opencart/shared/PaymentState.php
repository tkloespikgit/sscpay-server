<?php
namespace Sscpay\OpenCart;

use RuntimeException;

/** Pure protocol reducer; OpenCart history and storage are applied under one order lock. */
final class PaymentState {
    public static function apply(array $record, array $body, string $source): array {
        $previous = $record['state'];
        if (($body['merchant_order_no'] ?? '') !== $record['merchant_order_no']
            || !is_string($body['order_no'] ?? null) || $body['order_no'] === '' || strlen($body['order_no']) > 80
            || (!empty($record['remote_order_no']) && $body['order_no'] !== $record['remote_order_no'])
            || ($body['currency'] ?? '') !== $record['currency']
            || Decimal::cents($body['amount'] ?? null) !== Decimal::cents($record['amount'])) {
            throw new RuntimeException('Order identity or amount mismatch.');
        }
        $v2 = isset($body['paid_amount']) || isset($body['version']) || ($body['order_type'] ?? '') === 'combine';
        $type = $body['order_type'] ?? 'single';
        if (!in_array($type, ['single', 'combine'], true) || (isset($previous['type']) && $previous['type'] !== $type)) {
            throw new RuntimeException('Invalid order type.');
        }
        $version = $previous['version'] ?? -1;
        $event = '';
        $paid = ($body['status'] ?? '') === 'paid' || !empty($previous['paid_once']) ? Decimal::cents($body['amount']) : '0';
        $remaining = Decimal::subtract(Decimal::cents($body['amount']), $paid);
        $children = [];
        if ($v2) {
            $paid = Decimal::cents($body['paid_amount'] ?? null);
            $remaining = Decimal::cents($body['remaining_amount'] ?? null);
            $version = $body['version'] ?? null;
            if (!is_int($version) || $version < ($source === 'notify' ? 1 : 0)
                || Decimal::add($paid, $remaining) !== Decimal::cents($record['amount'])
                || !is_array($body['children'] ?? null)) {
                throw new RuntimeException('Invalid v2 payment progress.');
            }
            if ($source === 'notify') {
                $event = $body['event_id'] ?? '';
                if (!is_string($event) || $event === '' || strlen($event) > 128) {
                    throw new RuntimeException('Missing v2 event ID.');
                }
            }
            $paidChildren = '0';
            $references = [];
            $remoteReferences = [];
            foreach ($body['children'] as $child) {
                if (!is_array($child) || !is_string($child['merchant_order_no'] ?? null)
                    || !is_string($child['order_no'] ?? null) || $child['order_no'] === ''
                    || ($child['currency'] ?? '') !== $record['currency']
                    || !preg_match('/^' . preg_quote($record['merchant_order_no'], '/') . '-S\d{3,}$/D', $child['merchant_order_no'])
                    || isset($references[$child['merchant_order_no']]) || isset($remoteReferences[$child['order_no']])) {
                    throw new RuntimeException('Invalid combined payment child.');
                }
                $childAmount = Decimal::cents($child['amount'] ?? null);
                $references[$child['merchant_order_no']] = true;
                $remoteReferences[$child['order_no']] = true;
                if (($child['payment_status'] ?? '') === 'paid') {
                    $paidChildren = Decimal::add($paidChildren, $childAmount);
                }
                $children[] = $child;
            }
            if (($type === 'combine' && $paidChildren !== $paid) || ($type === 'single' && $children)) {
                throw new RuntimeException('Combined payment total mismatch.');
            }
            if (($previous['version'] ?? -1) > $version
                || ($source === 'notify' && ($previous['version'] ?? -1) === $version)
                || ($event !== '' && in_array($event, $previous['events'] ?? [], true))) {
                return $previous;
            }
        } elseif (isset($previous['version']) && $previous['version'] >= 0 && ($source !== 'query' || ($previous['type'] ?? '') !== 'single')) {
            return $previous;
        }
        $status = (string)($body['status'] ?? '');
        $orderStatus = (string)($body['order_status'] ?? $status);
        $fullyPaid = $status === 'paid' && $remaining === '0' && $paid !== '0';
        if ($status === 'paid' && !$fullyPaid) {
            throw new RuntimeException('A paid order must be fully collected.');
        }
        $stage = $fullyPaid ? 'paid' : ($paid !== '0' ? 'partial' : 'pending');
        if (in_array($status, ['refund', 'refunded', 'chargeback', 'dispute', 'disputed'], true)) {
            $stage = in_array($status, ['refund', 'refunded'], true) ? 'refunded' : 'chargeback';
        } elseif ($status === 'review') {
            $stage = !empty($previous['paid_once']) ? 'paid' : 'partial';
        } elseif ($paid === '0' && in_array($status, ['expired', 'cancelled', 'failed', 'unpaid'], true)) {
            $stage = in_array($status, ['expired', 'cancelled'], true) ? 'cancelled' : 'failed';
        } elseif (!in_array($status, ['paid', 'pending', 'partially_paid', 'expired'], true)) {
            throw new RuntimeException('Unknown payment status.');
        }
        if (in_array($previous['stage'] ?? '', ['refunded', 'chargeback'], true)
            || (!empty($previous['paid_once']) && in_array($stage, ['pending', 'partial', 'cancelled', 'failed'], true))) {
            $stage = $previous['stage'];
        }
        if ($v2 && isset($previous['paid_amount']) && Decimal::compare($paid, Decimal::cents($previous['paid_amount'])) < 0
            && !in_array($stage, ['refunded', 'chargeback'], true)) {
            throw new RuntimeException('Payment progress cannot decrease.');
        }
        $events = $previous['events'] ?? [];
        if ($event !== '') {
            $events[] = $event;
        }
        return array_merge($previous, [
            'type' => $type, 'stage' => $stage, 'order_status' => $orderStatus,
            'paid_once' => !empty($previous['paid_once']) || $fullyPaid,
            'paid_amount' => Decimal::format($paid), 'remaining_amount' => Decimal::format($remaining),
            'version' => $version, 'events' => array_slice($events, -128), 'children' => $children,
        ]);
    }

    public static function shippingPayloads(array $record, array $shipment): array {
        $state = $record['state'];
        if (empty($state['paid_once']) || ($state['stage'] ?? '') !== 'paid' || ($state['remaining_amount'] ?? '') !== '0.00'
            || ($state['order_status'] ?? '') === 'review') {
            throw new RuntimeException('Shipping requires a fully paid order.');
        }
        $references = [$record['merchant_order_no']];
        if (($state['type'] ?? '') === 'combine') {
            $references = [];
            foreach ($state['children'] ?? [] as $child) {
                if (($child['payment_status'] ?? '') === 'paid') {
                    $references[] = $child['merchant_order_no'];
                }
            }
            if (!$references) {
                throw new RuntimeException('Missing paid combined payment children.');
            }
        }
        $result = [];
        foreach (array_unique($references) as $reference) {
            $result[] = array_merge($shipment, ['merchant_order_no' => $reference]);
        }
        return $result;
    }
}
