<?php

namespace App\Services\Crypto\Chains;

use App\Models\Order;
use App\Services\Crypto\Chains\Concerns\FormatsCryptoAmount;

/**
 * Bitcoin 主网链适配器（预留，**未**挂入 CryptoServiceProvider）。
 *
 * 现状：
 * - P0 仅开放 Tron USDT；本类保留实现，供以后挂 Registry 时复用。
 * - 产品若开放 BTC：在 CryptoServiceProvider 加入本类，并补配置模板 / 建单校验（当前
 *   CryptoPaymentService 仅允许 USDT + 非空 token_contract，原生 BTC 需另开产品口径）。
 *
 * 职责：
 * - Tatum chain：`bitcoin-mainnet`
 * - 深链：BIP21 `bitcoin:{address}?amount={btc}`；金额取订单 `converted_amount` 的人类可读串
 * - 订阅：空 `token_contract` → Tatum `INCOMING_NATIVE_TX`（见 TatumClient）
 * - 占位地址：`bc1Replace…` / `1Replace…` / `3Replace…` 前缀，建单应拒绝
 *
 * 精度与盐：`defaultAmountScale=8`、`amountSaltMaxOffset=0.0001` 供以后按链盐策略使用；
 * 现行 P0 盐编排固定用 CryptoPaymentService::CONVERTED_SCALE / 盐上限 1（USDT 千分位），
 * **不会**自动读本适配器这两项，挂载 BTC 前须对齐产品与编排。
 */
class BitcoinChainAdapter implements CryptoChainAdapter
{
    use FormatsCryptoAmount;

    public function key(): string
    {
        return 'bitcoin';
    }

    public function supports(string $chainHint): bool
    {
        $value = strtolower(str_replace('_', '-', trim($chainHint)));

        return $value === 'btc'
            || $value === 'bitcoin'
            || str_starts_with($value, 'bitcoin-');
    }

    public function tatumChain(): string
    {
        return 'bitcoin-mainnet';
    }

    /**
     * BIP21 付款 URI。QR 码仍只用纯地址（见付款页），深链给「Open in wallet」。
     */
    public function deepLink(Order $order, string $toAddress, ?string $_pageUrl = null): ?string
    {
        if ($toAddress === '') {
            return null;
        }

        $amount = $this->humanAmount((string) $order->converted_amount);
        if ($amount === null) {
            return null;
        }

        return 'bitcoin:'.$toAddress.'?amount='.$amount;
    }

    public function walletLabel(): string
    {
        return 'Open in wallet';
    }

    /**
     * BTC 无 TRC/ERC 式合约别名；返回 null，调用方按合约地址精确比对（原生币通常为空）。
     */
    public function matchesContractAlias(string $contract, Order $order): ?bool
    {
        return null;
    }

    public function isPlaceholderAddress(string $address): bool
    {
        $value = strtolower(trim($address));

        return str_starts_with($value, 'bc1replace')
            || str_starts_with($value, '1replace')
            || str_starts_with($value, '3replace');
    }

    public function defaultAmountScale(): int
    {
        return 8;
    }

    public function amountSaltMaxOffset(): string
    {
        return '0.0001';
    }
}
