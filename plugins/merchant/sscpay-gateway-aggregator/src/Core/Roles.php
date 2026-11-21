<?php
/**
 * API权限分离（对应设计文档v2 第七节7.9 / 评审：下单账号与配置账号权限隔离）。
 *
 * 注册两个自定义capability，分别控制"日常下单/查询/物流同步"和
 * "网关凭证注册/轮换"这两类操作。管理员账号默认两个权限都有，
 * 生产环境建议单独建两个WP用户、各自只授予其中一个capability，
 * 再各自生成独立的应用密码给S系统的两个不同用途使用。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Roles
{
    const CAP_MANAGE_ORDERS = 'pga_manage_orders';
    const CAP_MANAGE_GATEWAY_CONFIG = 'pga_manage_gateway_config';

    public static function activate()
    {
        // 管理员默认拥有全部权限，保证不额外配置也能直接用起来。
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP_MANAGE_ORDERS);
            $admin->add_cap(self::CAP_MANAGE_GATEWAY_CONFIG);
        }

        // 两个专用角色，供按最小权限原则创建独立账号时使用。
        if (!get_role('pga_order_operator')) {
            add_role('pga_order_operator', __('支付插件-订单接口账号', 'sscpay-gateway-aggregator'), [
                'read' => true,
                self::CAP_MANAGE_ORDERS => true,
            ]);
        }

        if (!get_role('pga_config_operator')) {
            add_role('pga_config_operator', __('支付插件-网关配置账号', 'sscpay-gateway-aggregator'), [
                'read' => true,
                self::CAP_MANAGE_GATEWAY_CONFIG => true,
            ]);
        }
    }
}
