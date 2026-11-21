<?php
/**
 * 支付页面主题注册表：内置主题在这里登记，后台"外观主题"下拉框
 * （见Admin\SettingsPage）与PaymentPage渲染时都从这里取。
 *
 * 新增内置主题：在 self::registry() 里加一行 new XxxTheme()，别的地方不用改。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

class ThemeManager
{
    const OPTION_NAME = 'pga_payment_theme';
    const DEFAULT_SLUG = 'paper';

    /** @var array<string,ThemeInterface>|null */
    private static $registry = null;

    /** @return array<string,ThemeInterface> slug => 实例 */
    private static function registry()
    {
        if (self::$registry === null) {
            self::$registry = [];
            foreach ([new PaperTheme(), new SplitTheme(), new BentoTheme(), new MidnightTheme(), new EditorialTheme(), new TicketTheme()] as $theme) {
                self::$registry[$theme->getSlug()] = $theme;
            }
        }

        return self::$registry;
    }

    /** 后台下拉框用：slug => 展示名称。 */
    public static function getAvailableLabels()
    {
        $labels = [];
        foreach (self::registry() as $slug => $theme) {
            $labels[$slug] = $theme->getLabel();
        }

        return $labels;
    }

    public static function isValidSlug($slug)
    {
        return isset(self::registry()[$slug]);
    }

    /** 当前生效主题：后台未设置或设置了未知slug时兜底为默认主题。 */
    public static function getActive()
    {
        $slug = get_option(self::OPTION_NAME, self::DEFAULT_SLUG);

        return self::get($slug);
    }

    /**
     * 按slug直接取主题实例，不经过后台保存的option——预览功能用：
     * 管理员在下拉框里选中某个主题但还没点保存时，也能预览它的效果。
     * slug非法时兜底为默认主题，与getActive()口径一致。
     */
    public static function get($slug)
    {
        $registry = self::registry();

        return $registry[$slug] ?? $registry[self::DEFAULT_SLUG];
    }
}
