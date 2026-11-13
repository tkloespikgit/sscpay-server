<?php

namespace App\Http\Controllers;

use App\Exceptions\CombinedPaymentException;
use App\Exceptions\MinimumAmountNotMetException;
use App\Exceptions\NoAvailablePaymentMethodException;
use App\Models\CheckoutLink;
use App\Models\CombinedOrder;
use App\Services\CombinedOrderService;
use App\Services\OrderApiPayload;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class CombinedPaymentController extends Controller
{
    public function __construct(
        private readonly CombinedOrderService $payments,
        private readonly OrderApiPayload $payloads,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $parent = $this->resolve($request, $token);

        return response()->view('payment.combined', ['parent' => $parent, 'state' => $this->state($parent)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request, string $token): JsonResponse
    {
        return response()->json(['code' => 0, 'data' => $this->state($this->resolve($request, $token))])
            ->header('Cache-Control', 'no-store');
    }

    public function quote(Request $request, string $token): JsonResponse
    {
        $parent = $this->resolve($request, $token);
        try {
            $this->payments->quote($parent);

            return response()->json(['code' => 0, 'data' => $this->state($parent->refresh())])->header('Cache-Control', 'private, no-store');
        } catch (CombinedPaymentException $error) {
            return $this->error($parent, $error->errorCode, $error->httpStatus);
        } catch (MinimumAmountNotMetException|NoAvailablePaymentMethodException) {
            return $this->error($parent, 'COMBINE_PAYMENT_UNAVAILABLE');
        }
    }

    public function pay(Request $request, string $token): JsonResponse
    {
        $parent = $this->resolve($request, $token);
        $data = $request->validate(['quote_id' => ['nullable', 'string', 'max:64']]);
        try {
            $order = $this->payments->pay($parent, (string) ($data['quote_id'] ?? ''));

            return response()->json(['code' => 0, 'pay_url' => $order->pay_url, 'data' => $this->state($parent->refresh())])->header('Cache-Control', 'private, no-store');
        } catch (CombinedPaymentException $error) {
            return $this->error($parent, $error->errorCode, $error->httpStatus);
        } catch (PaymentGatewayException) {
            return $this->error($parent, 'GATEWAY_ERROR', 502);
        } catch (MinimumAmountNotMetException|NoAvailablePaymentMethodException) {
            return $this->error($parent, 'COMBINE_PAYMENT_UNAVAILABLE');
        } catch (LockTimeoutException) {
            return $this->error($parent, 'PAYMENT_IN_PROGRESS');
        }
    }

    private function resolve(Request $request, string $token): CombinedOrder
    {
        $parent = CombinedOrder::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('payment_link_token', $token)->with(['merchant', 'application'])->firstOrFail();
        abort_unless(strtolower($parent->checkout_host) === strtolower($request->getHost())
            && $parent->merchant?->status && $parent->application?->status
            && (int) $parent->application->merchant_id === (int) $parent->merchant_id, 404);
        if ($parent->checkout_link_id) {
            $link = CheckoutLink::query()->withoutGlobalScopes()->whereNull('deleted_at')
                ->where('merchant_id', $parent->merchant_id)->active()->find($parent->checkout_link_id);
            abort_unless($link && in_array(strtolower($request->getHost()), $link->allowedHosts(), true), 404);
        }
        $locale = $request->query('lang', $request->session()->get('combined_locale', app()->getLocale()));
        if (in_array($locale, ['zh_CN', 'en'], true)) {
            app()->setLocale($locale);
            $request->session()->put('combined_locale', $locale);
        }

        return $parent;
    }

    /** @return array<string, mixed> */
    private function state(CombinedOrder $parent): array
    {
        $data = $this->payloads->parent($parent);
        $quote = $parent->current_quote;
        if ($quote && Carbon::parse($quote['expires_at'])->isPast()) {
            $quote = null;
        }
        $current = $parent->current_order_id ? $parent->children()->forMerchant($parent->merchant_id)->find($parent->current_order_id) : null;

        return $data + [
            'payable' => $parent->isPayable(),
            'quote' => $quote ? array_intersect_key($quote, array_flip(['id', 'amount', 'converted_amount', 'payment_method', 'expires_at'])) : null,
            'current_payment' => $current ? [
                'amount' => (string) $current->amount, 'payment_method' => $current->payment_method,
                'status' => $current->status, 'pay_url' => $current->pay_url,
            ] : null,
            'return_url' => $parent->status === 'paid' ? $parent->return_url : null,
        ];
    }

    private function error(CombinedOrder $parent, string $code, int $status = 409): JsonResponse
    {
        CombinedOrder::query()->forMerchant($parent->merchant_id)->whereKey($parent->id)
            ->update(['last_error_code' => $code]);

        return response()->json(['code' => $status, 'error_code' => $code,
            'msg' => __('combined.errors.'.$code), 'data' => $this->state($parent->refresh())], $status)->header('Cache-Control', 'private, no-store');
    }
}
