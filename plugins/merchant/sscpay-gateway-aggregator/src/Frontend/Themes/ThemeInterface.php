<?php
/**
 * 支付页面前端主题契约。
 *
 * 【重要】只负责"视觉呈现"：拿PaymentPage已经准备好的纯数据viewModel，
 * 吐出对应的HTML片段。不允许在实现里读$_GET/$_SERVER、访问数据库、
 * 做状态判断/文案翻译之类的业务逻辑——那些属于PaymentPage的职责，
 * 目的是保证换主题不会意外改变业务行为，只改变长相。
 *
 * 安全相关的部分（防嵌套响应头/meta、Referrer-Policy、html骨架）不在这个
 * 契约里，由PaymentPage统一处理，任何主题都不能绕过或忘记实现。
 *
 * renderLoadingBody() 返回的HTML里必须包含：
 * - id="pga-jump-link" 的<a>标签（倒计时结束后被脚本以.click()触发跳转）；
 * - id="pga-countdown-num" 的元素（脚本会实时改写它的文本为剩余秒数）。
 * 这两个id是PaymentPage::renderLoadingShell()里倒计时脚本的硬编码依赖。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

interface ThemeInterface
{
    /** 后台设置项存的值、内部识别用，只能是[a-z0-9_-]。 */
    public function getSlug();

    /** 后台主题下拉框里展示给管理员看的名称。 */
    public function getLabel();

    /** 主题自己的CSS文件地址（带版本号），文件不存在时返回空字符串。 */
    public function getStylesheetUrl();

    /**
     * 结账页/结果页/取消页共用的"发票风格"订单卡片——包括提示文案（notice）和
     * 操作按钮（actions_html）在内的整张卡片都由主题自己排版，PaymentPage不再
     * 在外部拼接这两部分，因为好几套新主题把按钮/提示放在卡片内部而不是下方
     * （比如侧栏、票根桩），主题需要自己决定放哪。
     *
     * @param array $viewModel 见 PaymentPage::buildInvoiceViewModel()，另含：
     *   - notice: array{title:string,body:string}|null 状态提示文案
     *   - actions_html: string 操作按钮的可信HTML（结账页是网关"前往支付"按钮；
     *     结果页/取消页是"Return to Store"/"Retry Payment"按钮，已拼好，
     *     主题只需原样输出，不用再转义）
     */
    public function renderInvoiceCard(array $viewModel);

    /**
     * loading页的主体内容（logo+倒计时文案+跳转链接），不含<html>/<head>/脚本。
     * @param array $viewModel 见 PaymentPage::renderLoadingShell()
     */
    public function renderLoadingBody(array $viewModel);

    /**
     * 通用的主操作/次操作按钮（"Return to Store"/"Retry Payment"共用）。
     * @param array{url:string,label:string,primary:bool} $viewModel
     */
    public function renderActionButton(array $viewModel);
}
