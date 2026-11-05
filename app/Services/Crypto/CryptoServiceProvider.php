<?php

namespace App\Services\Crypto;

use App\Services\Crypto\Chains\CryptoChainRegistry;
use App\Services\Crypto\Chains\TronChainAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * 加密听链绑定。在 bootstrap/providers.php 注册。
 * 当前仅注册 Tron（USDT）；Ethereum / Bitcoin 适配器暂未合入本分支提交。
 * 新链：实现 CryptoChainAdapter，并加入下方 Registry 列表（顺序即解析优先级）。
 */
class CryptoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CryptoChainRegistry::class, function ($app): CryptoChainRegistry {
            return new CryptoChainRegistry([
                $app->make(TronChainAdapter::class),
            ]);
        });
    }

    /**
     * @return list<class-string>
     */
    public function provides(): array
    {
        return [CryptoChainRegistry::class];
    }
}
