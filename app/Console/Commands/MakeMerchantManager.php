<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlatformRoleProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * 创建商户级管理员（is_super_admin = false、merchant_id = NULL，
 * 赋 PlatformRoleProvisioningService 里那个全平台共享的"商户级管理员"角色）。
 *
 * 和 MakeSuperAdmin 是同一套用法，方便脚本化建号：
 *   php artisan make:merchant-manager
 *   php artisan make:merchant-manager --name="Agent A" --account=agent-a --password=secret123
 *
 * --account 只填账号即可，不需要真实邮箱，落库时自动拼内部邮箱
 * （见 User::emailForAccount()）。
 */
class MakeMerchantManager extends Command
{
    protected $signature = 'make:merchant-manager
                            {--name= : 商户级管理员姓名}
                            {--account= : 登录账号}
                            {--password= : 登录密码（不传则交互式输入，输入时不回显）}';

    protected $description = '创建商户级管理员账号（只能管理自己名下的商户）';

    public function handle(PlatformRoleProvisioningService $roleService): int
    {
        $name = $this->option('name') ?: $this->ask('姓名');
        $account = $this->option('account') ?: $this->ask('登录账号');
        $password = $this->option('password') ?: $this->secret('密码（输入时不显示）');
        $email = User::emailForAccount($account);

        $validator = Validator::make(
            compact('name', 'account', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'account' => ['required', 'string', 'max:190'],
                'email' => ['unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'is_super_admin' => false,
            // merchant_id 不传，保持 NULL —— 和超管一样是平台侧账号，
            // 但只能管理 ownedMerchants() 名下的商户（见 User::isMerchantManager()）。
        ]);

        $user->assignRole($roleService->provisionMerchantManagerRole());

        $this->info("商户级管理员创建成功：{$account}（ID: {$user->id}）");
        $this->comment('提示：登录后台后可以在"商户管理"里创建/名下商户，也可以在"用户管理"里给自己名下商户建用户账号。');

        return self::SUCCESS;
    }
}
