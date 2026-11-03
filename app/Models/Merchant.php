<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * 兼容历史非空列；商户创建不再要求提供联系方式。
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'contact_person' => '',
        'contact_phone' => '',
        'contact_email' => '',
    ];

    protected $fillable = [
        'owner_id',
        'name',
        'contact_person',
        'contact_phone',
        'contact_email',
        'status',
        'timezone',
        'balance',
        'frozen_balance',
        'pending_settlement_balance',
        'pending_withdrawal_balance',
        'settlement_mode',
        'settlement_days',
        'default_fee_percent',
        'default_fee_fixed',
        'default_refund_fee',
        'default_chargeback_fee',
        'default_reserve_percent',
        'default_reserve_days',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'balance' => 'decimal:2',
            'frozen_balance' => 'decimal:2',
            'pending_settlement_balance' => 'decimal:2',
            'pending_withdrawal_balance' => 'decimal:2',
            'settlement_days' => 'integer',
            'default_fee_percent' => 'decimal:4',
            'default_fee_fixed' => 'decimal:2',
            'default_refund_fee' => 'decimal:2',
            'default_chargeback_fee' => 'decimal:2',
            'default_reserve_percent' => 'decimal:4',
            'default_reserve_days' => 'integer',
        ];
    }

    /**
     * 旧提现占用仍留在 frozen_balance，新提现独立记账，不重写历史余额。
     */
    public function availableBalance(): string
    {
        return bcsub(bcsub(bcsub(
            (string) $this->balance,
            (string) $this->frozen_balance,
            2,
        ), (string) ($this->pending_settlement_balance ?? '0'), 2), (string) ($this->pending_withdrawal_balance ?? '0'), 2);
    }

    public function legacyPendingWithdrawals(): HasMany
    {
        return $this->withdrawals()->where('status', MerchantWithdrawal::STATUS_PENDING)
            ->where('uses_pending_balance', false);
    }

    public function pendingWithdrawalBalance(): string
    {
        return bcadd((string) ($this->pending_withdrawal_balance ?? '0'), $this->legacyPendingWithdrawalBalance(), 2);
    }

    public function reserveBalance(): string
    {
        return bcsub((string) $this->frozen_balance, $this->legacyPendingWithdrawalBalance(), 2);
    }

    private function legacyPendingWithdrawalBalance(): string
    {
        return (string) ($this->getAttribute('legacy_pending_withdrawal_balance')
            ?? $this->legacyPendingWithdrawals()->withoutGlobalScopes()->sum('amount'));
    }

    public function paymentSettings(): HasMany
    {
        return $this->hasMany(MerchantPaymentSetting::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(OrderSettlement::class);
    }

    /**
     * 后台时间显示时区，留空回退系统时区（与 PaymentGroup::effectiveTimezone() 同一模式）。
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?: (string) config('app.timezone', 'UTC');
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

    /**
     * 被分配可使用的系统级支付方式（payment_methods.merchant_id 为空，见 PaymentMethod::assignedMerchants()）。
     */
    public function assignedPaymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'merchant_payment_methods')
            ->using(MerchantPaymentMethod::class)
            ->withTimestamps();
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
