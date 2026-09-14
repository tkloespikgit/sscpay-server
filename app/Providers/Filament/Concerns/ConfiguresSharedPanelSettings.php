<?php

namespace App\Providers\Filament\Concerns;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Pages\AdminDashboard;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

/**
 * 平台面板（AdminPanelProvider）和商户面板（MerchantPanelProvider）共用的配置：
 * 同一批 Resource/Page/Widget、同一个登录/资料页类、同一套中间件、同一段侧边栏样式、
 * 同一个订单列表统计 render hook、同一套 2FA 配置。两个 Provider 只在
 * id()/path()/domain()/default()/plugins() 这几处不同（各自的 panel() 方法里链式追加），
 * 抽到这里避免几十行配置重复一份、以后改一处忘改另一处。
 *
 * 共享同一批 Resource 类文件是安全的——每个 Resource 已经用 canViewAny()/
 * getEloquentQuery() 做了完整的角色级授权与行级隔离，商户账号即使能在商户面板里
 * "发现"到 AdminResource/SystemConfigResource 这些平台专属资源，canViewAny() 也会
 * 直接拒绝，不会泄露任何数据或菜单项。
 */
trait ConfiguresSharedPanelSettings
{
    protected function applySharedSettings(Panel $panel): Panel
    {
        // 全站时间显示统一格式；具体时区由下面的 FilamentTimezone::set() 按当前登录用户
        // 所属商户动态解析，二者共同作用于所有未显式指定格式/时区的 ->dateTime() 调用
        // （目前全项目所有调用都是这种不带参数的写法，无需逐处修改）。
        Table::configureUsing(fn (Table $table) => $table->defaultDateTimeDisplayFormat('Y/m/d H:i:s'));
        Schema::configureUsing(fn (Schema $schema) => $schema->defaultDateTimeDisplayFormat('Y/m/d H:i:s'));

        // 超级管理员/商户管理员没有单一所属商户，回退系统默认时区。
        FilamentTimezone::set(fn () => auth()->user()?->merchant?->timezone ?: config('app.timezone', 'UTC'));

        return $panel
            ->profile(EditProfile::class)
            ->login(Login::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                AdminDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => "
                <style>
                /* 未选中时的菜单文字与图标颜色 */
.fi-sidebar-item-button {
    color: #4b5563 !important;
}

/* 鼠标悬浮在菜单上时的扁平背景（保持扁平化设计） */
.fi-sidebar-item-button:hover {
    background-color: rgba(0, 0, 0, 0.05) !important; /* 浅色模式悬浮 */
}
.dark .fi-sidebar-item-button:hover {
    background-color: rgba(255, 255, 255, 0.05) !important; /* 暗黑模式悬浮 */
}

/* 当前激活/选中菜单的背景色和文字颜色 */
.fi-sidebar-item-active .fi-sidebar-item-button {
    background-color: #e5e7eb !important; /* 选中后的扁平背景块 */
    color: #111827 !important;            /* 选中后的文字颜色 */
}
.dark .fi-sidebar-item-active .fi-sidebar-item-button {
    background-color: #374151 !important;
    color: #ffffff !important;
}
/* 浅色模式右侧主背景 */
body, .fi-main, .fi-layout {
    background-color: #f9fafb !important; /* 这里改成你想要的右侧背景色（例如极浅灰） */
}

/* 暗黑模式右侧主背景 */
.dark body, .dark .fi-main, .dark .fi-layout {
    background-color: #0b0f19 !important; /* 这里改成你想要的暗黑主背景色 */
}
                </style>
                "
            )
            // 订单列表页"本次查询统计"：TablesRenderHook::TOOLBAR_AFTER 在表格 Blade 模板里
            // 调用时不带 scopes（见 vendor/filament/tables/resources/views/index.blade.php），
            // 所以这里注册时也不能传 scopes 去卡表格实例——只能全局注册，
            // 再用 Livewire::current() 在闭包里判断"当前渲染的是不是订单列表页"，
            // 避免这段统计出现在其他资源的表格上。
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
            ->sidebarCollapsibleOnDesktop()
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ]);
    }
}
