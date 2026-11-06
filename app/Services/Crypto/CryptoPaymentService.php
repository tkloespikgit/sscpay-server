<?php

namespace App\Services\Crypto;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Crypto\Chains\CryptoChainRegistry;
use App\Services\OrderPaymentStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 链上收款编排（listen-only，不持私钥）。
 *
 * 流程概要（P0 仅 USDT）：
 * 1. 建单侧：无汇损折算 converted_amount（千分位）→ 写互斥盐 → 再算手续费；
 *    prepareCheckout 绑定地址/过期并订阅 Tatum
 * 2. handleIncomingTransfer：webhook → 同址 + converted_amount 精确匹配 → applyTransfer
 * 3. expireDueOrders / expireIfDue：超时 pending → expired
 *
 * 匹配键为 orders.converted_amount。
 */
class CryptoPaymentService
{
    /** P0 开放的链上币种（大小写不敏感）。 */
    public const SUPPORTED_SYMBOLS = ['USDT'];

    /** converted_amount / 链上匹配统一千分位。 */
    public const CONVERTED_SCALE = 3;

    /** 金额互斥：相对 base 向下偏移的最大幅度（与 CONVERTED_SCALE 同单位）。 */
    private const AMOUNT_SALT_MAX_OFFSET = '1';

    public function __construct(
        private readonly TatumClient $tatum,
        private readonly OrderPaymentStatusService $paymentStatuses,
        private readonly CryptoChainRegistry $chains,
    ) {}

    /**
     * 校验渠道为已开放的 USDT 加密网关（含有效收款地址与合约）。
     */
    public function assertSupportedUsdtMethod(PaymentMethod $method): void
    {
        if (! $method->isCryptoGateway()) {
            throw new RuntimeException("支付方式 {$method->method_code} 不是加密网关");
        }

        $address = $method->cryptoReceiveAddress();
        $chain = $this->chains->normalize($method->cryptoChain());
        $symbol = $method->cryptoSymbol();
        $contract = $method->cryptoTokenContract();

        if ($address === '' || $this->chains->isPlaceholderAddress($address)) {
            throw new RuntimeException('加密渠道未配置有效收款地址（网关配置 receive_address）');
        }

        if ($chain === '' || $symbol === '') {
            throw new RuntimeException('加密渠道未配置 chain / symbol');
        }

        if (! in_array(strtoupper($symbol), self::SUPPORTED_SYMBOLS, true)) {
            throw new RuntimeException(
                '当前仅支持 '.implode('/', self::SUPPORTED_SYMBOLS)." 收款，渠道 symbol={$symbol} 暂未开放"
            );
        }

        if ($contract === '') {
            throw new RuntimeException('USDT 渠道须配置 token_contract（合约地址），不支持空合约原生币收款');
        }
    }

    /** 地址维度互斥锁键；调用方须在「分配盐 + 落库含 crypto_receive_address」期间持有。 */
    public function amountLockKey(string $address): string
    {
        return 'crypto:amount:'.strtolower($address);
    }

    /**
     * 同址未过期 pending 内为 converted_amount 分配唯一值（向下盐，步长 0.001，总偏移 ≤1）。
     * 不自带锁：须由调用方持有 amountLockKey($address) 并在锁内落库收款地址。
     */
    public function allocateUniqueConvertedAmountForAddress(string $address, string $baseConverted): string
    {
        return $this->allocateUniqueConvertedAmount($address, $baseConverted, self::CONVERTED_SCALE);
    }

    /**
     * 建单后绑定链上快照：地址、过期时间、付款链接；不改 converted_amount。
     */
    public function prepareCheckout(Order $order, PaymentMethod $method): void
    {
        $this->assertSupportedUsdtMethod($method);

        $address = $method->cryptoReceiveAddress();
        $chain = $this->chains->normalize($method->cryptoChain());
        $symbol = $method->cryptoSymbol();
        $contract = $method->cryptoTokenContract();
        $scale = self::CONVERTED_SCALE;
        $ttl = max(1, (int) config('tatum.payment_ttl_minutes', 480));
        $payUrl = route('payment.show', ['token' => $order->payment_link_token], absolute: true);

        $order->forceFill([
            'crypto_chain' => $chain,
            'crypto_symbol' => $symbol,
            'crypto_token_contract' => $contract,
            'crypto_receive_address' => $address,
            'crypto_amount_received' => $this->truncate('0', $scale),
            'crypto_expires_at' => now()->addMinutes($ttl),
            'crypto_txids' => [],
            'pay_url' => $payUrl,
        ])->save();

        $this->ensureSubscription($method->fresh());
    }

