<?php

namespace App\Providers\Filament;

use AchyutN\FilamentLogViewer\FilamentLogViewer;
use App\Filament\Pages\AdminDashboard;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Tables\View\TablesRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Support\Enums;
use Livewire\Livewire;


class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->profile()
            ->login()
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
            ->plugins([
                // Laravel 日志查看（achyutn/filament-log-viewer）：挂在"平台管理"分组下，仅超管可见。
                // 导航分组/标签用闭包传入，保证在请求期解析，走 admin.nav / admin.log_viewer 翻译键。
                FilamentLogViewer::make()
                    ->authorize(fn (): bool => (bool) auth()->user()?->is_super_admin)
                    ->navigationGroup(fn (): string => __('admin.nav.platform'))
                    ->navigationLabel(fn (): string => __('admin.log_viewer.nav_label'))
                    ->navigationIcon('heroicon-o-document-text'),
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
                \Filament\View\PanelsRenderHook::HEAD_END,
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
