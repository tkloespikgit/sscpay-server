<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\SystemConfig;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 新商户入驻时自动建好默认角色（商户管理员/订单管理员/物流管理员/
 * 网站应用管理员/财务管理员/支付通道管理员），商户自己不需要从零开始逐个
 * 勾选权限——可以直接用这几个默认角色，也可以在此基础上再自建/调整。
 *
 * 新商户由 MerchantObserver::created() 开通；超管在「角色权限分配」保存模板时
 * 自动同步存量商户。CLI `merchants:provision-roles` 同样读取已保存模板，
 * firstOrCreate + syncPermissions 保持角色 ID 和用户绑定，可幂等补建。
 */
class MerchantRoleProvisioningService
{
    public const MERCHANT_ADMIN_LABEL = '商户管理员';

    public const TEMPLATE_CONFIG_KEY = 'permissions.merchant_role_templates';

    /**
     * 从同一行配置读取并锁定模板，使新商户开通、CLI 补建与全量同步不会交错使用旧模板。
     */
    public function provisionDefaultRoles(Merchant $merchant): array
    {
        $this->ensureTemplateConfig();

        return DB::transaction(function () use ($merchant) {
            $config = $this->lockedTemplateConfig();

            return $this->provisionRoles($merchant, $this->definitionsFromConfig($config));
        });
    }

    /** 当前有效的六个默认角色模板；未配置的角色使用内置权限。 */
    public function definitions(): array
    {
        return $this->definitionsFromConfig(
            SystemConfig::query()->where('config_key', self::TEMPLATE_CONFIG_KEY)->first(),
        );
    }

    /**
     * 保存完整模板并在同一事务内同步所有未删除商户（包括停用商户）。
     * 仅更新默认角色的权限，不改角色 ID、用户绑定、自建角色或用户直授权限。
     * 返回同步的商户数。后台调用方必须先校验超级管理员身份。
     */
    public function saveAndSync(array $permissionsByRole): int
    {
        $keys = array_keys($this->builtInDefinitions());
        $rules = ['data' => ['required', 'array:'.implode(',', $keys)]];

        foreach ($keys as $key) {
            $rules["data.{$key}"] = ['present', 'array', 'list'];
            $rules["data.{$key}.*"] = ['string', 'distinct', Rule::in(Permissions::merchantScoped())];
        }

        Validator::make(['data' => $permissionsByRole], $rules)->validate();

        $this->ensureTemplateConfig();

        try {
            return DB::transaction(function () use ($permissionsByRole) {
                $config = $this->lockedTemplateConfig();

                // 新权限在存量环境可能尚未运行 Seeder，保存时一并幂等补齐。
                foreach (Permissions::merchantScoped() as $name) {
                    Permission::findOrCreate($name, 'web');
                }

                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $config->update(['config_value' => json_encode($permissionsByRole, JSON_THROW_ON_ERROR)]);
                $definitions = $this->definitionsFromConfig($config);
                $count = 0;

                Merchant::query()->chunkById(100, function ($merchants) use ($definitions, &$count) {
                    foreach ($merchants as $merchant) {
                        $this->provisionRoles($merchant, $definitions);
                        $count++;
                    }
                });

                return $count;
            });
        } finally {
            // 提交后再清一次，防止同步过程中其他请求缓存了旧权限；回滚也不能留下脏缓存。
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function ensureTemplateConfig(): void
    {
        SystemConfig::query()->firstOrCreate(
            ['config_key' => self::TEMPLATE_CONFIG_KEY],
            [
                'config_value' => '{}',
                'value_type' => SystemConfig::TYPE_JSON,
                'group' => 'permissions',
                'description' => '商户默认角色权限模板（由角色权限分配页面保存并同步）',
            ],
        );
    }

    private function lockedTemplateConfig(): SystemConfig
    {
        return SystemConfig::query()->where('config_key', self::TEMPLATE_CONFIG_KEY)->lockForUpdate()->firstOrFail();
    }

    private function definitionsFromConfig(?SystemConfig $config): array
    {
        // 刻意直接读数据库，不使用 SystemConfig 的小时缓存，避免保存/回滚期间读到旧模板。
        $overrides = json_decode($config?->config_value ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $definitions = $this->builtInDefinitions();

        foreach ($definitions as $key => &$definition) {
            if (array_key_exists($key, $overrides)) {
                $definition['permissions'] = array_values(array_intersect($overrides[$key], Permissions::merchantScoped()));
            }
        }

        return $definitions;
    }

    private function builtInDefinitions(): array
    {
        return [
            'merchant_admin' => [
                'label' => self::MERCHANT_ADMIN_LABEL,
                'permissions' => Permissions::merchantScoped(),
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
                    Permissions::CHECKOUT_LINKS_MANAGE,
                    Permissions::MERCHANT_DOMAINS_MANAGE,
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
                'permissions' => [Permissions::APPLICATIONS_MANAGE],
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
                'permissions' => [Permissions::PAYMENT_METHODS_MANAGE, Permissions::PAYMENT_GROUPS_MANAGE],
            ],
        ];
    }

    /**
     * @return array<string, Role> 角色标识 => Role 实例，方便调用方
     *                             （比如商户注册流程）直接把首个管理员用户
     *                             assignRole() 到"商户管理员"上。
     */
    private function provisionRoles(Merchant $merchant, array $definitions): array
    {
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
