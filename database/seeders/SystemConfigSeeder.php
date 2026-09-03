<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemConfigSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $configs = [
            [
                'config_key' => 'exchange.supported_currencies',
                'config_value' => json_encode(['EUR', 'JPY', 'GBP']),
                'value_type' => 'json',
                'group' => 'exchange',
                'description' => '支持的币种列表（JSON 数组）',
            ],
            [
                'config_key' => 'exchange.surcharge_percent',
                'config_value' => '0',
                'value_type' => 'number',
                'group' => 'exchange',
                'description' => '汇率转换汇损百分比（%）',
            ],
            [
                'config_key' => 'exchange.surcharge_type',
                'config_value' => 'percent',
                'value_type' => 'string',
                'group' => 'exchange',
                'description' => '汇损类型：percent 或 fixed',
            ],
            [
                'config_key' => 'exchange.surcharge_fixed',
                'config_value' => '0.005',
                'value_type' => 'number',
                'group' => 'exchange',
                'description' => '固定汇损值（当 surcharge_type = fixed 时生效）',
            ],
            [
                'config_key' => 'order.platforms',
                'config_value' => json_encode(['wordpress', 'shopyy', 'shopline', 'invoice', 'opencart']),
                'value_type' => 'json',
                'group' => 'order',
                'description' => '下单接口允许的电商网站平台类型枚举（JSON 数组），如 wordpress / shopyy / shopline / invoice / opencart',
            ],
            [
                'config_key' => 'order_match.min_price_ratio',
                'config_value' => '0.4',
                'value_type' => 'number',
                'group' => 'order',
                'description' => '自动匹配商品末件改价补齐时的最低价格比例（0~1）：改价后单价不低于原价的该比例，低于则回溯退上一行一件凑大额度',
            ],
            [
                'config_key' => 'order_match.max_item_quantity',
                'config_value' => '3',
                'value_type' => 'number',
                'group' => 'order',
                'description' => '自动匹配商品时单个商品的最大匹配件数（0 表示不限制）；商品池按此上限计算的总容量不够时会报错',
            ],
            [
                'config_key' => 'order_event.sync_enabled',
                'config_value' => 'true',
                'value_type' => 'boolean',
                'group' => 'order_event',
                'description' => '是否开启订单事件自动同步',
            ],
            [
                'config_key' => 'order_event.sync_interval',
                'config_value' => '10',
                'value_type' => 'number',
                'group' => 'order_event',
                'description' => '同步间隔（分钟）',
            ],
            [
                'config_key' => 'order_event.active_window_days',
                'config_value' => '3',
                'value_type' => 'number',
                'group' => 'order_event',
                'description' => '订单日志同步的活跃窗口（天）：创建时间在此窗口内的订单每轮都会重新拉取日志；超出窗口后仅 pending/paid/disputing 状态的订单仍会继续拉取',
            ],
            [
                'config_key' => 'payment.product_match_modes',
                'config_value' => json_encode(['MATCH', 'CREATE', 'VIRTUAL']),
                'value_type' => 'json',
                'group' => 'payment',
                'description' => '支付方式商品匹配模式枚举（JSON 数组）：MATCH 匹配 / CREATE 创建 / VIRTUAL 虚拟（等同 MATCH）；回跳地址与站点同域名时自动走直连',
            ],
            [
                'config_key' => 'payment_link.expire_days',
                'config_value' => '7',
                'value_type' => 'number',
                'group' => 'payment',
                'description' => '付款链接有效期（天）',
            ],
            [
                'config_key' => 'mfa.force_for_admins',
                'config_value' => 'false',
                'value_type' => 'boolean',
                'group' => 'security',
                'description' => '是否强制所有管理员开启 MFA',
            ],
            [
                'config_key' => 'notify.max_attempts',
                'config_value' => '5',
                'value_type' => 'number',
                'group' => 'notify',
                'description' => '交易结果通知最大尝试次数（含首次）',
            ],
            [
                'config_key' => 'notify.retry_intervals_seconds',
                'config_value' => json_encode([30, 300, 1800, 3600]),
                'value_type' => 'json',
                'group' => 'notify',
                'description' => '失败后重试间隔（秒），依次对应第2/3/4/5次尝试前的等待时间',
            ],
            [
                'config_key' => 'notify.response_body_max_length',
                'config_value' => '5000',
                'value_type' => 'number',
                'group' => 'notify',
                'description' => '记录商户响应内容时的最大字符数（超出截断，避免异常大响应撑爆表）',
            ],
        ];

        $now = now();

        foreach ($configs as $config) {
            DB::table('system_configs')->updateOrInsert(
                ['config_key' => $config['config_key']],
                array_merge($config, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
