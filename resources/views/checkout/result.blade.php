@extends('checkout.layout')

@section('title', __('checkout.page_title', ['title' => $link->title]))

@section('content')
    {{--
        支付网关回跳后的结果页。

        ⚠️ 客户被跳回来只说明他离开了网关页面，**不等于钱已到账**。
        真实的支付状态由 /api/webhooks/payment-gateway/status 异步驱动
        （见 OrderPaymentStatusService），所以这里展示的是订单此刻的实际
        status，而不是无条件写"支付成功"——否则客户会拿着一张写着"成功"
        的页面来质问为什么货没发。
    --}}
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        @if ($outcome === 'success')
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50">
                <svg class="h-6 w-6 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
                </svg>
            </div>
            <h2 class="text-lg font-semibold">{{ __('checkout.result.success_title') }}</h2>
            <p class="mt-2 text-sm text-slate-600">{{ __('checkout.result.success_body') }}</p>
        @else
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-amber-50">
                <svg class="h-6 w-6 text-amber-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm1-11a1 1 0 1 0-2 0v4a1 1 0 1 0 2 0V7Zm-1 7a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd" />
                </svg>
            </div>
            <h2 class="text-lg font-semibold">{{ __('checkout.result.cancelled_title') }}</h2>
            <p class="mt-2 text-sm text-slate-600">{{ __('checkout.result.cancelled_body') }}</p>
        @endif

        @if ($order)
            <dl class="mx-auto mt-6 max-w-xs space-y-2 border-t border-slate-100 pt-6 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-500">{{ __('checkout.result.order_no') }}</dt>
                    <dd class="font-medium tabular-nums">{{ $order->order_no }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-500">{{ __('checkout.result.status') }}</dt>
                    <dd class="font-medium">{{ $order->status }}</dd>
                </div>
            </dl>
        @endif

        @if ($outcome !== 'success')
            <a href="{{ route('checkout.show', ['slug' => $link->slug]) }}"
               class="mt-6 inline-block rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                {{ __('checkout.result.back') }}
            </a>
        @endif
    </div>
@endsection
