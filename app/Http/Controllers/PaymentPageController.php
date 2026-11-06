<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Crypto\CryptoCheckoutLinkBuilder;
use App\Services\Crypto\CryptoPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 付款链接落地页。访问控制依赖 token 不可猜测性 + pending + 未过期。
 * 链上收款订单渲染 crypto 视图，不跳转 PGA。
 */
class PaymentPageController extends Controller
{
    public function show(string $token, CryptoPaymentService $cryptoPayments): View|RedirectResponse
    {
        $order = Order::query()
            ->withoutGlobalScopes()
            ->validPaymentLink()
            ->where('payment_link_token', $token)
            ->with(['items', 'merchant'])
            ->first();

        if (! $order) {
            $order = Order::query()
                ->withoutGlobalScopes()
                ->where('payment_link_token', $token)
                ->where('status', 'pending')
                ->whereNotNull('crypto_expires_at')
                ->with(['items', 'merchant'])
                ->first();

            if ($order) {
                $cryptoPayments->expireIfDue($order);
            }

            return view('payment.expired');
        }

        if ($order->isCryptoCheckout()) {
            if ($order->crypto_expires_at?->isPast()) {
                $cryptoPayments->expireIfDue($order);

                return view('payment.expired');
            }

            $checkoutLink = app(CryptoCheckoutLinkBuilder::class)->forOrder(
                $order,
                route('payment.show', ['token' => $token], absolute: true),
            );

            return view('payment.crypto', [
                'order' => $order,
                'deepLink' => $checkoutLink['deep_link'],
                'walletLabel' => $checkoutLink['wallet_label'],
            ]);
        }

        if ($order->pay_url) {
            $self = route('payment.show', ['token' => $token], absolute: true);
            if (rtrim((string) $order->pay_url, '/') !== rtrim($self, '/')) {
                return redirect()->away($order->pay_url);
            }
        }

        return view('payment.show', ['order' => $order]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $order = Order::query()
            ->withoutGlobalScopes()
            ->validPaymentLink()
            ->where('payment_link_token', $token)
            ->first();

        if (! $order) {
            return redirect()->route('payment.show', $token);
        }

        if ($order->isCryptoCheckout()) {
            return redirect()->route('payment.show', $token);
        }

        $request->validate(['payment_method' => ['required', 'string']]);

        return redirect($order->return_url ?? route('payment.show', $token));
    }
}
