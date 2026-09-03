<?php

namespace App\Models\Concerns;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * 用于所有带 merchant_id 字段的业务 Model（Application、PaymentMethod、PaymentGroup、
 * Order、OrderShipping、OrderEvent、LogisticsImportTask、TelegramBot）。
 *
 * 提供：
 *   1. 自动注册 MerchantScope 全局 Scope（详见该类注释：仅在已登录 Web 用户时生效）。
 *   2. 创建记录时，若调用方没有显式传 merchant_id，且当前有已登录的非超管用户，
 *      自动回填为该用户的 merchant_id —— 主要方便 Filament 后台的表单提交场景，
 *      不需要每个 Resource 都手动 set 一遍。
 *   3. scopeForMerchant()：显式按商户 ID 过滤，绕开登录态判断，专供 API / 队列 /
 *      命令行等没有 auth 用户的上下文使用。
 */
trait BelongsToMerchant
{
    public static function bootBelongsToMerchant(): void
    {
        static::addGlobalScope(new MerchantScope);

        static::creating(function ($model) {
            if (empty($model->merchant_id) && auth()->check() && ! auth()->user()->is_super_admin) {
                $model->merchant_id = auth()->user()->merchant_id;
            }
        });
    }

    /**
     * 显式按商户过滤，绕开 auth() 判断。API 鉴权中间件、队列 Job、Artisan 命令
     * 一律没有登录用户，必须用这个而不是依赖全局 Scope。
     */
    public function scopeForMerchant(Builder $query, int $merchantId): Builder
    {
        return $query->withoutGlobalScope(MerchantScope::class)
            ->where($this->getTable().'.merchant_id', $merchantId);
    }
}
