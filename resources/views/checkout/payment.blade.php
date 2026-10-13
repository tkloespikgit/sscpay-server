@extends('checkout.layout')

@section('title', __('checkout.page_title', ['title' => $link->title]))

@section('content')
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <h2 class="text-lg font-semibold">{{ __('checkout.payment.title') }}</h2>
        <p class="mt-2 text-sm text-slate-600">{{ __('checkout.payment.body') }}</p>
        <p class="mt-6 text-sm text-slate-500">{{ __('checkout.result.order_no') }}: {{ $order->order_no }}</p>
        <a href="{{ $order->pay_url }}" class="mt-4 block break-all text-sm text-blue-600 underline">{{ $order->pay_url }}</a>
        <a href="{{ $order->pay_url }}" class="mt-6 inline-block rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
            {{ __('checkout.payment.continue') }}
        </a>
    </div>
@endsection
