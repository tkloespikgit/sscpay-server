<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCheckoutOrderRequest;
use App\Models\CheckoutLink;
use App\Models\Order;
use App\Services\Checkout\CheckoutLinkOrderService;
use App\Services\Checkout\TurnstileService;
use App\Support\CheckoutCountryRecommendation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 收款链接落地页（公开页面，无鉴权）。
 *
 * 访问控制的三道门：
 *   1. slug 不可猜测（24 位随机串），和 PaymentPageController 的 token 同思路；
 *   2. 链接必须启用（is_active）；
 *   3. 请求的 Host 必须在 CheckoutLink::allowedHosts() 里——防止 A 商户的域名
 *      被用来打开 B 商户的链接页面（钓鱼）。
 *
 * 订单状态的流转不在这里发生：本控制器只负责建单 + 把客户送去支付网关收银台，
 * pending → paid 一律由 /api/webhooks/payment-gateway/status 驱动
 * （见 PaymentGatewayWebhookController → OrderPaymentStatusService），
 * 那条链路已经接好了入账、商户通知、Telegram 与广告转化上报。
 */
class CheckoutLinkController extends Controller
{
    public function __construct(
        private readonly CheckoutLinkOrderService $orderService,
        private readonly TurnstileService $turnstile,
    ) {}

    public function show(Request $request, string $slug): View
    {
        $link = $this->resolveLink($request, $slug);
        $countries = $link->availableCountries();

        return view('checkout.show', [
            'link' => $link,
            'countries' => $countries,
            'recommendedCountry' => CheckoutCountryRecommendation::forRequest($request, $countries)
                ?? (count($link->supported_countries ?? []) === 1 ? array_key_first($countries) : null),
            'turnstileSiteKey' => $this->turnstile->siteKey(),
            'googleMapsKey' => filled($link->google_maps_browser_key)
                ? trim($link->google_maps_browser_key)
                : null,
            // 加密的渲染时间戳，提交时用来算填表耗时（见 CreateCheckoutOrderRequest）
            'formToken' => Crypt::encryptString((string) time()),
        ]);
    }

    public function store(CreateCheckoutOrderRequest $request, string $slug): RedirectResponse
    {
        $link = $this->resolveLink($request, $slug);

        try {
            $order = $this->orderService->createOrder($link, $request->toOrderData());
        } catch (\Throwable $e) {
            // 具体错误只进日志，不回显给客户：这些异常信息里带着支付组配置、
            // 风控阈值、汇率等内部细节，暴露给公网访问者等于免费的情报。
            Log::warning('收款链接下单失败', [
                'checkout_link_id' => $link->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()
                ->withInput($request->except(['form_token', 'cf-turnstile-response']))
                ->with('checkout_error', __('checkout.errors.order_failed'));
        }

        $this->recordSubmission($request, $link);

        // 供回跳页面展示订单号与当前状态。只存 ID 不存整个订单，
        // session 里放客户的收货地址等 PII 没有必要。
        $request->session()->put('checkout_last_order_id', $order->id);

        // 正常情况下 OrderCreationService 第 9 步已经把远端收银台地址写进 pay_url。
        // 拿不到说明远端建单失败（站点凭证没配齐、插件报错等），订单已经落库但
        // 客户无处可付——退回到本系统的托管付款页，商户可以在后台看到这笔 pending
        // 订单并重新发送付款链接。
        if (blank($order->pay_url)) {
            Log::warning('收款链接下单成功但未取得 pay_url', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
            ]);

            return redirect()->route('payment.show', $order->payment_link_token);
        }

        return redirect()->away($order->pay_url);
    }

    /**
     * 支付网关回跳的成功页。这里**不做任何状态判断之外的事**——客户被跳回来
     * 只说明他从网关页面离开了，不代表钱已经到账。真实状态以 webhook 为准，
     * 所以页面上展示的是订单当前的实际状态，而不是无条件说"支付成功"。
     */
    public function success(Request $request, string $slug): View
    {
        return view('checkout.result', [
            'link' => $this->resolveLink($request, $slug),
            'outcome' => 'success',
            'order' => $this->resolveRecentOrder($request),
        ]);
    }

    public function cancelled(Request $request, string $slug): View
    {
        return view('checkout.result', [
            'link' => $this->resolveLink($request, $slug),
            'outcome' => 'cancelled',
            'order' => $this->resolveRecentOrder($request),
        ]);
    }

    /**
     * 按 slug + Host 定位链接。任何一项不满足都返回 404（而不是更具体的提示）
     * ——对未鉴权的访问者，"链接不存在"和"链接已停用"不应该被区分出来，
     * 否则可以用来探测哪些 slug 是真实存在的。
     */
    private function resolveLink(Request $request, string $slug): CheckoutLink
    {
        $link = CheckoutLink::query()
            ->withoutGlobalScopes()
            ->active()
            ->where('slug', $slug)
            ->with(['items', 'domain', 'merchant'])
            ->first();

        if (! $link) {
            throw new NotFoundHttpException;
        }

        if (! in_array(strtolower($request->getHost()), $link->allowedHosts(), true)) {
            throw new NotFoundHttpException;
        }

        return $link;
    }

    /**
     * 回跳页面上要展示的订单。支付网关回跳时通常不带我们的订单号，
     * 所以用 store() 阶段写进 session 的那一笔。取不到就只显示通用提示。
     */
    private function resolveRecentOrder(Request $request): ?Order
    {
        $orderId = $request->session()->get('checkout_last_order_id');

        if (! $orderId) {
            return null;
        }

        return Order::query()->withoutGlobalScopes()->find($orderId);
    }

    /**
     * 下单成功后才记频率计数。放在这里而不是校验阶段，是为了让"填错格式被打回"
     * 的正常客户不白白消耗配额——真正要限制的是成功建单的速率。
     */
    private function recordSubmission(CreateCheckoutOrderRequest $request, CheckoutLink $link): void
    {
        Cache::add($key = CreateCheckoutOrderRequest::ipKey($request->ip()), 0, now()->addHour());
        Cache::increment($key);

        Cache::add($key = CreateCheckoutOrderRequest::emailKey((string) $request->input('email')), 0, now()->addDay());
        Cache::increment($key);

        Cache::add($key = CreateCheckoutOrderRequest::linkKey($link), 0, now()->endOfDay());
        Cache::increment($key);
    }
}
