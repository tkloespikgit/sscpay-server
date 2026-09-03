<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway;

use Illuminate\Support\ServiceProvider;

/**
 * 注册方式：
 *
 * Laravel 11+：在 bootstrap/providers.php 的数组里加一行
 *     App\Services\PaymentGateway\PaymentGatewayServiceProvider::class,
 *
 * Laravel 10 及更早：在 config/app.php 的 'providers' 数组里加同一行。
 *
 * 另外记得把 config/payment_gateway.php 复制到项目的 config/ 目录下。
 */
class PaymentGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/payment_gateway.php', 'payment_gateway');

        $this->app->singleton(PaymentGatewayService::class, function ($app) {
            return new PaymentGatewayService($app['config']['payment_gateway'] ?? []);
        });
    }

    public function provides(): array
    {
        return [PaymentGatewayService::class];
    }
}
