<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * method_code 改为全局唯一，不再按商户/系统级分开算。
 *
 * 原来的唯一性是 (merchant_id_uniq, method_code_uniq)：同一商户内不重复、系统级
 * 记录之间不重复，但跨商户可以重名。系统级支付方式（merchant_id 为空、可分配给
 * 多个商户使用）出现后这个口径会产生歧义——商户自己有一条 paypal，又被分配了一条
 * 系统级 paypal，按"商户 + code"反查会撞到两条，API 下单指定 payment_method_key
 * 时取到哪条不确定。改成全局唯一后 method_code 可以独立作为支付方式的业务主键。
 *
 * 同时删除 merchant_id_uniq 生成列：它只是为了让 NULL 商户参与复合唯一索引而存在
 * （IFNULL(merchant_id, 0)），复合索引去掉后就没有用处了。虚拟生成列不占存储，
 * 删除不会丢任何数据，down() 里会原样恢复。
 *
 * 升级前提：库里不能有重复的 method_code（只算未软删的记录）。有冲突时这里会直接
 * 抛异常中止并列出冲突记录，需要人工先把其中一方改名——改名会影响历史订单上冗余
 * 存的 orders.payment_method 以及商户侧已在用的 payment_method_key，不适合自动处理。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstDuplicateCodes();

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['merchant_id_uniq', 'method_code_uniq']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unique('method_code_uniq');
            $table->dropColumn('merchant_id_uniq');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['method_code_uniq']);

            $table->unsignedBigInteger('merchant_id_uniq')
                ->nullable()
                ->virtualAs('IFNULL(merchant_id, 0)')
                ->comment('生成列：merchant_id 为空（系统级）时按 0 参与唯一性校验，避免系统级记录间 method_code 重复');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unique(['merchant_id_uniq', 'method_code_uniq']);
        });
    }

    /**
     * 未软删的记录里如果已经有重复 method_code，加全局唯一索引会直接失败于
     * "Duplicate entry"，报错里只看得到第一条冲突值。这里提前查出全部冲突并
     * 给出可读的清单，方便一次性处理完再重跑。
     */
    private function guardAgainstDuplicateCodes(): void
    {
        $duplicates = DB::table('payment_methods')
            ->whereNull('deleted_at')
            ->select('method_code', DB::raw('COUNT(*) as total'), DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'))
            ->groupBy('method_code')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = $duplicates
            ->map(fn ($row) => "{$row->method_code}（payment_methods.id: {$row->ids}）")
            ->implode('；');

        throw new RuntimeException(
            'payment_methods.method_code 存在重复，无法建立全局唯一索引，请先手动改名后重试：'.$detail
        );
    }
};
