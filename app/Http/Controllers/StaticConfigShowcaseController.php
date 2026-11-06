<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\StaticConfigShowcase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * 静态配置演示页。
 *
 * 不挂 auth 中间件：登录页由各 Filament 面板自行注册，没有全局 login 路由，
 * 未登录时 auth 中间件会因 route('login') 不存在而 500。未登录或无权在这里直接 403。
 */
class StaticConfigShowcaseController extends Controller
{
    public function __invoke(Request $request): View
    {
        $screens = StaticConfigShowcase::screensFor($request->user());
        abort_if($screens === [], 403);

        /** @var User $user */
        $user = $request->user();
        $isPlatformStaff = $user->isPlatformStaff();

        return view('showcase.merchant-admin', [
            'allowedScreens' => $screens,
            'viewerAvatar' => $isPlatformStaff ? '管' : '商',
            'viewerName' => $isPlatformStaff ? '管理员' : '商户',
        ]);
    }
}
