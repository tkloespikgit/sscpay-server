<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\CombinedPaymentController;
use App\Http\Controllers\CryptoPaymentPageController;
use App\Http\Controllers\DisputeAttachmentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentPageController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\StaticConfigShowcaseController;
use Illuminate\Support\Facades\Route;

Route::get('/docs/api/{locale?}', [ApiDocumentationController::class, 'show'])
    ->whereIn('locale', ['zh_CN', 'en'])
    ->name('api.documentation');

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

Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
    ->whereNumber('purchaseOrder')
    ->name('purchase-orders.show');

Route::get('/purchase-orders/{purchaseOrder}/details', [PurchaseOrderController::class, 'details'])
    ->whereNumber('purchaseOrder')
    ->name('purchase-orders.details');

Route::get('/payment/{token}', [PaymentPageController::class, 'show'])->middleware('throttle:120,1')->name('payment.show');
Route::get('/payment/{token}/crypto', [CryptoPaymentPageController::class, 'status'])->middleware('throttle:crypto-checkout-read')->name('payment.crypto.status');
Route::post('/payment/{token}/crypto', [CryptoPaymentPageController::class, 'open'])->middleware('throttle:crypto-checkout-open')->name('payment.crypto.open');
Route::prefix('combine-payment/{token}')->name('combined-payment.')->group(function (): void {
    Route::get('/', [CombinedPaymentController::class, 'show'])->name('show')->middleware('throttle:combined-payment-show');
    Route::get('/status', [CombinedPaymentController::class, 'status'])->name('status')->middleware('throttle:combined-payment-status');
    Route::post('/quote', [CombinedPaymentController::class, 'quote'])->name('quote')->middleware('throttle:combined-payment-quote');
    Route::post('/pay', [CombinedPaymentController::class, 'pay'])->name('pay')->middleware('throttle:combined-payment-pay');
});

Route::post('/payment/{token}/confirm', [PaymentPageController::class, 'confirm'])->name('payment.confirm');
Route::get('/payment/expired', fn () => view('payment.expired'))->name('payment.expired');

Route::get('/', [HomeController::class, 'show'])->name('home.show');

// 静态配置演示。不挂 auth 中间件，原因同争议附件：没有全局 login 路由。
// 未登录或无权由控制器返回 403。页面不在 public/，避免被 Web 服务器直接托管。
Route::get('/showcase', StaticConfigShowcaseController::class)->name('showcase.merchant-admin');
