<?php

namespace App\Providers;

use App\Models\Merchant;
use App\Models\OrderShipping;
use App\Models\User;
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
        // instanceof User 判断：观察者面板（App\Models\Observer，见 ObserverPanelProvider）
        // 走独立 guard 登录，同一个全局 Gate::before 也会为它的请求触发，Observer 没有
        // is_super_admin 属性；加这层判断避免以后开启 Eloquent 严格属性模式时在观察者
        // 面板的每一次权限判断上都抛异常。
        Gate::before(function ($user, string $ability) {
            return $user instanceof User && $user->is_super_admin ? true : null;
        });
    }
}
