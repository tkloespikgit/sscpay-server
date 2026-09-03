<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * 争议审核事件的富文本（开立原因 / 回复内容）在入库前统一走这里过滤 XSS。
 *
 * 故意不复用 Filament 自带的 HtmlSanitizerConfig 单例（见
 * vendor/filament/support/src/SupportServiceProvider.php）——那份默认配置
 * 放行了 style 属性，RichEditor 组件自己的代码注释也明确警告"面向不可信
 * 内容要配置更严格的过滤器"。这里单独建一份更收紧的配置：只保留基础排版
 * 标签，不放行 style / img / table，未识别的标签按 Block（去标签保留文字）
 * 而不是 Drop（连文字一起删掉），避免用户输入被过度吞掉。
 */
final class RichTextSanitizer
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(string $html): string
    {
        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(self::buildConfig());
    }

    private static function buildConfig(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('strike')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(50_000);
    }
}
