<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\MerchantRoleProvisioningService;
use Illuminate\Console\Command;

/**
 * MerchantObserver::created() 只对"新建的商户"自动生效。这个命令用于
 * 一次性给已经存在的商户补跑角色配置（比如这套权限体系是后加的，
 * 数据库里已经有商户但还没有默认角色）。可以放心重复执行——
 * MerchantRoleProvisioningService 内部用 firstOrCreate + syncPermissions，
 * 幂等，不会重复创建角色或叠加权限。
 */
class ProvisionMerchantRoles extends Command
{
    protected $signature = 'merchants:provision-roles {merchant_id? : 只处理指定商户，不传则处理全部商户}';

    protected $description = '为商户补建/刷新默认角色（商户管理员/订单管理员/物流管理员/网站应用管理员）';

    public function handle(MerchantRoleProvisioningService $service): int
    {
        $merchantId = $this->argument('merchant_id');

        $merchants = $merchantId
            ? Merchant::query()->where('id', $merchantId)->get()
            : Merchant::query()->get();

        if ($merchants->isEmpty()) {
            $this->warn('No matching merchant(s) found.');

            return self::FAILURE;
        }

        foreach ($merchants as $merchant) {
            $service->provisionDefaultRoles($merchant);
            $this->info("Provisioned roles for merchant #{$merchant->id} ({$merchant->name})");
        }

        return self::SUCCESS;
    }
}
