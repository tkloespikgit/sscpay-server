<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AmountMismatchException;
use App\Exceptions\CallbackDomainNotAllowedException;
use App\Exceptions\MinimumAmountNotMetException;
use App\Exceptions\NoAvailablePaymentMethodException;
use App\Exceptions\OrderItemsMismatchException;
use App\Exceptions\PaymentMethodDomainMismatchException;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateOrderRequest;
use App\Http\Requests\Api\SyncOrderShippingRequest;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\OrderCreationService;
use App\Services\OrderShippingService;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 对外下单 / 查询接口。merchant_id / application_id 由 ApiAuthentication
 * 中间件验签通过后注入到 $request->attributes（不是 auth()->user()，
 * 这条路径从头到尾都没有"登录用户"这个概念，见 MerchantScope 类注释）。
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderCreationService $orderCreationService,
        private readonly OrderShippingService $orderShippingService,
    ) {}

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $merchant = Merchant::query()->findOrFail($request->attributes->get('merchant_id'));
        // application 实例由 ApiAuthentication 中间件验签通过后注入（见中间件末尾）；
        // 回跳域名要与它绑定的 website 一致（OrderCreationService 第 4 步校验）。
        $application = $request->attributes->get('application');

        try {
            $order = $this->orderCreationService->createOrder(
                data: $request->toOrderCreationData(),
                merchant: $merchant,
                application: $application,
                source: 'api',
            );
        } catch (
            AmountMismatchException
            |OrderItemsMismatchException
            |CallbackDomainNotAllowedException
            |MinimumAmountNotMetException
            |PaymentMethodNotAvailableException
            |PaymentMethodDomainMismatchException $e
        ) {
            // 前四类是通用下单校验；后两类只在商户传了 payment_method_key（指定支付渠道）时出现。
            return $this->errorResponse($e->errorCode(), $e->getMessage(), 422);
        } catch (NoAvailablePaymentMethodException $e) {
            return $this->errorResponse($e->errorCode(), $e->getMessage(), 409);
        } catch (PaymentGatewayException $e) {
            // 远程创建支付订单失败：订单已落库，用同一商户单号重试可自动补创建。
            return $this->errorResponse('GATEWAY_ERROR', $e->getMessage(), 502);
        }

        return response()->json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'order_no' => $order->order_no,
                'payment_link_token' => $order->payment_link_token,
                'payment_url' => url("/payment/{$order->payment_link_token}"),
                // 支付网关插件渲染的收银台地址（远程创建支付订单成功后回填）
                'pay_url' => $order->pay_url,
                'converted_currency' => $order->converted_currency,
                'converted_amount' => (string) $order->converted_amount,
                'exchange_rate' => (string) $order->exchange_rate,
                'payment_method' => $order->payment_method,
                'status' => $order->status,
            ],
        ]);
    }

    public function query(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $request->validate([
            'order_no' => ['required_without:merchant_order_no', 'string'],
            'merchant_order_no' => ['required_without:order_no', 'string'],
        ]);

        $order = Order::query()
            ->forMerchant($merchantId)
            ->when($request->filled('order_no'), fn ($q) => $q->where('order_no', $request->input('order_no')))
            ->when($request->filled('merchant_order_no'), fn ($q) => $q->where('merchant_order_no', $request->input('merchant_order_no')))
            ->first();

        if (! $order) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Order not found', 404);
        }

        return response()->json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'order_no' => $order->order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'status' => $order->status,
                'platform' => $order->platform,
                'currency' => $order->currency,
                'amount' => (string) $order->amount,
                'converted_amount' => (string) $order->converted_amount,
                'payment_method' => $order->payment_method,
                'created_at' => $order->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * 外部系统（物流商/商户自建系统）同步物流信息。这里没有真实的后台登录用户，
     * 操作人固定记为 OrderShippingService::API_OPERATOR_ID，前端据此显示为"API"。
     */
    public function ship(SyncOrderShippingRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $validated = $request->validated();

        $order = Order::query()
            ->forMerchant($merchantId)
            ->where('merchant_order_no', $validated['merchant_order_no'])
            ->first();

        if (! $order) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Order not found', 404);
        }

        try {
            $shipping = $this->orderShippingService->record(
                $order,
                OrderShippingService::API_OPERATOR_ID,
                [
                    'logistics_company' => $validated['logistics_company'],
                    'tracking_number' => $validated['tracking_number'],
                    'tracking_url' => $validated['tracking_url'] ?? null,
                    'shipped_at' => $validated['shipped_at'],
                ]
            );
        } catch (ValidationException $e) {
            return $this->errorResponse('INVALID_ORDER_STATUS', $e->getMessage(), 422);
        }

        return response()->json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'order_no' => $order->order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'status' => $order->refresh()->status,
                'logistics_company' => $shipping->logistics_company,
                'tracking_number' => $shipping->tracking_number,
                'tracking_url' => $shipping->tracking_url,
                'shipped_at' => $shipping->shipped_at->toIso8601String(),
            ],
        ]);
    }

    private function errorResponse(string $code, string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'code' => $httpStatus,
            'msg' => $message,
            'error_code' => $code,
        ], $httpStatus);
    }
}
