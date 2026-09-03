<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shippings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('关联订单主表');
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户（冗余字段，便于查询隔离）');
            $table->string('logistics_company', 100)->comment('物流公司名称（如 DHL、FedEx、顺丰）');
            $table->string('tracking_number', 100)->comment('物流单号');
            $table->string('tracking_url', 255)->nullable()->comment('物流追踪链接');
            $table->timestamp('shipped_at')->useCurrent()->comment('发货时间');
            // 发货记录属于审计追溯数据，操作人被删除不应导致记录一并消失，故改为 restrict
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete()->comment('操作人 ID');
            $table->text('remark')->nullable()->comment('发货备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
            $table->index('tracking_number');
            $table->index('merchant_id');

            // 业务规则：一个订单同一时间只允许存在一条有效物流记录。
            // 若商户后台重新提交物流单号（如补发/改单），应用层要做 updateOrCreate（按 order_id 更新已有记录），
            // 这里用软删除安全的生成列在数据库层兜底，防止并发或误操作绕过应用层逻辑插入第二条有效记录。
            $table->unsignedBigInteger('order_id_uniq')
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, order_id, NULL)')
                ->comment('生成列：仅未删除记录参与 order_id 唯一性校验（每单同一时间只有一条有效物流记录）');
            $table->unique('order_id_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shippings');
    }
};
