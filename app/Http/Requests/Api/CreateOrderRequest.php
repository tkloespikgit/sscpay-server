<?php

namespace App\Http\Requests\Api;

use App\Models\Order;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * 对应 6.1 节的请求体结构。app_id / sign 已经由 ApiAuthentication 中间件
 * 验证过了，这里不重复校验其格式，只校验业务字段。
 *
 * 金额公式（amount = subtotal + shipping_fee - discount + tax）不在这里校验
 * ——那是 2.1 节的"铁律"，交给 Order::isAmountValid() 在 OrderCreationService
 * 里统一处理，理由是：那条校验失败需要走专门的 AMOUNT_MISMATCH 错误码，
 * 和普通的字段格式校验（这里用的是标准校验失败响应）不是一回事，
 * 混在一起会让 API 的错误响应格式不一致。
 *
 * 同理，指定支付渠道（payment_method_key）时的"回跳地址必须落在该渠道绑定的
 * 电商网站域名上"也不在这里校验——那需要先把支付方式查出来，属于业务规则，
 * 在 OrderCreationService 里做，走专门的 PAYMENT_METHOD_DOMAIN_MISMATCH 错误码。
 * 这里只保证"指定渠道时三个回跳地址必须传"这个纯格式约束。
 */
class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 鉴权已经在 ApiAuthentication 中间件里做完了（App-ID+签名），
        // 走到这里说明请求已经通过验证，直接放行。
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_order_no' => ['required', 'string', 'max:64'],
            // 电商网站平台类型枚举，合法值由系统配置 order.platforms（JSON 数组）
            // 动态维护，不在列表内直接拒单（走标准 422 校验错误）。
            'platform' => ['required', 'string', 'max:50', Rule::in(Order::supportedPlatforms())],
            'currency' => ['required', 'string', 'size:3'],
            'group_key' => ['required', 'string', 'max:50'],
            // 指定支付渠道（取值为 payment_methods.method_code，商户内唯一）：
            // 传了就直接用这个渠道收款，跳过支付组内的加权分配与单笔/日/月限额风控；
            // 不传则维持原逻辑，由 PaymentService 按组路由。两种模式下 group_key 都必填。
            'payment_method_key' => ['nullable', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'shipping_fee' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
            'tax' => ['required', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0'],

            'customer.first_name' => ['required', 'string', 'max:100'],
            'customer.last_name' => ['required', 'string', 'max:100'],
            'customer.email' => ['required', 'email', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:30'],

            'shipping_address.line1' => ['required', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:100'],
            'shipping_address.state' => ['nullable', 'string', 'max:100'],
            'shipping_address.country' => ['required', 'string', 'size:2'],
            'shipping_address.zip' => ['required', 'string', 'max:20'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_sku' => ['nullable', 'string', 'max:64'],
            // 商品唯一标识与商品页链接：下单必填，用于后续商品匹配/对账。
            'items.*.product_id' => ['required', 'string', 'max:64'],
            'items.*.product_url' => ['required', 'url', 'max:500'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.product_description' => ['nullable', 'string'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],

            // 指定支付渠道时三个回跳地址全部必填（域名一致性在 OrderCreationService 里校验）：
            // 跳过风控的对价就是这笔交易必须完整落在该渠道绑定的站点上，缺任何一个都拒单。
            // required_with 是隐式规则，即使同时标了 nullable 也照样执行。
            'notify_url' => ['required_with:payment_method_key', 'nullable', 'url', 'max:500'],
            'return_url' => ['required_with:payment_method_key', 'nullable', 'url', 'max:500'],
            'cancel_url' => ['required_with:payment_method_key', 'nullable', 'url', 'max:500'],
        ];
    }

    /**
     * 把嵌套的 customer / shipping_address 展平成 OrderCreationService 期望的
     * 扁平结构，同时补上客户端环境信息（IP / User-Agent / Accept-Language），
     * 这些字段不来自请求体，而是 API 场景特有的、从 Request 本身读取。
     */
    public function toOrderCreationData(): array
    {
        $validated = $this->validated();

        return array_merge($validated, [
            'customer_first_name' => $validated['customer']['first_name'],
            'customer_last_name' => $validated['customer']['last_name'],
            'customer_email' => $validated['customer']['email'],
            'customer_phone' => $validated['customer']['phone'],
            'shipping_address_line1' => $validated['shipping_address']['line1'],
            'shipping_address_line2' => $validated['shipping_address']['line2'] ?? null,
            'shipping_city' => $validated['shipping_address']['city'],
            'shipping_state' => $validated['shipping_address']['state'] ?? null,
            'shipping_country' => $validated['shipping_address']['country'],
            'shipping_zip' => $validated['shipping_address']['zip'],
            'customer_ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'accept_language' => $this->header('Accept-Language'),
        ]);
    }

    /**
     * 覆盖默认的校验失败响应，统一成本系统的 API 错误格式
     * （{code, msg}，与 ApiAuthentication 中间件的错误格式保持一致）。
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'code' => 422,
                'msg' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