    /**
     * 确保渠道收款地址已在 Tatum 订阅（有合约 → FUNGIBLE，空合约 → NATIVE）。
     */
    public function ensureSubscription(PaymentMethod $method): void
    {
        if (filled($method->cryptoTatumSubscriptionId())) {
            return;
        }

        $address = $method->cryptoReceiveAddress();
        $chain = $method->cryptoChain();
        if ($address === '' || $chain === '') {
            return;
        }

        $adapter = $this->chains->resolve($chain);
        if ($adapter === null) {
            Log::warning('crypto: 无对应链适配器，跳过 Tatum 订阅', [
                'payment_method_id' => $method->id,
                'chain' => $chain,
            ]);

            return;
        }

        $subscriptionType = TatumClient::subscriptionTypeForContract($method->cryptoTokenContract());
        $wantNative = $subscriptionType === TatumClient::SUBSCRIPTION_NATIVE;

        $existingId = PaymentMethod::query()
            ->withoutGlobalScopes()
            ->with('configMap')
            ->where('id', '!=', $method->id)
            ->get()
            ->first(function (PaymentMethod $other) use ($address, $wantNative): bool {
                if (! $other->isCryptoGateway()
                    || strcasecmp($other->cryptoReceiveAddress(), $address) !== 0
                    || blank($other->cryptoTatumSubscriptionId())) {
                    return false;
                }

                $otherNative = $other->cryptoTokenContract() === '';

                return $otherNative === $wantNative;
            })
            ?->cryptoTatumSubscriptionId();

        if (filled($existingId)) {
            $method->mergeCryptoConfig(['tatum_subscription_id' => $existingId]);

            return;
        }

        $webhookUrl = $method->cryptoTatumWebhookUrl();

        try {
            $id = $this->tatum
                ->using($method->cryptoTatumHttpCredentials())
                ->subscribeAddress($adapter->tatumChain(), $address, $webhookUrl, $subscriptionType);
            if (filled($id)) {
                $method->mergeCryptoConfig(['tatum_subscription_id' => $id]);
            }
        } catch (\Throwable $e) {
            Log::error('crypto: 订阅 Tatum 失败，订单仍可展示收款信息', [
                'payment_method_id' => $method->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleIncomingTransfer(array $payload): void
    {
        $address = (string) ($payload['address'] ?? $payload['to'] ?? '');
        $txid = (string) ($payload['txId'] ?? $payload['txid'] ?? $payload['hash'] ?? '');
        $amount = $this->normalizeAmount((string) ($payload['amount'] ?? '0'));
        $contract = (string) ($payload['contractAddress'] ?? $payload['contract'] ?? '');
        $assetHint = strtolower((string) ($payload['asset'] ?? $payload['symbol'] ?? ''));
        $chainHint = $this->chains->normalize((string) ($payload['chain'] ?? $payload['currency'] ?? 'tron'));

        if ($address === '' || $txid === '' || bccomp($amount, '0', 8) <= 0) {
            Log::info('crypto webhook: 缺少地址/txid/金额，忽略', ['payload' => $payload]);

            return;
        }

        $order = $this->matchPendingOrder($address, $amount, $chainHint, $assetHint, $contract);

        if (! $order) {
            Log::warning('crypto webhook: 无法唯一匹配待付订单', [
                'address' => $address,
                'amount' => $amount,
                'txid' => $txid,
            ]);

            return;
        }

        $this->applyTransfer($order, $amount, $txid);
    }

    /**
     * 累计链上到账；达到 converted_amount 则标记 paid。
     */
    public function applyTransfer(Order $order, string $amount, string $txid): void
    {
        $shouldMarkPaid = false;

        DB::transaction(function () use ($order, $amount, $txid, &$shouldMarkPaid): void {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== 'pending' || $locked->crypto_expires_at === null) {
                return;
            }

            if ($locked->crypto_expires_at->isPast()) {
                $locked->forceFill(['status' => 'expired'])->save();

                return;
            }

            $txids = is_array($locked->crypto_txids) ? $locked->crypto_txids : [];
            if (in_array($txid, $txids, true)) {
                return;
            }

            $txids[] = $txid;
            $scale = $locked->cryptoAmountScale();
            $received = bcadd((string) ($locked->crypto_amount_received ?? '0'), $amount, 8);
            $expected = (string) $locked->converted_amount;

            $locked->forceFill([
                'crypto_amount_received' => $this->truncate($received, $scale),
                'crypto_txids' => $txids,
                'transaction_id' => $txid,
            ])->save();

            $shouldMarkPaid = bccomp($this->truncate($received, $scale), $this->truncate($expected, $scale), $scale) >= 0;
            $order->setRawAttributes($locked->getAttributes());
        });

        $order->refresh();

        if ($shouldMarkPaid && $order->status === 'pending') {
            $this->paymentStatuses->applyCryptoPaid($order, $txid);
        }
    }

    public function expireDueOrders(int $limit = 200): int
    {
        $count = 0;
        Order::query()
            ->withoutGlobalScopes()
            ->where('status', 'pending')
            ->whereNotNull('crypto_expires_at')
            ->where('crypto_expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->each(function (Order $order) use (&$count): void {
                if ($this->expireIfDue($order)) {
                    $count++;
                }
            });

        return $count;
    }

    public function expireIfDue(Order $order): bool
    {
        if ($order->status !== 'pending' || $order->crypto_expires_at === null || $order->crypto_expires_at->isFuture()) {
            return false;
        }

        $order->forceFill(['status' => 'expired'])->save();

        return true;
    }

    /**
     * 同址未过期 pending 内分配唯一 converted_amount。
     */
    private function allocateUniqueConvertedAmount(string $address, string $base, int $scale): string
    {
        $base = $this->truncate($base, $scale);
        if (bccomp($base, '0', $scale) <= 0) {
            throw new RuntimeException('加密货币应付金额无效');
        }

        $maxOffset = self::AMOUNT_SALT_MAX_OFFSET;
        $step = $this->minStep($scale);
        $floor = bcsub($base, $maxOffset, $scale);
        if (bccomp($floor, $step, $scale) < 0) {
            $floor = $step;
        }

        $occupied = Order::query()
            ->withoutGlobalScopes()
            ->where('status', 'pending')
            ->where('crypto_receive_address', $address)
            ->whereNotNull('crypto_expires_at')
            ->where('crypto_expires_at', '>', now())
            ->pluck('converted_amount')
            ->map(fn ($amount): string => $this->truncate((string) $amount, $scale))
            ->all();
        $occupiedSet = array_fill_keys($occupied, true);

        $candidate = $base;
        while (bccomp($candidate, $floor, $scale) >= 0) {
            if (! isset($occupiedSet[$candidate])) {
                return $candidate;
            }
            $candidate = bcsub($candidate, $step, $scale);
        }

        throw new RuntimeException(
            "收款地址 {$address} 在金额互斥窗口内无可用应付额（base={$base}，向下最多 {$maxOffset}）"
        );
    }

    private function matchPendingOrder(
        string $address,
        string $amount,
        string $chain,
        string $symbol,
        string $contract = '',
    ): ?Order {
        $adapter = $this->chains->resolve($chain);

        $candidates = Order::query()
            ->withoutGlobalScopes()
            ->where('status', 'pending')
            ->where('crypto_receive_address', $address)
            ->whereNotNull('crypto_expires_at')
            ->where('crypto_expires_at', '>', now())
            ->orderBy('id')
            ->get()
            ->filter(function (Order $order) use ($chain, $symbol, $contract, $adapter) {
                if (filled($order->crypto_chain) && strtolower((string) $order->crypto_chain) !== $chain) {
                    return false;
                }

                if ($contract !== '') {
                    $aliasMatch = $adapter?->matchesContractAlias($contract, $order);
                    if ($aliasMatch !== null) {
                        return $aliasMatch;
                    }

                    if (filled($order->crypto_token_contract)) {
                        return strcasecmp((string) $order->crypto_token_contract, $contract) === 0;
                    }
                }

                if ($symbol === '') {
                    return true;
                }

                return strcasecmp((string) $order->crypto_symbol, $symbol) === 0;
            })
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $byExactConverted = $candidates->filter(function (Order $order) use ($amount) {
            $scale = $order->cryptoAmountScale();

            return bccomp(
                $this->truncate($amount, $scale),
                $this->truncate((string) $order->converted_amount, $scale),
                $scale
            ) === 0;
        });

        if ($byExactConverted->count() === 1) {
            return $byExactConverted->first();
        }

        return null;
    }

    private function minStep(int $scale): string
    {
        if ($scale <= 0) {
            return '1';
        }

        return $this->truncate(bcpow('10', (string) (-1 * $scale), $scale + 2), $scale);
    }

    private function truncate(string $amount, int $scale): string
    {
        return bcadd($amount, '0', $scale);
    }

    private function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);
        if ($amount === '' || ! is_numeric($amount)) {
            return '0';
        }

        return $amount;
    }
}
