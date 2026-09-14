<?php

namespace App\Providers\Filament;

use AchyutN\FilamentLogViewer\FilamentLogViewer;
use App\Providers\Filament\Concerns\ConfiguresSharedPanelSettings;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * 平台面板：超级管理员 + 商户级管理员登录（见 App\Models\User::canAccessPanel()
 * 按 $panel->getId() === 'admin' 收窄账号类型）。域名限制见 ->domain()，
 * 读 config('app.platform_domain')（对应 .env 的 FILAMENT_PLATFORM_DOMAIN），
 * 未配置时为 null，Filament 不限制域名——本地开发不受影响。
 *
 * 与 MerchantPanelProvider 共用同一批 Resource/Page/Widget 及大部分面板配置，
 * 见 Concerns\ConfiguresSharedPanelSettings 的类注释。
 */
class AdminPanelProvider extends PanelProvider
{
    use ConfiguresSharedPanelSettings;

    public function panel(Panel $panel): Panel
    {
        // id()/path()/domain() 必须先于 applySharedSettings() 里的 discoverResources() 等
        // 调用——discoverResources() 内部会检查缓存路径（键里带 getId()），id() 没设置就调用
        // getId() 会抛 LogicException("A panel has been registered without an `id()`.")。
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain(config('app.platform_domain'));

        return $this->applySharedSettings($panel)
            ->plugins([
                // Laravel 日志查看（achyutn/filament-log-viewer）：挂在"平台管理"分组下，仅超管可见。
                // 导航分组/标签用闭包传入，保证在请求期解析，走 admin.nav / admin.log_viewer 翻译键。
                FilamentLogViewer::make()
                    ->authorize(fn (): bool => (bool) auth()->user()?->is_super_admin)
                    ->navigationGroup(fn (): string => __('admin.nav.platform'))
                    ->navigationLabel(fn (): string => __('admin.log_viewer.nav_label'))
                    ->navigationIcon('heroicon-o-document-text'),
            ]);
    }
}
