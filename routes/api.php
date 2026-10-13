<?php

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\PaymentGatewayWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| 商户对接接口全部走 App-ID + 签名鉴权（ApiAuthentication 中间件，别名 'api.auth'，
| 见 bootstrap/app.php 里的中间件别名注册）。
|
| 商户对接接口限定在 config('app.api_domain')（.env 的 API_DOMAIN）域名下才能访问，
| 未配置时为 null，Route::domain(null) 不限制域名，等价于改造前的行为，
| 本地开发无需配置。支付网关 webhook 不限制域名，以接收收款链接自定义域名的回调。
*/

Route::domain(config('app.api_domain'))->group(function () {
    Route::middleware(['api.auth'])->prefix('order')->group(function () {
        Route::post('/create', [OrderController::class, 'store']);
        Route::post('/query', [OrderController::class, 'query']);
        Route::post('/ship', [OrderController::class, 'ship']);
    });
});

// callback_url 可能使用客户的收款链接域名，不能限定在 API_DOMAIN。
// 仍通过插件自己的 X-PGA-Signature 对原始 body 验签，不套用商户 api.auth。
Route::post('/webhooks/payment-gateway/status', [PaymentGatewayWebhookController::class, 'status'])
    ->name('webhooks.payment-gateway.status');
