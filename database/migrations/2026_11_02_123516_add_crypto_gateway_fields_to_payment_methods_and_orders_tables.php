<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 加密收款：订单快照链/币/合约/精度/地址；渠道参数走 PaymentMethodConfigMap + config。
 * 是否加密由模板 payment_config_tag（crypto / crypto_*）约定，不增加 gateway_driver 列。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('crypto_chain', 32)->nullable()->after('payment_method_id')->comment('链上收款链键快照（如 tron / ethereum）');
            $table->string('crypto_symbol', 32)->nullable()->after('crypto_chain')->comment('链上币种符号快照（如 USDT）');
            $table->string('crypto_token_contract', 128)->nullable()->after('crypto_symbol')->comment('代币合约地址快照；原生币为空');
            $table->string('crypto_receive_address', 128)->nullable()->after('crypto_token_contract')->comment('收款地址快照');
            $table->decimal('crypto_amount_received', 20, 8)->nullable()->after('crypto_receive_address')->comment('链上已累计到账额');
            $table->timestamp('crypto_expires_at')->nullable()->after('crypto_amount_received')->comment('链上 pending 过期时间');
            $table->json('crypto_txids')->nullable()->after('crypto_expires_at')->comment('已计入到账的交易哈希列表');

            $table->index(['crypto_receive_address', 'status', 'crypto_expires_at'], 'orders_crypto_listen_idx');
        });

        if (! DB::table('payment_method_config_maps')->where('name', 'TRON USDT')->exists()) {
            DB::table('payment_method_config_maps')->insert([
                'name' => 'TRON USDT',
                'payment_config_tag' => 'crypto_tron_usdt',
                'is_active' => true,
                'fields' => json_encode([
                    ['key' => 'chain', 'label' => '区块链（如 tron）', 'required' => true],
                    ['key' => 'symbol', 'label' => '币种符号（如 USDT）', 'required' => true],
                    ['key' => 'token_contract', 'label' => '代币合约地址（USDT 等代币必填；收 TRX/ETH 等原生币则留空）', 'required' => false],
                    ['key' => 'receive_address', 'label' => '收款地址', 'required' => true],
                    ['key' => 'tatum_api_key', 'label' => 'Tatum API Key', 'required' => true],
                    ['key' => 'tatum_base_url', 'label' => 'Tatum API 地址（默认 https://api.tatum.io，可留空）', 'required' => false],
                    ['key' => 'tatum_webhook_url', 'label' => 'Tatum 回调 URL（须 https 公网；留空则用系统默认）', 'required' => false],
                    // amount_scale 不进模板：代码默认 3（千分位互斥）
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 含历史上曾存在、现已从 up() 移除的列，便于回滚已跑过旧版迁移的本地库。
        $columns = array_values(array_filter([
            'crypto_chain',
            'crypto_symbol',
            'crypto_token_contract',
            'crypto_amount_scale',
            'crypto_quote_rate',
            'crypto_quote_at',
            'crypto_quote_source',
            'crypto_receive_address',
            'crypto_amount_expected',
            'crypto_amount_received',
            'crypto_expires_at',
            'crypto_txids',
        ], fn (string $column): bool => Schema::hasColumn('orders', $column)));

        Schema::table('orders', function (Blueprint $table) use ($columns): void {
            if (Schema::hasIndex('orders', 'orders_crypto_listen_idx')) {
                $table->dropIndex('orders_crypto_listen_idx');
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        DB::table('payment_method_config_maps')->where('name', 'TRON USDT')->delete();
    }
};
