<?php

namespace App\Services;

use App\Models\Merchant;
use App\Support\Permissions;
use Spatie\Permission\Models\Role;

/**
 * 新商户入驻时自动建好 4 个默认角色（2.x 节列出的商户管理员/订单管理员/
 * 物流管理员/网站应用管理员），商户自己不需要从零开始逐个勾选权限——
 * 可以直接用这几个默认角色，也可以在此基础上再自建/调整。
 *
 * 触发时机见 MerchantObserver::created()。
 */
class MerchantRoleProvisioningService
{
    /**
     * @return array<string, Role> 角色标识 => Role 实例，方便调用方
     *                             （比如商户注册流程）直接把首个管理员用户
     *                             assignRole() 到"商户管理员"上。
     */
    public function provisionDefaultRoles(Merchant $merchant): array
    {
        $definitions = [
            'merchant_admin' => [
                'label' => '商户管理员',
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
}
