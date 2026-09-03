<?php

namespace App\Http\Requests\Api;

use App\Models\Carrier;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 外部系统（物流商/商户自建系统）同步物流信息的请求体校验。
 * app_id / sign 已经由 ApiAuthentication 中间件验证过，这里只校验业务字段。
 */
class SyncOrderShippingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_order_no' => ['required', 'string', 'max:64'],
            'logistics_company' => [
                'required',
                'string',
                'max:100',
                // 必须是 CarrierResource 里登记过的承运商代码，否则外部系统传进来的
                // logistics_company 会是一个物流状态同步/追踪都无法识别的野字符串。
                function ($attribute, $value, $fail) {
                    if (! Carrier::isValidCode($value)) {
                        $fail("该物流承运商「{$value}」不在系统支持列表中，请联系管理员添加该承运商后重试。");
                    }
                },
            ],
            'tracking_number' => ['required', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'url', 'max:255'],
            'shipped_at' => ['required', 'date'],
        ];
    }

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
