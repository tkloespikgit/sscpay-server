<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasFactory;
    use HasRoles;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;
    use SoftDeletes;

    /**
     * merchant_id 不在这里，因为它是通过独立迁移
     * (2026_07_05_000017_add_merchant_id_to_users_table) 加上的列，
     * 但对 Eloquent 而言就是普通字段，直接加进 $fillable 即可。
     */
    protected $fillable = [
        'merchant_id',
        'name',
        'email',
        'password',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * 平台超级管理员：merchant_id 为 NULL。
     * 普通商户用户：merchant_id 必须指向所属商户。
     * 商户级管理员：merchant_id 也为 NULL，但 is_super_admin = false，
     * 通过 ownedMerchants() 名下的商户集合来限定可管理范围（见 isMerchantManager()）。
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * 商户级管理员名下的商户（merchants.owner_id 指向本用户）。
     */
    public function ownedMerchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'owner_id');
    }

    /**
     * 商户级管理员：平台侧账号（不挂靠具体商户），但不是无限制的超级管理员，
     * 只能管理 ownedMerchants() 名下的商户及其业务数据。
     */
    public function isMerchantManager(): bool
    {
        return ! $this->is_super_admin && is_null($this->merchant_id);
    }

    /**
     * 平台侧账号（超级管理员 或 商户级管理员），区别于绑定单一商户的普通商户用户。
     * 各 Resource 里"是否展示归属商户列/筛选/可自由选择商户"这类 UI 判断统一走这个方法，
     * 而不是零散地各写一遍 is_super_admin || isMerchantManager()。
     */
    public function isPlatformStaff(): bool
    {
        return $this->is_super_admin || $this->isMerchantManager();
    }

    /**
     * 该用户可管理的商户 ID 集合。
     * 返回 null 代表"不限"（超级管理员），调用方看到 null 就不加 whereIn 限制；
     * 否则返回具体的商户 ID 数组（商户级管理员为名下商户，普通商户用户为自己所在的那一个）。
     *
     * @return array<int>|null
     */
    public function manageableMerchantIds(): ?array
    {
        if ($this->is_super_admin) {
            return null;
        }

        if ($this->isMerchantManager()) {
            return $this->ownedMerchants()->pluck('id')->all();
        }

        return [$this->merchant_id];
    }

    /**
     * Filament 面板访问权限判断（如需限制哪些用户能进后台可在此扩展，
     * 例如要求商户 status = true 才能登录）。
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_super_admin || $this->isMerchantManager()) {
            return true;
        }

        return $this->merchant()->exists() && (bool) $this->merchant?->status;
    }
}
