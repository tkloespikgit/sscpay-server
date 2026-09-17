<?php

namespace App\Services;

use App\Models\Merchant;
use App\Support\Permissions;
use Spatie\Permission\Models\Role;

/**
 * 新商户入驻时自动建好默认角色（商户管理员/订单管理员/物流管理员/
 * 网站应用管理员/财务管理员/支付通道管理员），商户自己不需要从零开始逐个
 * 勾选权限——可以直接用这几个默认角色，也可以在此基础上再自建/调整。
 *
 * 触发时机见 MerchantObserver::created()；新增角色定义后，已存在的商户
 * 需要额外跑一次 `php artisan merchants:provision-roles` 补建
 * （ProvisionMerchantRoles 命令，firstOrCreate + syncPermissions 天然幂等，可重复执行）。
 */
class MerchantRoleProvisioningService
{
    public const MERCHANT_ADMIN_LABEL = '商户管理员';

    /**
     * @return array<string, Role> 角色标识 => Role 实例，方便调用方
     *                             （比如商户注册流程）直接把首个管理员用户
     *                             assignRole() 到"商户管理员"上。
     */
    public function provisionDefaultRoles(Merchant $merchant): array
    {
        $definitions = [
            'merchant_admin' => [
                'label' => self::MERCHANT_ADMIN_LABEL,
                'permissions' => Permissions::merchantScoped(), // 商户管理员拥有该商户下全部权限
            ],
            'order_admin' => [
                'label' => '订单管理员',
                'permissions' => [
                    Permissions::ORDERS_VIEW,
                    Permissions::ORDERS_CREATE_MANUAL,
                    Permissions::ORDERS_SHIP,
                    Permissions::LOGISTICS_IMPORTS_MANAGE,
                    Permissions::ORDER_EVENTS_VIEW,
                    Permissions::ORDER_DISPUTES_VIEW,
                    Permissions::ORDER_DISPUTES_REPLY,
                ],
            ],
            'logistics_admin' => [
                'label' => '物流管理员',
                'permissions' => [
                    Permissions::ORDERS_VIEW,
                    Permissions::ORDERS_SHIP,
                    Permissions::LOGISTICS_IMPORTS_MANAGE,
                ],
            ],
            'application_admin' => [
                'label' => '网站应用管理员',
                'permissions' => [
                    Permissions::APPLICATIONS_MANAGE,
                ],
            ],
            'finance_admin' => [
                'label' => '财务管理员',
                'permissions' => [
                    Permissions::FINANCE_VIEW,
                    Permissions::WITHDRAWALS_REQUEST,
                    Permissions::WITHDRAWALS_REVIEW,
                    Permissions::BALANCE_ADJUST,
                    Permissions::ORDERS_VIEW,
                    Permissions::ORDERS_REFUND,
                    Permissions::ORDERS_CHARGEBACK,
                    Permissions::ORDER_DISPUTES_VIEW,
                    Permissions::ORDER_DISPUTES_OPEN,
                    Permissions::ORDER_DISPUTES_CLOSE,
                ],
            ],
            'payment_channel_admin' => [
                'label' => '支付通道管理员',
                'permissions' => [
                    Permissions::PAYMENT_METHODS_MANAGE,
                    Permissions::PAYMENT_GROUPS_MANAGE,
                ],
            ],
        ];

        $roles = [];

        foreach ($definitions as $key => $definition) {
            $role = Role::query()->firstOrCreate([
                'merchant_id' => $merchant->id,
                'name' => $definition['label'],
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($definition['permissions']);

            $roles[$key] = $role;
        }

        return $roles;
    }

    /**
     * 查出某商户的"商户管理员"角色。要求 provisionDefaultRoles() 已经跑过
     * （MerchantObserver::created() 在商户建好时同步触发），否则返回 null。
     */
    public function merchantAdminRole(Merchant $merchant): ?Role
    {
        return Role::query()->where([
            'merchant_id' => $merchant->id,
            'name' => self::MERCHANT_ADMIN_LABEL,
            'guard_name' => 'web',
        ])->first();
    }
}
