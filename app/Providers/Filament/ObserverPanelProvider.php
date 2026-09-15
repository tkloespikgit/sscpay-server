<?php

namespace App\Providers\Filament;

use App\Filament\Observer\Auth\Login;
use App\Filament\Observer\Resources\OrderResource;
use App\Filament\Observer\Resources\OrderResource\Pages\ListOrders;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

/**
 * 观察者账户面板：绑定 observer guard（App\Models\Observer，见 config/auth.php），
 * 不是 users 表。刻意不复用 Concerns\ConfiguresSharedPanelSettings——那个 trait
 * discoverResources() 指向的 app/Filament/Resources 整批 Resource、以及
 * FilamentTimezone 的闭包都假设当前登录用户是 App\Models\User，混进来会在
 * Observer 请求下出问题。这里手动列出一套精简中间件（对齐共享 trait 里的组合，
 * 去掉了 2FA 相关部分——观察者面板不需要），并显式只注册观察者专属的
 * App\Filament\Observer\Resources\OrderResource，不做目录扫描。
 *
 * authMiddleware 必须显式带上 Authenticate::class：Filament Panel 的
 * authMiddleware 默认是空数组，不加这一条不仅鉴权形同虚设，
 * Auth::shouldUse() 也不会被触发，观察者专属代码里的 auth('observer')->user()
 * 仍然能正常工作（guard 已显式指定），但为了安全必须加上这层路由保护。
 */
class ObserverPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('observer')
            ->path('observer')
            ->domain(config('app.observer_domain'))
            ->authGuard('observer')
            ->login(Login::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->resources([
                OrderResource::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // 订单列表页"本次查询统计"，写法/渲染位置对齐
            // Concerns\ConfiguresSharedPanelSettings 里平台面板/商户面板的同名 render hook
            // （同一个 Blade 视图 resources/views/filament/tables/order-currency-stats.blade.php），
            // 这里没法复用那个 trait（假设登录用户是 User），所以在这个面板单独注册一份，
            // 统计数字经 App\Filament\Observer\Resources\OrderResource::currencyStats()
            // 按观察者的金额显示比例折算。
            ->renderHook(
                TablesRenderHook::TOOLBAR_AFTER,
                function (): string {
                    $livewire = Livewire::current();

                    if (! $livewire instanceof ListOrders) {
                        return '';
                    }

                    return view('filament.tables.order-currency-stats', [
                        'stats' => OrderResource::currencyStats($livewire),
                    ])->render();
                }
            )
            ->maxContentWidth('full')
            ->sidebarCollapsibleOnDesktop();
    }
}
