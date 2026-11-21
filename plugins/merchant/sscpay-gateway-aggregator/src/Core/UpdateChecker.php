<?php
/**
 * 插件在线更新检测器。
 *
 * 这个插件不会发布到 wordpress.org，所以拿不到WP官方仓库的自动更新能力，
 * 需要自己接管"检查新版本 / 在后台显示更新提示 / 点更新后下载新zip"这一整套流程。
 *
 * 没有选择手写（自己实现 pre_set_site_transient_update_plugins 过滤器），
 * 而是用了社区维护的 Plugin Update Checker(PUC) 库：
 * https://github.com/YahnisElsts/plugin-update-checker
 *
 * 原因：这块涉及版本比较、缓存、签名/来源校验、WP更新UI的多个钩子协同，
 * 手写很容易在边界情况上出错（比如版本号格式、更新检测缓存穿透、
 * 私有仓库鉴权），而这是一个支付插件，更新机制出错的代价是线上支付中断，
 * 没有必要为了"自己实现"而承担这个风险，用被广泛验证过的库更稳妥。
 *
 * 使用前提（见 composer.json）：
 *   composer require yahnis-elsts/plugin-update-checker
 */

namespace PaymentGatewayAggregator\Core;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (!defined('ABSPATH')) {
    exit;
}

class UpdateChecker
{
    /** @var object|null PUC返回的updateChecker实例，方便调试/单测时取用 */
    private static $instance = null;

    /**
     * @param string $pluginFile 主插件文件的绝对路径（__FILE__）
     */
    public static function init($pluginFile)
    {
        if (!class_exists(PucFactory::class)) {
            // composer依赖没装（比如直接把源码目录上传上去、没跑composer install），
            // 静默跳过，不影响插件其它功能正常运行，只是没有"在线更新"提示。
            error_log('[sscpay-gateway-aggregator] Plugin Update Checker 库未安装，在线更新功能未启用。');
            return;
        }

        $repoUrl = defined('PGA_UPDATE_REPO_URL')
            ? PGA_UPDATE_REPO_URL
            : 'https://github.com/tkloespikgit/sscpay-gateway-aggregator/';

        $updateChecker = PucFactory::buildUpdateChecker(
            $repoUrl,
            $pluginFile,
            // 必须和插件所在目录名一致，即 wp-content/plugins/{这里} 的目录名
            'sscpay-gateway-aggregator',
            // 检查周期（小时）：默认12小时检查一次；本地调试想立刻看到效果可以改小，
            // 或者直接在WP后台"插件"页面点一下检查更新。注意Puc v5没有setUpdateInterval()，
            // 周期只能在这里传。
            12
        );

        // 私有仓库需要一个有 repo 读权限的 GitHub Personal Access Token。
        // 默认值（空）已内置在插件主文件里；如需填token，建议放到 wp-config.php 里
        // 覆盖（if (!defined()) 保护，优先生效），不要写进代码库：
        //   define('PGA_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx');
        if (defined('PGA_GITHUB_TOKEN') && PGA_GITHUB_TOKEN) {
            $updateChecker->setAuthentication(PGA_GITHUB_TOKEN);
        }

        // 只信任正式 Release 里手动/CI上传的资源包（zip），不要用GitHub
        // 自动生成的"源码压缩包"——那个包里没有 vendor/ 目录（composer依赖
        // 没打进去），装上会直接报 Class not found。见 .github/workflows/release.yml
        // 里"上传release资源包"这一步，两者要配套。
        $vcsApi = $updateChecker->getVcsApi();
        if ($vcsApi && method_exists($vcsApi, 'enableReleaseAssets')) {
            $vcsApi->enableReleaseAssets();
        }

        self::$instance = $updateChecker;
    }

    public static function instance()
    {
        return self::$instance;
    }
}
