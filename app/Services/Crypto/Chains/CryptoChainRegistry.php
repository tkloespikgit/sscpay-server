<?php

namespace App\Services\Crypto\Chains;

/**
 * 按链提示解析 CryptoChainAdapter，并做 chain 键归一化。
 *
 * 解析顺序即构造时传入顺序（当前提交：仅 Tron）。
 * 未知链：resolve 返回 null；normalize 仅做小写/下划线整理，不臆造适配器。
 */
class CryptoChainRegistry
{
    /**
     * @param  list<CryptoChainAdapter>  $adapters
     */
    public function __construct(
        private readonly array $adapters,
    ) {}

    public function resolve(string $chainHint): ?CryptoChainAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($chainHint)) {
                return $adapter;
            }
        }

        return null;
    }

    /** 命中适配器时返回其 key()；否则返回清洗后的原始提示。 */
    public function normalize(string $chainHint): string
    {
        $adapter = $this->resolve($chainHint);
        if ($adapter !== null) {
            return $adapter->key();
        }

        return strtolower(str_replace('_', '-', trim($chainHint)));
    }

    /** 任一已注册适配器认为是占位地址则 true。 */
    public function isPlaceholderAddress(string $address): bool
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->isPlaceholderAddress($address)) {
                return true;
            }
        }

        return false;
    }
}
