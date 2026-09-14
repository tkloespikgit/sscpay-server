<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * 创建平台超级管理员（is_super_admin = true，merchant_id 必须为 NULL）。
 *
 * 不用 Filament 自带的 `make:filament-user`——那个命令只会建一个普通用户，
 * 不知道 is_super_admin 这个字段的存在，建出来的用户在这套系统里
 * 什么后台功能都看不到（所有 Resource 的权限判断都是走
 * Gate::before($user->is_super_admin) 或具体 permission，普通用户
 * 两者都没有）。
 *
 * 用法：
 *   php artisan make:super-admin
 *   php artisan make:super-admin --name="Admin" --account=admin --password=secret123
 *
 * --account 只填账号即可，不需要真实邮箱：落库时会用 User::emailForAccount() 自动拼成
 * 内部邮箱（账号里本来就带 @ 的话就原样当邮箱用）。
 *
 * 不传参数时会交互式询问；传了 --password 则跳过交互，方便写自动化部署脚本
 * （生产环境用这种方式时注意密码不要出现在 shell history / CI 日志里）。
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'make:super-admin
                            {--name= : 超级管理员姓名}
                            {--account= : 登录账号}
                            {--password= : 登录密码（不传则交互式输入，输入时不回显）}';

    protected $description = '创建平台超级管理员账号（is_super_admin = true）';

    public function handle(): int
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
            'is_super_admin' => true,
            // merchant_id 不传，保持 NULL —— 这是超级管理员和普通商户用户
            // 的唯一区分依据，MerchantScope 和 Gate::before 都靠这个判断。
        ]);

        $this->info("超级管理员创建成功：{$account}（ID: {$user->id}）");
        $this->comment('提示：首次登录后建议立刻在 Profile 页面开启 MFA（两步验证），尤其是 mfa.force_for_admins 配置项已经打开的情况下，不开启会无法通过登录后的二次验证。');

        return self::SUCCESS;
    }
}
