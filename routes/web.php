<?php

use App\Http\Controllers\DisputeAttachmentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentPageController;
use Illuminate\Support\Facades\Route;


// 争议审核事件的图片凭证（后台详情页灯箱用）。放在 web.php 而不是某个面板里，
// 是因为管理端和商户端两个面板都要用同一个地址。
//
// 故意不挂 auth 中间件：本项目的登录页由各 Filament 面板自己注册
// （filament.admin.auth.login 等），没有全局的 login 命名路由，auth 中间件
// 未登录时会因 route('login') 不存在直接 500。鉴权改在控制器里做，
// 未登录就是 403。
Route::get('/dispute-attachments/{type}/{record}/{index}', [DisputeAttachmentController::class, 'show'])
    ->whereIn('type', ['event', 'reply'])
    ->whereNumber(['record', 'index'])
    ->name('dispute-attachments.show');


Route::get('/payment/{token}', [PaymentPageController::class, 'show'])->name('payment.show');
Route::post('/payment/{token}/confirm', [PaymentPageController::class, 'confirm'])->name('payment.confirm');
Route::get('/payment/expired', fn () => view('payment.expired'))->name('payment.expired');


Route::get('/', [HomeController::class, 'show'])->name('home.show');
