<?php
namespace Sscpay\OpenCart;

use RuntimeException;

final class Payload {
    public static function reference(int $major, int $storeId, int $orderId): string {
        return 'OC' . $major . '-' . $storeId . '-' . $orderId;
    }

    public static function create(array $order, array $products, array $totals, array $settings, array $urls, string $reference): array {
        $currency = strtoupper((string)$order['currency_code']);
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new RuntimeException('Invalid order currency.');
        }
        $rate = (string)$order['currency_value'];
        if (Decimal::scaled($rate, 8) === '0') {
            throw new RuntimeException('Invalid order exchange rate.');
        }
        $amount = Decimal::convert($order['total'], $rate);
        if (Decimal::cents($amount) === '0') {
            throw new RuntimeException('A positive order total is required.');
        }
        $items = [];
        $subtotal = '0';
        foreach ($products as $product) {
            $quantity = (int)$product['quantity'];
            if ($quantity < 1 || $quantity > 1000000) {
                throw new RuntimeException('Invalid product quantity.');
            }
            $price = Decimal::convert($product['price'], $rate);
            $subtotal = Decimal::add($subtotal, Decimal::multiply(Decimal::cents($price), (string)$quantity));
            $items[] = [
                'product_id' => (string)$product['product_id'], 'product_sku' => self::text($product['model'] ?? '', 64),
                'product_url' => $urls['products'][(string)$product['product_id']],
                'product_name' => self::text($product['name'], 255), 'unit_price' => $price, 'quantity' => $quantity,
            ];
        }
        if (!$items) {
            throw new RuntimeException('No order products.');
        }
        $shipping = '0';
        $tax = '0';
        foreach ($totals as $total) {
            if (in_array($total['code'], ['shipping', 'tax'], true) && substr((string)$total['value'], 0, 1) !== '-') {
                $value = Decimal::cents(Decimal::convert($total['value'], $rate));
                if ($total['code'] === 'shipping') {
                    $shipping = Decimal::add($shipping, $value);
                } else {
                    $tax = Decimal::add($tax, $value);
                }
            }
        }
        $calculated = Decimal::add(Decimal::add($subtotal, $shipping), $tax);
        $amountCents = Decimal::cents($amount);
        $discount = '0';
        if (Decimal::compare($calculated, $amountCents) >= 0) {
            $discount = Decimal::subtract($calculated, $amountCents);
        } else {
            $fee = Decimal::subtract($amountCents, $calculated);
            $items[] = ['product_id' => 'sscpay-adjustment-' . (int)$order['order_id'],
                'product_url' => $urls['store'], 'product_name' => 'Order fees and rounding',
                'unit_price' => Decimal::format($fee), 'quantity' => 1];
            $subtotal = Decimal::add($subtotal, $fee);
        }
        $prefix = !empty($order['shipping_address_1']) ? 'shipping_' : 'payment_';
        $address = [
            'line1' => self::text($order[$prefix . 'address_1'] ?? '', 255),
            'line2' => self::text($order[$prefix . 'address_2'] ?? '', 255),
            'city' => self::text($order[$prefix . 'city'] ?? '', 100),
            'state' => self::text($order[$prefix . 'zone'] ?? '', 100),
            'country' => strtoupper((string)($order[$prefix . 'iso_code_2'] ?? '')),
            'zip' => self::text($order[$prefix . 'postcode'] ?? '', 20),
        ];
        if ($address['line1'] === '' || $address['city'] === '' || !preg_match('/^[A-Z]{2}$/D', $address['country'])
            || empty($order['firstname']) || empty($order['lastname']) || !filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A customer name, email and complete shipping or billing address are required.');
        }
        $body = [
            'merchant_order_no' => $reference, 'platform' => $settings['platform'] ?: 'opencart',
            'currency' => $currency, 'group_key' => $settings['group_key'],
            'subtotal' => Decimal::format($subtotal), 'shipping_fee' => Decimal::format($shipping),
            'tax' => Decimal::format($tax), 'discount' => Decimal::format($discount), 'amount' => $amount,
            'customer' => ['first_name' => self::text($order['firstname'], 100), 'last_name' => self::text($order['lastname'], 100),
                'email' => (string)$order['email'], 'phone' => self::text($order['telephone'] ?? '', 30)],
            'shipping_address' => $address, 'items' => $items,
            'notify_url' => $urls['notify'], 'return_url' => $urls['return'], 'cancel_url' => $urls['cancel'],
        ];
        if (!empty($settings['payment_method_key'])) {
            $body['payment_method_key'] = $settings['payment_method_key'];
        }
        return $body;
    }

    private static function text(string $value, int $limit): string {
        return mb_substr(trim(strip_tags(html_entity_decode($value, ENT_QUOTES, 'UTF-8'))), 0, $limit, 'UTF-8');
    }
}
