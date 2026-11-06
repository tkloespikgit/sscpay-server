<?php

namespace App\Services\Crypto;

use App\Models\Order;
use App\Services\Crypto\Chains\CryptoChainRegistry;

/**
 * 加密付款页链接：委托对应 CryptoChainAdapter 生成深链。
 * 付款页 QR 编码纯收款地址；「Open in wallet」按钮使用 deep_link。
 */
class CryptoCheckoutLinkBuilder
{
    public function __construct(
        private readonly CryptoChainRegistry $chains,
    ) {}

    /**
     * @return array{
     *     address: string,
     *     deep_link: ?string,
     *     wallet_label: ?string
     * }
     */
    public function forOrder(Order $order, ?string $pageUrl = null): array
    {
        $address = (string) ($order->crypto_receive_address ?: '');
        $adapter = $this->chains->resolve((string) ($order->crypto_chain ?? ''));
        $deepLink = $adapter?->deepLink($order, $address, $pageUrl);

        return [
            'address' => $address,
            'deep_link' => $deepLink,
            'wallet_label' => $deepLink !== null ? $adapter?->walletLabel() : null,
        ];
    }
}
