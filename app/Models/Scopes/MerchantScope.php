<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 多租户隔离的全局 Scope。
 *
 * 重要：这个 Scope 只在"有已登录的 Filament 后台用户"时生效（即 auth()->check() 为 true）。
 * 原因是本系统有两条完全不同的请求路径：
 *
 *   1. Filament 后台（Web 会话登录）：这里天然有 auth()->user()，Scope 据此按
 *      User::manageableMerchantIds() 过滤——平台超级管理员返回 null 代表不受限制，
 *      商户级管理员（merchant_id 为 NULL 但非超管）返回其 ownedMerchants() 的 ID 集合，
 *      普通商户用户返回自己的 merchant_id 单值。
 *
 *   2. 对外 API（App-ID + 签名鉴权，见 ApiAuthentication 中间件）：这条路径根本
 *      没有"登录用户"，merchant_id 是中间件验签后从 Application 记录里查出来
 *      注入到 Request 的。这种场景下 auth()->check() 恒为 false，Scope 不介入，
 *      必须由调用方（Controller / Service）显式 where('merchant_id', $merchantId)
 *      或使用下方 BelongsToMerchant trait 提供的 forMerchant() 局部 Scope。
 *
 *   3. 队列任务 / Artisan 命令（如 order-events:sync、db:backup:upload）同样没有
 *      登录用户，同理需要调用方显式传入 merchant_id 过滤条件。
 *
 * 这样设计是为了避免"Scope 在 API/队列上下文里意外生效或意外不生效"这种更难排查
 * 的隐患——宁可要求调用方显式传参，也不要在无用户上下文里悄悄放行全部数据。
 */
class MerchantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        $merchantIds = $user->manageableMerchantIds();

        if ($merchantIds === null) {
            // 平台超级管理员：不限制。
            return;
        }

        $builder->whereIn($model->getTable().'.merchant_id', $merchantIds);
    }

    /**
     * 供 Model::withoutGlobalScope(MerchantScope::class) 或
     * ->withoutGlobalScopes() 之外，也提供一个显式方法名，方便代码可读性。
     */
    public static function bypass(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope(static::class);
    }
}
