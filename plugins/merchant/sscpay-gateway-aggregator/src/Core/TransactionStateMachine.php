<?php
/**
 * 订单状态迁移规则（对应设计文档v2 第九节9.3）。
 *
 * 所有对 wp_payment_transactions.status 的修改都必须先过这里校验，
 * 不允许在Controller/Gateway里直接UPDATE status字段——这是评审里指出的
 * "状态机没有定义合法迁移"问题的落地实现。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class TransactionStateMachine
{
    const PENDING = 'pending';
    const PAID = 'paid';
    const FAILED = 'failed';
    const CANCELLED = 'cancelled';
    const EXPIRED = 'expired';
    const REFUNDED = 'refunded';
    /** 争议中（收到网关侧的客诉/Dispute通知，结果未定）——非终态。 */
    const DISPUTING = 'disputing';
    /** 拒付：争议败诉，资金被网关强制扣回（chargeback），与商家主动退款(REFUNDED)语义不同——终态。 */
    const CONFUSED = 'confused';

    /** @var array<string,string[]> 允许的迁移：from => [to, to, ...] */
    private static $allowedTransitions = [
        self::PENDING => [self::PAID, self::FAILED, self::CANCELLED, self::EXPIRED],
        // DISPUTING：收到客诉。CONFUSED：部分网关在没有先发"争议已创建"事件的情况下
        // 会直接推送一次性的扣款反转通知，所以也允许从PAID直接到CONFUSED。
        self::PAID => [self::REFUNDED, self::DISPUTING, self::CONFUSED],
        // DISPUTING -> PAID：争议以商家胜诉告终，资金保留，状态回退为已支付。
        // DISPUTING -> REFUNDED：争议过程中商家主动/网关促成退款了结。
        self::DISPUTING => [self::PAID, self::CONFUSED, self::REFUNDED],
        self::FAILED => [],
        self::CANCELLED => [],
        // EXPIRED只表示本站支付链接已关闭，不代表渠道没有收款。
        // 已验签的付款通知或主动查单确认收款后，允许补正为PAID；不能重新打开支付。
        self::EXPIRED => [self::PAID],
        self::REFUNDED => [],
        self::CONFUSED => [],
    ];

    public static function allStatuses()
    {
        return array_keys(self::$allowedTransitions);
    }

    public static function isValidStatus($status)
    {
        return in_array($status, self::allStatuses(), true);
    }

    /**
     * @param string $from
     * @param string $to
     * @param string|null $paymentMethod 用交易实际通道判断可恢复的失败；省略则使用通用规则
     * @return bool
     */
    public static function canTransition($from, $to, $paymentMethod = null)
    {
        if ($from === $to) {
            // 同状态重复迁移视为幂等，允许，但调用方应据此跳过副作用（不重复入队回调等）。
            return true;
        }

        if (!self::isValidStatus($from) || !self::isValidStatus($to)) {
            return false;
        }

        // Stripe的一次扣款失败后仍可在同一收银台重试；确认到账后允许补正。
        // 未提供支付方式或其它通道仍沿用通用终态规则。
        if ($paymentMethod === 'stripe' && $from === self::FAILED && $to === self::PAID) {
            return true;
        }

        return in_array($to, self::$allowedTransitions[$from], true);
    }

    /**
     * @param string $from
     * @param string $to
     * @param string|null $paymentMethod
     * @return string 'noop'表示目标状态和当前相同（幂等）；'transition'表示发生了实际迁移
     * @throws \RuntimeException 当迁移不合法时
     */
    public static function assertTransition($from, $to, $paymentMethod = null)
    {
        if (!self::canTransition($from, $to, $paymentMethod)) {
            throw new \RuntimeException(
                sprintf('非法的订单状态迁移：%s -> %s', $from, $to)
            );
        }

        return ($from === $to) ? 'noop' : 'transition';
    }
}
