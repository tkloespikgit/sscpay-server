<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'owner_id',
        'name',
        'contact_person',
        'contact_phone',
        'contact_email',
        'status',
        'balance',
        'frozen_balance',
        'remark',
    ];

    protected function casts(): array
    {
        return [
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

    /**
     * 所属商户级管理员（平台侧账号）。NULL 表示由平台超管直接管理。
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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
}
