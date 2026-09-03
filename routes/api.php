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
*/

Route::middleware(['api.auth'])->prefix('order')->group(function () {
    Route::post('/create', [OrderController::class, 'store']);
    Route::post('/query', [OrderController::class, 'query']);
    Route::post('/ship', [OrderController::class, 'ship']);
});

// 支付网关聚合插件的 payment_status 回调，走插件自己的 X-PGA-Signature 验签
// （PaymentGatewayService::verifyWebhookSignature()），不套用上面商户那套 api.auth。
Route::post('/webhooks/payment-gateway/status', [PaymentGatewayWebhookController::class, 'status'])
    ->name('webhooks.payment-gateway.status');
