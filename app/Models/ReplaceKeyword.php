<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商户级关键词替换表：COPY 商品匹配模式下，把订单商品名里命中的 keyword
 * 替换成 replacement（忽略大小写），替换后的名字用于在站点商品库里找同名商品。
 */
class ReplaceKeyword extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'keyword',
        'replacement',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * 把商户配置的所有关键词替换规则应用到商品名上，忽略大小写。
     * 长关键词优先替换，避免短词先替换掉一部分后污染了本该匹配的长词。
     */
    public static function applyReplacements(string $name, int $merchantId): string
    {
        $rules = static::query()
            ->forMerchant($merchantId)
            ->get(['keyword', 'replacement'])
            ->sortByDesc(fn (self $rule) => mb_strlen($rule->keyword));

        foreach ($rules as $rule) {
            // 替换目标允许为空字符串：str_ireplace 传空串天然就是"删除关键词"。
            $name = str_ireplace($rule->keyword, (string) $rule->replacement, $name);
        }

        // 删除关键词可能在名字中间留下连续空格（如去掉 "Mechanical " 中的 "Mechanical"），
        // 统一收敛成单个空格再首尾 trim。
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }
}
