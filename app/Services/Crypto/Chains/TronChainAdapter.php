<?php

namespace App\Services\Crypto\Chains;

use App\Models\Order;
use App\Services\Crypto\Chains\Concerns\FormatsCryptoAmount;
use Illuminate\Support\Str;

/**
 * TRON 主网：Tatum tron-mainnet 订阅 + TronLink transfer 深链。
 * 代币（如 USDT TRC-20）填 token_contract；原生 TRX 则留空。
 */
class TronChainAdapter implements CryptoChainAdapter
{
    use FormatsCryptoAmount;

    /** TronLink 协议主网 chainId。 */
    public const MAINNET_CHAIN_ID = '0x2b6653dc';

    public function key(): string
    {
        return 'tron';
    }

    public function supports(string $chainHint): bool
    {
        $value = strtolower(str_replace('_', '-', trim($chainHint)));

        return $value !== '' && str_starts_with($value, 'tron');
    }

    public function tatumChain(): string
    {
        return 'tron-mainnet';
    }

    public function deepLink(Order $order, string $toAddress, ?string $pageUrl = null): ?string
    {
        if ($toAddress === '') {
            return null;
        }

        // P0：链上应付与匹配键均为 converted_amount（千分位，可含盐）。
        $amount = $this->humanAmount((string) $order->converted_amount);
        if ($amount === null) {
            return null;
        }

        $contract = trim((string) ($order->crypto_token_contract ?? ''));
        $isTrc20 = $contract !== '';

        $pageUrl = $pageUrl ?: route('payment.show', ['token' => $order->payment_link_token], absolute: true);
        $param = [
            'url' => $pageUrl,
            'callbackUrl' => $pageUrl,
            'dappName' => (string) ($order->merchant?->name ?: config('app.name')),
            'protocol' => 'TronLink',
            'version' => '1.0',
            'chainId' => self::MAINNET_CHAIN_ID,
            'memo' => (string) $order->order_no,
            'from' => '',
            'to' => $toAddress,
            'loginAddress' => '',
            'tokenId' => $isTrc20 ? '' : '0',
            'contract' => $isTrc20 ? $contract : '',
            'amount' => $amount,
            'action' => 'transfer',
            'actionId' => (string) Str::uuid(),
        ];

        $json = json_encode($param, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }

        return 'tronlinkoutside://pull.activity?param='.rawurlencode($json);
    }

    public function walletLabel(): string
    {
        return 'Open in TronLink';
    }

    public function matchesContractAlias(string $contract, Order $order): ?bool
    {
        // Tatum 偶发用资产别名代替合约地址。
        $alias = strtoupper(trim($contract));

        if ($alias === 'USDT_TRON') {
            return strcasecmp((string) $order->crypto_symbol, 'USDT') === 0;
        }

        if ($alias === 'INRT_TRON') {
            return strcasecmp((string) $order->crypto_symbol, 'INRT') === 0;
        }

        return null;
    }

    public function isPlaceholderAddress(string $address): bool
    {
        return str_starts_with($address, 'TReplace');
    }

    public function defaultAmountScale(): int
    {
        return 3;
    }

    public function amountSaltMaxOffset(): string
    {
        return '1';
    }
}
