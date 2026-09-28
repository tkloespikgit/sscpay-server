<?php

namespace App\Http\Requests;

use App\Models\CheckoutLink;
use App\Rules\InternationalPhone;
use App\Services\Checkout\TurnstileService;
use App\Support\Countries;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/**
 * 收款链接落地页的下单表单校验。
 *
 * 这个表单是整个系统唯一「无鉴权 + 可写库 + 会真的去调支付网关」的入口，
 * 所以校验之外还压了四层防刷（见 withValidator()）：
 *   1. Turnstile 人机验证 —— 挡自动化脚本；
 *   2. 蜜罐字段 —— 挡填满所有输入框的傻瓜爬虫；
 *   3. 最短填表耗时 —— 挡「秒填秒交」的脚本；
 *   4. IP / 邮箱 / 链接三个维度的频率限制 —— 挡人肉刷单和撞库式试探。
 * 任何一层单独都能被绕过，叠起来才有意义。
 */
class CreateCheckoutOrderRequest extends FormRequest
{
    private ?CheckoutLink $link = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $link = $this->checkoutLink();

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:30', new InternationalPhone($link->supported_countries)],

            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            // ISO 3166-1 alpha-2。Google Places 的 short_name 正好是这个格式，
            // 前端自动填充时直接取那个值。用白名单而不是 size:2 + alpha——
            // 后者放得过 'ZZ' 这种不存在的代码，脏数据会一路流到订单表。
            'country' => ['required', 'string', 'size:2', Rule::in(array_keys($link->availableCountries()))],
            'zip' => ['nullable', 'string', 'max:20'],

            // 金额只在区间模式下由客户输入；固定金额模式即使前端被改也不会被采纳
            // （CheckoutLink::resolveAmount() 在 fixed 模式下直接忽略这个值）。
            'amount' => $link->isFixedAmount()
                ? ['nullable']
                : [
                    'required',
                    'numeric',
                    'min:'.$link->min_amount,
                    'max:'.$link->max_amount,
                    // 两位小数上限：金额字段是 decimal(15,2)，多出来的小数位会被
                    // 数据库静默截断，导致客户看到的金额和实际扣款对不上。
                    'decimal:0,2',
                ],

            // 蜜罐：真人看不见这个字段（CSS 隐藏），填了就是机器人。
            'company_website' => ['prohibited'],

            // 页面渲染时间戳，加密后随表单一起发出，用于算填表耗时。
            'form_token' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'first_name' => __('checkout.fields.first_name'),
            'last_name' => __('checkout.fields.last_name'),
            'email' => __('checkout.fields.email'),
            'phone' => __('checkout.fields.phone'),
            'address_line1' => __('checkout.fields.address_line1'),
            'address_line2' => __('checkout.fields.address_line2'),
            'city' => __('checkout.fields.city'),
            'state' => __('checkout.fields.state'),
            'country' => __('checkout.fields.country'),
            'zip' => __('checkout.fields.zip'),
            'amount' => __('checkout.fields.amount'),
        ];
    }

    public function messages(): array
    {
        return [
            // 蜜罐命中时不能提示"这个字段不该填"——那等于告诉攻击者机关在哪。
            // 统一报一句含糊的失败信息即可。
            'company_website.prohibited' => __('checkout.validation.submission_rejected'),
            'form_token.required' => __('checkout.validation.form_expired'),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->assertHumanSubmission($validator);
            $this->assertNotTooFast($validator);
            $this->assertWithinRateLimits($validator);
        });
    }

    /**
     * 已通过校验的数据 + 客户端环境字段，直接喂给 CheckoutLinkOrderService。
     */
    public function toOrderData(): array
    {
        return array_merge(
            $this->safe()->except(['company_website', 'form_token']),
            [
                // trustProxies 已配置 Cloudflare 网段，这里拿到的是真实客户 IP
                // 而不是 CF 边缘节点 IP（见 config/checkout.php 的说明）。
                'customer_ip' => $this->ip(),
                'user_agent' => mb_substr((string) $this->userAgent(), 0, 500),
                'accept_language' => mb_substr((string) $this->header('Accept-Language'), 0, 255),
            ]
        );
    }

    public function checkoutLink(): CheckoutLink
    {
        return $this->link ??= CheckoutLink::query()
            ->withoutGlobalScopes()
            ->active()
            ->where('slug', $this->route('slug'))
            ->with(['items', 'domain'])
            ->firstOrFail();
    }

    private function assertHumanSubmission(Validator $validator): void
    {
        $turnstile = app(TurnstileService::class);

        if (! $turnstile->verify($this->input('cf-turnstile-response'), $this->ip())) {
            $validator->errors()->add('cf-turnstile-response', __('checkout.validation.captcha_failed'));
        }
    }

    /**
     * 最短填表耗时。form_token 是页面渲染时用 Crypt 加密的时间戳——加密而不是
     * 明文，否则脚本把时间戳改早几分钟就绕过去了。
     */
    private function assertNotTooFast(Validator $validator): void
    {
        try {
            $renderedAt = (int) Crypt::decryptString((string) $this->input('form_token'));
        } catch (\Throwable) {
            $validator->errors()->add('form_token', __('checkout.validation.form_expired'));

            return;
        }

        $elapsed = time() - $renderedAt;
        $minimum = (int) config('checkout.throttle.min_form_seconds', 3);

        if ($elapsed < $minimum) {
            $validator->errors()->add('form_token', __('checkout.validation.submission_rejected'));
        }

        // 页面开着超过 6 小时再提交：多半是标签页放了一夜，此时汇率、链接配置
        // 都可能已经变了，让客户刷新一次比拿着旧页面下单安全。
        if ($elapsed > 6 * 3600) {
            $validator->errors()->add('form_token', __('checkout.validation.form_expired'));
        }
    }

    /**
     * IP / 邮箱 / 链接三个维度的频率限制。
     *
     * 用 Cache::increment 而不是 RateLimiter::attempt，是因为这里要在校验阶段
     * 只"读"不"消费"——校验没过的提交不应该占掉配额，否则客户填错一次邮箱格式
     * 就白白消耗一次机会。真正的计数在下单成功后由控制器 hit()。
     */
    private function assertWithinRateLimits(Validator $validator): void
    {
        $link = $this->checkoutLink();

        $checks = [
            [self::ipKey($this->ip()), (int) config('checkout.throttle.ip_max_per_hour', 20), 'checkout.validation.too_many_attempts'],
            [self::emailKey((string) $this->input('email')), (int) config('checkout.throttle.email_max_per_day', 10), 'checkout.validation.too_many_attempts'],
            [self::linkKey($link), (int) config('checkout.throttle.link_max_per_day', 500), 'checkout.validation.link_unavailable'],
        ];

        foreach ($checks as [$key, $limit, $message]) {
            if ($limit > 0 && (int) Cache::get($key, 0) >= $limit) {
                $validator->errors()->add('email', __($message));

                return;
            }
        }
    }

    public static function ipKey(?string $ip): string
    {
        return 'checkout:throttle:ip:'.sha1((string) $ip);
    }

    public static function emailKey(string $email): string
    {
        return 'checkout:throttle:email:'.sha1(mb_strtolower(trim($email)));
    }

    public static function linkKey(CheckoutLink $link): string
    {
        return 'checkout:throttle:link:'.$link->id.':'.now()->toDateString();
    }
}
