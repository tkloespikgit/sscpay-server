<?php

use App\Http\Controllers\CheckoutLinkController;
use App\Http\Controllers\CheckoutLocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 收款链接落地页
|--------------------------------------------------------------------------
| 商户创建的收款链接（checkout_links）对外的公开页面。客户在这里填写联系方式
| 与收货地址，提交后由 CheckoutLinkOrderService 当场建单并跳转到支付网关收银台。
|
| ⚠️ 与 routes/web.php 里的 /payment/{token} 不是同一个东西：那个是订单级的
| 一次性付款链接（下单后邮件发给客户），这里是可反复使用的收银链接（先有链接
| 后有订单）。两套路由、两套模型，不要互相复用。
|
| 这个文件**故意不限制域名**（对比 routes/api.php 的 Route::domain(...)）：
| 落地页要跑在各个商户自己绑定的域名上，域名合法性在控制器里按
| CheckoutLink::allowedHosts() 逐条链接校验，而不是在路由层一刀切。
|
| 没有任何鉴权中间件——访问控制依赖 slug 不可猜测（24 位随机串），
| 和 PaymentPageController 的 token 是同一个思路。写入口的防护靠
| Turnstile + 频率限制，见 CreateCheckoutOrderRequest 与 CheckoutLinkController。
*/

Route::get('/checkout-locations/states', [CheckoutLocationController::class, 'states'])->name('checkout.locations.states');
Route::get('/checkout-locations/cities', [CheckoutLocationController::class, 'cities'])->name('checkout.locations.cities');

Route::prefix('c/{slug}')->group(function () {
    Route::get('/', [CheckoutLinkController::class, 'show'])->name('checkout.show');
    Route::post('/', [CheckoutLinkController::class, 'store'])->name('checkout.store');

    // 支付网关回跳地址（下单时写进 orders.return_url / cancel_url）。
    // 这两个页面只是给客户看的结果提示，订单状态的真实流转一律由
    // /api/webhooks/payment-gateway/status 驱动，不在这里改任何状态。
    Route::get('/success', [CheckoutLinkController::class, 'success'])->name('checkout.success');
    Route::get('/cancelled', [CheckoutLinkController::class, 'cancelled'])->name('checkout.cancelled');
});
