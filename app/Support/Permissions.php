<?php

namespace App\Support;

/**
 * 权限名称统一定义在这里，Resource 的 canX() 方法和 PermissionSeeder
 * 都从这个类取值，避免两边各写一遍字符串、改名的时候漏改一处。
 *
 * 注意：这些是"权限"（permissions），不是"角色"（roles）。角色是
 * 商户自己建的（roles.merchant_id 有值），但权限本身是全平台统一定义、
 * 不区分商户的——商户在建角色时，从这份固定的权限列表里勾选组合即可。
 */
final class Permissions
{
    // 平台级（超级管理员专属，不出现在任何商户角色的可选范围里）
    public const MERCHANTS_MANAGE = 'merchants.manage';

    public const SYSTEM_CONFIGS_MANAGE = 'system_configs.manage';

    // 商户级
    public const APPLICATIONS_MANAGE = 'applications.manage';

    public const PAYMENT_METHODS_MANAGE = 'payment_methods.manage';

    public const PAYMENT_GROUPS_MANAGE = 'payment_groups.manage';

    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_CREATE_MANUAL = 'orders.create_manual';

    public const ORDERS_SHIP = 'orders.ship';

    public const LOGISTICS_IMPORTS_MANAGE = 'logistics_imports.manage';

    public const ORDER_EVENTS_VIEW = 'order_events.view';

    public const TELEGRAM_MANAGE = 'telegram.manage';

    public const USERS_MANAGE = 'users.manage';

    // 资金管理（商户级，可分配给角色；超级管理员通过 Gate::before 自动拥有）
    public const FINANCE_VIEW = 'finance.view';               // 查看余额与流水台账

    public const WITHDRAWALS_REQUEST = 'withdrawals.request'; // 发起提现申请

    public const WITHDRAWALS_REVIEW = 'withdrawals.review';   // 审核放款 / 驳回提现

    public const BALANCE_ADJUST = 'balance.adjust';          // 人工调整余额

    public const ORDERS_REFUND = 'orders.refund';            // 订单退款（含部分退款）

    public const ORDERS_CHARGEBACK = 'orders.chargeback';    // 订单拒付

    // 争议审核事件（商户级，可分配给角色；超级管理员通过 Gate::before 自动拥有）
    public const ORDER_DISPUTES_VIEW = 'order_disputes.view';     // 查看争议审核事件列表/详情

    public const ORDER_DISPUTES_OPEN = 'order_disputes.open';     // 开立争议审核事件（冻结资金）

    public const ORDER_DISPUTES_REPLY = 'order_disputes.reply';   // 回复争议审核事件

    public const ORDER_DISPUTES_CLOSE = 'order_disputes.close';   // 手动结束争议审核事件（释放资金）

    public static function platformOnly(): array
    {
        return [
            self::MERCHANTS_MANAGE,
            self::SYSTEM_CONFIGS_MANAGE,
        ];
    }

    public static function merchantScoped(): array
    {
        return [
            self::APPLICATIONS_MANAGE,
            self::PAYMENT_METHODS_MANAGE,
            self::PAYMENT_GROUPS_MANAGE,
            self::ORDERS_VIEW,
            self::ORDERS_CREATE_MANUAL,
            self::ORDERS_SHIP,
            self::LOGISTICS_IMPORTS_MANAGE,
            self::ORDER_EVENTS_VIEW,
            self::TELEGRAM_MANAGE,
            self::USERS_MANAGE,
            self::FINANCE_VIEW,
            self::WITHDRAWALS_REQUEST,
            self::WITHDRAWALS_REVIEW,
            self::BALANCE_ADJUST,
            self::ORDERS_REFUND,
            self::ORDERS_CHARGEBACK,
            self::ORDER_DISPUTES_VIEW,
            self::ORDER_DISPUTES_OPEN,
            self::ORDER_DISPUTES_REPLY,
            self::ORDER_DISPUTES_CLOSE,
        ];
    }

    public static function all(): array
    {
        return array_merge(self::platformOnly(), self::merchantScoped());
    }
}
