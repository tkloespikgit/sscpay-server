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
        'email_verified_at',
        'password',
        'is_super_admin',
        'status',
    ];

    /**
     * 后台"建用户"的地方统一只填一个"账号"，不要求真实邮箱：账号里没带 @ 就
     * 自动拼上这个内部域名存进 email 列；账号里已经带 @（比如历史上的真实邮箱
     * 账号）就原样当邮箱用，不做二次改写——这样老账号（比如现有的超级管理员）
     * 不会因为这次改造被锁死登不进去，不需要额外写数据迁移。
     */
    public const ACCOUNT_EMAIL_DOMAIN = 'test.example.com';

    public static function emailForAccount(string $account): string
    {
        return str_contains($account, '@') ? $account : "{$account}@".self::ACCOUNT_EMAIL_DOMAIN;
    }

    /**
     * emailForAccount() 的反函数，供编辑页把已存的 email 回填成 account 输入框：
     * 匹配内部域名后缀就拆出账号部分，否则（历史真实邮箱账号）原样整个显示。
     */
    public static function accountFromEmail(?string $email): string
    {
        if (! $email) {
            return '';
        }

        $suffix = '@'.self::ACCOUNT_EMAIL_DOMAIN;

        return str_ends_with($email, $suffix) ? substr($email, 0, -strlen($suffix)) : $email;
    }

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
            'status' => 'boolean',
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
     * Filament 面板访问权限判断。账号自身 status = false（被禁用）一律拒绝登录，
     * 不区分超级管理员/商户级管理员/普通商户用户——账号状态是比角色更基础的一道闸门。
     *
     * 平台侧账号（超级管理员/商户级管理员）和普通商户账号分属两个不同的面板
     * （分别绑定不同域名，见 AdminPanelProvider/MerchantPanelProvider 的 ->domain()），
     * 这里按 $panel->getId() 再收窄一层账号类型——即使有人绕过域名限制直接访问
     * 某个面板的 /admin/login（比如内网直连 IP），账号类型和面板对不上也一样登不进去，
     * 域名限制和这里的角色限制是两道独立的闸，不是互相替代。
     *
     * 普通商户用户还要再叠加一层：所属商户本身 status = false（商户被整体禁用）
     * 时也不能登录，即便账号自身状态是启用的。
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->status) {
            return false;
        }

        $isPlatformAccount = $this->is_super_admin || $this->isMerchantManager();

        if ($panel->getId() === 'admin') {
            return $isPlatformAccount;
        }

        return (! $isPlatformAccount) && $this->merchant()->exists() && (bool) $this->merchant?->status;
    }
}
