<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentPageController;
use App\Models\PaymentMethod;
use App\Services\WooCommerceProductSyncService;
use Illuminate\Support\Facades\Route;


Route::get('/payment/{token}', [PaymentPageController::class, 'show'])->name('payment.show');
Route::post('/payment/{token}/confirm', [PaymentPageController::class, 'confirm'])->name('payment.confirm');
Route::get('/payment/expired', fn () => view('payment.expired'))->name('payment.expired');


Route::get('/', [HomeController::class, 'show'])->name('home.show');

// 手动触发站点商品同步：/sync/products/{支付方式ID}，同步执行并返回统计结果。
// 商品多时翻译限频 1 QPS + 目标站点响应慢，整体耗时可能很长。
Route::get('/sync/products/{paymentMethod}', function (PaymentMethod $paymentMethod, WooCommerceProductSyncService $syncService) {
    set_time_limit(0);

    try {
        return response()->json($syncService->sync($paymentMethod));
    } catch (\Throwable $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
})->name('sync.products');
