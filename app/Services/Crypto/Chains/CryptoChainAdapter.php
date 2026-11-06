<?php

namespace App\Services\Crypto\Chains;

use App\Models\Order;

/**
 * 单链差异契约（按链实现，不按币种拆类；USDT/原生币靠 token_contract 有无区分）。
 *
 * 职责边界：
 * - 本接口：Tatum chain 标识、付款深链、合约别名、占位地址、默认精度/盐上限
 * - 不在此：金额互斥盐编排、订单匹配、入账、过期 → 见 CryptoPaymentService
 * - 订阅类型（FUNGIBLE vs NATIVE）由是否配置 token_contract 决定，见 TatumClient
 *
 * 注册：CryptoServiceProvider 将实现挂入 CryptoChainRegistry（当前仅 Tron；
 * ETH/BTC 适配器文件可存在但未注册，见各类文件头注释）。
 */
interface CryptoChainAdapter
{
    /** 规范化后的链键，写入订单 crypto_chain（如 tron / ethereum / bitcoin）。 */
    public function key(): string;

    /**
     * 是否处理该链提示（可含 tron-mainnet、ETH、ethereum、bitcoin、btc 等别名）。
     * 空字符串不得命中，避免无链订单误用默认适配器。
     */
    public function supports(string $chainHint): bool;

    /** Tatum Notifications 的 chain 参数（如 tron-mainnet / bitcoin-mainnet）。 */
    public function tatumChain(): string;

    /**
     * 钱包深链；无法生成时返回 null（付款页仅展示地址/金额）。
     * QR 码一律用纯收款地址，不把深链编进二维码。
     */
    public function deepLink(Order $order, string $toAddress, ?string $pageUrl = null): ?string;

    /** 付款页主按钮文案（有 deepLink 时使用）。 */
    public function walletLabel(): string;

    /**
     * webhook 的 contractAddress 是否为本链资产别名（如 USDT_TRON、USDT_ETH）。
     * 返回 null：不是本适配器认识的别名，调用方继续按合约地址精确比对。
     */
    public function matchesContractAlias(string $contract, Order $order): ?bool;

    /** 模板占位收款地址（如 TReplace… / 0xReplace… / bc1Replace…），建单时拒绝。 */
    public function isPlaceholderAddress(string $address): bool;

    /**
     * 渠道未配 amount_scale 时的默认展示/互斥小数位。
     * 稳定币类默认 3；BTC 等原生高单价资产用更高精度。
     */
    public function defaultAmountScale(): int;

    /**
     * 金额互斥相对 base 的最大向下偏移（与 amount_scale 同单位）。
     * USDT 等可用 1；BTC 须更小，避免盐到整币级。
     */
    public function amountSaltMaxOffset(): string;
}
