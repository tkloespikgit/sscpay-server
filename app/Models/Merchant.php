<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'contact_person',
        'contact_phone',
        'contact_email',
        'allowed_domains',
        'status',
        'balance',
        'frozen_balance',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'status' => 'boolean',
            'balance' => 'decimal:2',
            'frozen_balance' => 'decimal:2',
        ];
    }

    /**
     * 可提现余额 = 总余额 - 冻结余额（冻结部分为审核中的提现单占用）。
     */
    public function availableBalance(): string
    {
        return bcsub((string) $this->balance, (string) $this->frozen_balance, 2);
    }

    public function balanceTransactions(): HasMany
    {
        return $this->hasMany(MerchantBalanceTransaction::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(MerchantWithdrawal::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function paymentGroups(): HasMany
    {
        return $this->hasMany(PaymentGroup::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function orderShippings(): HasMany
    {
        return $this->hasMany(OrderShipping::class);
    }

    public function orderEvents(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function logisticsImportTasks(): HasMany
    {
        return $this->hasMany(LogisticsImportTask::class);
    }

    public function telegramBot(): HasOne
    {
        return $this->hasOne(TelegramBot::class);
    }

    /**
     * 校验回调域名是否在白名单内（用于校验 notify_url / return_url / cancel_url 等
     * 商户传入的回跳地址是否合法，防止 SSRF 或跳转到非商户自己的域名）。
     */
    public function isDomainAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return false;
        }

        return in_array($host, $this->allowed_domains ?? [], true);
    }
}
