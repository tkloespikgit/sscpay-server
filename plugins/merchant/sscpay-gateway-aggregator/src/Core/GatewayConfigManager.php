<?php
/**
 * 网关配置（wp_payment_gateway_configs）的注册/引用/吊销。
 * 对应设计文档v2 第七节7.4，是本次相对v1最主要的架构调整：
 * S系统不必在每次下单时都传明文密钥，可以先注册一次拿到config_id，
 * 后续 /v1/pay 只需要传引用。
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Models\GatewayConfig;

if (!defined('ABSPATH')) {
    exit;
}

class GatewayConfigManager
{
    const STATUS_ACTIVE = 'active';
    const STATUS_REVOKED = 'revoked';

    private static function table()
    {
        return GatewayConfig::tableName();
    }

    /**
     * 把配置里的环境字段统一成 mode（值一并转小写）。原来的 env 保留不动，
     * 以免S系统那边还按 env 读回时对不上。
     *
     * @param array $rawConfig 明文配置
     * @return array
     */
    private static function normalizeMode(array $rawConfig)
    {
        $raw = $rawConfig['mode'] ?? ($rawConfig['env'] ?? null);
        if ($raw === null || $raw === '') {
            return $rawConfig;
        }

        $rawConfig['mode'] = strtolower(trim((string) $raw));

        return $rawConfig;
    }

    /**
     * 注册或轮换一份网关配置。config_key重复提交视为轮换密钥：
     * 更新内容、重置状态为active，旧密钥立即失效（因为存的是最新内容，
     * 没有历史版本概念——如果需要保留历史版本用于审计，后续可以扩展成
     * 单独的历史表，本期不做）。
     *
     * @param string $configKey
     * @param string $paymentMethod
     * @param array $rawConfig 明文配置
     * @return array 保存后的配置行（不含明文，config_encrypted字段仍是密文）
     */
    public static function register($configKey, $paymentMethod, array $rawConfig)
    {
        global $wpdb;
        $table = self::table();

        // 字段名归一化：S系统注册时用的是 env，插件内部统一读 mode
        // （见 PaymentBase::resolveMode()）。在加密入库之前就归一，避免同一份
        // 语义在库里存成两种字段名。放在算 fingerprint 之前，保证指纹反映的
        // 就是真正落库的内容。
        $rawConfig = self::normalizeMode($rawConfig);

        $encrypted = ConfigEncryptor::encrypt($rawConfig);
        $fingerprint = ConfigEncryptor::fingerprint($rawConfig);
        $now = current_time('mysql', true);

        $existing = self::findByKey($configKey);

        if ($existing) {
            $wpdb->update(
                $table,
                [
                    'payment_method' => $paymentMethod,
                    'config_encrypted' => $encrypted,
                    'config_fingerprint' => $fingerprint,
                    'status' => self::STATUS_ACTIVE,
                    'updated_at' => $now,
                    'revoked_at' => null,
                ],
                ['config_key' => $configKey]
            );
            return self::findByKey($configKey);
        }

        $wpdb->insert($table, [
            'config_key' => $configKey,
            'payment_method' => $paymentMethod,
            'config_encrypted' => $encrypted,
            'config_fingerprint' => $fingerprint,
            'status' => self::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::findById((int) $wpdb->insert_id);
    }

    /**
     * @param int $configId
     * @return array|null 不含明文的行
     */
    public static function findById($configId)
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $configId),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @param string $configKey
     * @return array|null
     */
    public static function findByKey($configKey)
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE config_key = %s", $configKey),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * 解析出明文配置，供 /v1/pay 通过 gateway_config_id 引用时使用。
     *
     * @param int $configId
     * @return array{config:array,row:array}
     * @throws \RuntimeException 配置不存在或已吊销（Controller据此返回错误码10011）
     */
    public static function resolve($configId)
    {
        $row = self::findById($configId);

        if (!$row || $row['status'] !== self::STATUS_ACTIVE) {
            throw new \RuntimeException('GATEWAY_CONFIG_NOT_FOUND');
        }

        return [
            'config' => ConfigEncryptor::decrypt($row['config_encrypted']),
            'row' => $row,
        ];
    }

    /**
     * @param int $configId
     */
    public static function revoke($configId)
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            ['status' => self::STATUS_REVOKED, 'revoked_at' => current_time('mysql', true)],
            ['id' => $configId]
        );
    }

    /**
     * 供后台配置管理页面列表使用——不返回明文/密文，只返回元信息。
     *
     * @return array
     */
    public static function listAll()
    {
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            "SELECT id, config_key, payment_method, config_fingerprint, status, created_at, updated_at, revoked_at
             FROM {$table} ORDER BY created_at DESC",
            ARRAY_A
        );

        return $rows ?: [];
    }
}
