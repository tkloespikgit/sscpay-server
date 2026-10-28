<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class PaymentGroup extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'group_key',
        'group_name',
        'logo_path',
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
     *
     * 注意：日/月限额风控与分流均衡的"当天/当月"窗口**不再**用这个时区，统一改成了
     * 系统默认时区——限额是按支付方式跨商户汇总的，窗口跟着各自支付组时区走会让
     * 同一条通道出现多个互不对齐的"当天"（详见 PaymentService::dayRange() 注释）。
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
     * 组内支付方式及路由权重，包含启用和停用记录。
     * 实际下单由 PaymentService 筛选并按负载排序，从前三个候选中随机选择。
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

    /**
     * 落地页上展示的组 Logo 公开地址。
     *
     * 和 CheckoutLink::logoUrl() 同一个前提：落地页访问者是未登录的终端客户，
     * 必须走**公开可读**的盘，用不了争议附件那套 temporaryUrl() 签名地址
     * （签名有有效期，而收款链接是长期挂在广告位上的）。
     *
     * 返回 null 时落地页会回退展示链接自身的 Logo，见落地页视图的降级逻辑。
     */
    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        return Storage::disk(config('checkout.media_disk', 'public'))->url($this->logo_path);
    }
}
