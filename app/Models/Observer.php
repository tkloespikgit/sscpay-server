<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * 观察者账户：独立于 users 表的一套账号体系（见 config/auth.php 的 observer
 * guard/provider、App\Providers\Filament\ObserverPanelProvider），不接入
 * spatie/laravel-permission，唯一的"权限"就是绑定了哪些 PaymentMethod——
 * 登录后只能看到这些支付方式下的订单（见 App\Filament\Observer\Resources\OrderResource）。
 *
 * amount_display_ratio：观察者看到的所有订单金额按这个百分比折算显示（纯展示层，
 * 不改变数据库里的真实金额），只有超级管理员能设置，见 ObserverResource 表单里
 * 对该字段的可见性控制 + CreateObserver/EditObserver 里的服务端兜底。
 */
class Observer extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    /**
     * 建号只填"账号"，不要求真实邮箱：账号里没带 @ 就自动拼上这个内部域名存进
     * email 列登录用，写法对齐 App\Models\User::emailForAccount()（同一套思路，
     * 单独开一个域名只是为了和 User 的账号命名空间区分开，两套账号体系彼此独立）。
     */
    public const ACCOUNT_EMAIL_DOMAIN = 'observer.example.com';

    public static function emailForAccount(string $account): string
    {
        return str_contains($account, '@') ? $account : "{$account}@".self::ACCOUNT_EMAIL_DOMAIN;
    }

    /**
     * emailForAccount() 的反函数，供编辑页把已存的 email 回填成 account 输入框。
     */
    public static function accountFromEmail(?string $email): string
    {
        if (! $email) {
            return '';
        }

        $suffix = '@'.self::ACCOUNT_EMAIL_DOMAIN;

        return str_ends_with($email, $suffix) ? substr($email, 0, -strlen($suffix)) : $email;
    }

    protected $fillable = [
        'owner_id',
        'name',
        'email',
        'password',
        'status',
        'amount_display_ratio',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 建号时自动把创建人落成当前登录的后台账号——商户级管理员建的观察者归他自己管，
     * 超管建的也记在超管名下（超管本来就不受 owner 限制，记下来只是便于追溯）。
     * 用 creating 而不是 saving：归属只在创建时确定，之后只有超管能在表单里显式改。
     * instanceof User 判断：观察者面板自己的登录态是 observer guard，
     * auth()->user() 会解析成 Observer，不能拿来当 owner。
     */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->owner_id) && auth()->check() && auth()->user() instanceof User) {
                $model->owner_id = auth()->id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'boolean',
            'amount_display_ratio' => 'decimal:2',
        ];
    }

    /**
     * 创建人（谁建的谁管），NULL 表示平台直管、只有超管能维护。
     * 可见/可编辑范围见 ObserverResource::canManageRecord()。
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'observer_payment_methods');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'observer' && $this->status;
    }

    /**
     * 按 amount_display_ratio 折算展示金额，纯展示层用途（订单列表/详情、争议事件
     * 冻结金额等），不写回任何记录。用 bcmath 保持和 Order::isAmountValid() 一致的
     * 精度处理风格，避免浮点误差。
     */
    public function scaleAmount(string|float|null $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return bcdiv(bcmul((string) $amount, (string) $this->amount_display_ratio, 4), '100', 2);
    }
}
