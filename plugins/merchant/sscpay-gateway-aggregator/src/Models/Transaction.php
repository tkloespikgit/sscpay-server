<?php
/**
 * wp_payment_transactions 表名 + 状态/交易类型常量的唯一出处。
 * 实际的增删改查在 Core/TransactionManager.php（业务逻辑更适合放在Manager里，
 * 这里只做"表结构相关的静态信息"，避免表名字符串在多个文件里各写一份、
 * 改前缀/表名的时候到处漏改）。
 */

namespace PaymentGatewayAggregator\Models;

if (!defined('ABSPATH')) {
    exit;
}

class Transaction
{
    const TABLE_SUFFIX = 'payment_transactions';

    /**
     * trans_type：决定"商品明细以什么形态组装给支付通道"，具体规则见
     * Core/LineItemBuilder。由S系统在 /v1/pay 请求里必传。
     *
     * - CREATE ：商品名位置传SKU（S系统侧是新建商品，站内商品名对买家没有意义）
     * - MATCH  ：商品名位置传商品名（与站内商品库对得上的常规订单）
     * - VIRTUAL：不带真实商品，只合成一条 subject + S系统订单号 + 数量1 + 订单subtotal
     * - COPY   ：逐条读items并原样拷贝，name=商品名、sku=商品SKU（不做MATCH那种
     *            名为空退回SKU的兜底，也不像VIRTUAL那样合成单条）
     */
    const TRANS_TYPE_CREATE = 'CREATE';
    const TRANS_TYPE_MATCH = 'MATCH';
    const TRANS_TYPE_VIRTUAL = 'VIRTUAL';
    const TRANS_TYPE_COPY = 'COPY';

    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * @return string[] trans_type 的全部合法取值
     */
    public static function transTypes()
    {
        return [
            self::TRANS_TYPE_CREATE,
            self::TRANS_TYPE_MATCH,
            self::TRANS_TYPE_VIRTUAL,
            self::TRANS_TYPE_COPY,
        ];
    }

    /**
     * 严格校验（不做兜底转换）——入参校验用，非法值必须能被识别出来并拒绝。
     *
     * @param mixed $value
     * @return bool
     */
    public static function isValidTransType($value)
    {
        return is_string($value)
            && in_array(strtoupper(trim($value)), self::transTypes(), true);
    }

    /**
     * 归一化成大写常量值；无法识别的取值（包括1.4.0之前的历史记录没有这个
     * 字段）一律按 MATCH 处理——MATCH就是"按商品名正常传"，和加字段之前的
     * 行为完全一致，存量订单的表现不会因为升级而改变。
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeTransType($value)
    {
        $normalized = strtoupper(trim((string) $value));

        return in_array($normalized, self::transTypes(), true)
            ? $normalized
            : self::TRANS_TYPE_MATCH;
    }
}
