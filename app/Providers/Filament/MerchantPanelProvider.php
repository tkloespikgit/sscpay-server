<?php

namespace App\Providers\Filament;

use App\Providers\Filament\Concerns\ConfiguresSharedPanelSettings;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * 商户面板：商户自己的管理员登录（见 App\Models\User::canAccessPanel()
 * 按 $panel->getId() === 'merchant' 收窄账号类型，超级管理员/商户级管理员
 * 不能在这个面板登录）。域名限制见 ->domain()，读 config('app.merchant_domain')
 * （对应 .env 的 FILAMENT_MERCHANT_DOMAIN），未配置时为 null，Filament 不限制域名。
 *
 * path() 特意和平台面板（'admin'）不同——两个面板共用同一批 Resource 类、
 * 同一个 discoverResources() 目录（见 ConfiguresSharedPanelSettings），当
 * .env 未配置任何域名时（本地开发的默认状态）两个面板都不限制域名，如果
 * path 也相同就会在同一条 URI 上产生真实的路由冲突——实测会导致其中一个
 * 面板的资源路由整体注册不上（不是"后来者覆盖"这么简单，而是直接消失）。
 * 用不同 path 从根上避免这个问题，生产环境域名配置好之后二者互不影响。
 *
 * 与 AdminPanelProvider 共用同一批 Resource/Page/Widget 及大部分面板配置，
 * 见 Concerns\ConfiguresSharedPanelSettings 的类注释——共享是安全的，商户账号
 * 即使能"发现"到平台专属 Resource，各自的 canViewAny() 也会直接拒绝。
 *
 * 不注册 FilamentLogViewer 插件：那是纯超管专属的平台基础设施，商户账号
 * 永远通不过 authorize() 判断，没必要在这个面板里也加载一遍。
 */
class MerchantPanelProvider extends PanelProvider
{
    use ConfiguresSharedPanelSettings;

    public function panel(Panel $panel): Panel
    {
        // id()/path()/domain() 必须先于 applySharedSettings() 里的 discoverResources() 等
        // 调用——原因见 AdminPanelProvider::panel() 的同名注释。
        $panel = $panel
            ->id('merchant')
            ->path('merchant')
            ->domain(config('app.merchant_domain'));

        return $this->applySharedSettings($panel);
    }
}
