<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenCryptoPaymentRequest;
use App\Models\CheckoutLink;
use App\Models\Order;
use App\Models\SystemConfig;
use App\Services\CryptoCheckoutPresenter;
use App\Services\CryptoCheckoutService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CryptoPaymentPageController extends Controller
{
    public function __construct(private readonly CryptoCheckoutPresenter $presenter, private readonly CryptoCheckoutService $checkouts) {}

    public function show(Request $request, string $token): Response
    {
        $order = $this->order($request, $token);

        return response()->view('payment.crypto', ['order' => $order])->withHeaders($this->headers());
    }

    public function status(Request $request, string $token): JsonResponse
    {
        return response()->json($this->presenter->data($this->order($request, $token)))->withHeaders($this->headers());
    }

    public function open(OpenCryptoPaymentRequest $request, string $token): JsonResponse
    {
        $order = $this->order($request, $token);
        try {
            $this->checkouts->open($order->merchant_id, $order->id, $request->validated('action'),
                $request->filled('request_id') ? (int) $request->validated('request_id') : null);
        } catch (DomainException) {
            return response()->json(['message' => __('crypto.checkout.changed')], 409)->withHeaders($this->headers());
        }

        return response()->json($this->presenter->data($order->refresh()), 202)->withHeaders($this->headers());
    }

    private function order(Request $request, string $token): Order
    {
        abort_unless(strtolower($request->getHost()) === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)), 404);

        $order = Order::query()->withoutGlobalScopes()->where('payment_type', 'crypto')->where('payment_link_token', $token)
            ->where('created_at', '>', now()->subDays((int) SystemConfig::get('payment_link.expire_days', 7)))
            ->firstOrFail();
        if ($order->source === CheckoutLink::ORDER_SOURCE) {
            app()->setLocale('en');
        }

        return $order;
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
