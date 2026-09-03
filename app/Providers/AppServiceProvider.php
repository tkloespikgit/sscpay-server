<?php

namespace App\Providers;

use App\Models\Merchant;
use App\Models\OrderShipping;
use App\Observers\MerchantObserver;
use App\Observers\OrderShippingObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        OrderShipping::observe(OrderShippingObserver::class);
        Merchant::observe(MerchantObserver::class);

        // 超级管理员自动通过所有权限判断（Gate::before 短路后面所有 can() 检查），
        // 这样各 Filament Resource 只需要写"需要哪个具体权限"，不用每处都
        // 额外写一遍 "|| auth()->user()->is_super_admin"。
        Gate::before(function ($user, string $ability) {
            return $user->is_super_admin ? true : null;
        });
    }
}
