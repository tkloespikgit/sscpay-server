<?php

namespace App\Services\Crypto;

use App\Services\Crypto\Chains\CryptoChainRegistry;
use App\Services\Crypto\Chains\TronChainAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * 加密听链绑定。在 bootstrap/providers.php 注册。
 *
 * 当前 Registry **仅挂载 Tron**（P0：USDT）。
 * EthereumChainAdapter / BitcoinChainAdapter 源码已入库但故意不加入下方列表，
 * 故 resolve('ethereum'|'bitcoin') 为 null；开放时再挂载并补模板与测试。
 * 新链：实现 CryptoChainAdapter，加入列表（顺序即解析优先级）。
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
