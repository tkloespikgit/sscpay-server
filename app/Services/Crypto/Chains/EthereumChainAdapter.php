<?php

namespace App\Services\Crypto\Chains;

use App\Models\Order;
use App\Services\Crypto\Chains\Concerns\FormatsCryptoAmount;

/**
 * Ethereum 主网链适配器（预留，**未**挂入 CryptoServiceProvider）。
 *
 * 现状：
 * - P0 仅开放 Tron USDT；本类保留实现，供以后挂 Registry 时复用。
 * - 挂载步骤：CryptoServiceProvider 加入本类 + 配置模板（如 crypto_eth_usdt）+ 测试；
 *   建单仍须满足 CryptoPaymentService 的 USDT + `token_contract` 校验（P0 不收原生 ETH）。
 *
 * 职责：
 * - Tatum chain：`ethereum-mainnet`
 * - 深链：EIP-681；有合约 → `ethereum:{contract}/transfer?address=&uint256=`（默认 6 decimals，USDT）；
 *   空合约 → `ethereum:{to}?value=`（wei，18 decimals）。回跳 URL 不编入 EIP-681。
 * - 合约别名：webhook 可能带 `USDT_ETH` / `USDT_ETHEREUM`，在订单 symbol=USDT 时视为命中
 * - 占位地址：`0xReplace…` 前缀
 *
 * 注意：
 * - 订单未快照 token decimals；ERC-20 深链暂用 DEFAULT_TOKEN_DECIMALS=6（主网 USDT 常见值）。
 * - 主网 USDT 合约常见 `0xdAC17F958D2ee523a2206206994597C13D831ec7`（以渠道 config 为准）。
 * - `defaultAmountScale` / `amountSaltMaxOffset` 与现行 P0 盐编排（CONVERTED_SCALE=3、盐≤1）对齐；
 *   挂载后若改精度须同步 CryptoPaymentService，勿只改适配器。
 */
class EthereumChainAdapter implements CryptoChainAdapter
{
    use FormatsCryptoAmount;

    /** EIP-681 代币 transfer 的 decimals 缺省（订单无快照时）；主网 USDT=6。 */
    public const DEFAULT_TOKEN_DECIMALS = 6;

    /** 原生 ETH wei 精度。 */
    public const NATIVE_DECIMALS = 18;

    public function key(): string
    {
        return 'ethereum';
    }

    public function supports(string $chainHint): bool
    {
        $value = strtolower(str_replace('_', '-', trim($chainHint)));

        return str_starts_with($value, 'eth');
    }

    public function tatumChain(): string
    {
        return 'ethereum-mainnet';
    }

    /**
     * EIP-681。应付金额为订单 `converted_amount`（千分位，可含盐）；与 webhook 匹配键一致。
     */
    public function deepLink(Order $order, string $toAddress, ?string $_pageUrl = null): ?string
    {
        // 回跳页仅 TronLink 需要；EIP-681 不携带。
        if ($toAddress === '' || ! str_starts_with(strtolower($toAddress), '0x')) {
            return null;
        }

        $human = $this->humanAmount((string) $order->converted_amount);
        if ($human === null) {
            return null;
        }

        $contract = trim((string) ($order->crypto_token_contract ?? ''));

        if ($contract !== '' && str_starts_with(strtolower($contract), '0x')) {
            $baseUnits = $this->toBaseUnits($human, self::DEFAULT_TOKEN_DECIMALS);
            if ($baseUnits === null) {
                return null;
            }

            return sprintf(
                'ethereum:%s/transfer?address=%s&uint256=%s',
                $contract,
                $toAddress,
                $baseUnits,
            );
        }

        $wei = $this->toBaseUnits($human, self::NATIVE_DECIMALS);
        if ($wei === null) {
            return null;
        }

        return sprintf('ethereum:%s?value=%s', $toAddress, $wei);
    }

    public function walletLabel(): string
    {
        return 'Open in wallet';
    }

    public function matchesContractAlias(string $contract, Order $order): ?bool
    {
        $alias = strtoupper(trim($contract));

        if (! in_array($alias, ['USDT_ETH', 'USDT_ETHEREUM'], true)) {
            return null;
        }

        return strcasecmp((string) $order->crypto_symbol, 'USDT') === 0;
    }

    public function isPlaceholderAddress(string $address): bool
    {
        return str_starts_with(strtolower(trim($address)), '0xreplace');
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
