<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentGroup extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'group_key',
        'group_name',
        'description',
        'timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * 生效时区：组上未配置时回退系统时区。
     * 分流算法和日/月限额风控都用它来确定"当天/当月"统计窗口。
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?: (string) config('app.timezone', 'UTC');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * 按组内优先级（priority 越小越优先）排序的支付方式，仅含启用中的。
     */
    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'payment_group_methods', 'group_id', 'method_id')
            ->withPivot('priority')
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    public function activePaymentMethods(): BelongsToMany
    {
        return $this->paymentMethods()->where('payment_methods.is_active', true);
    }
}
