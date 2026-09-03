<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>付款 - {{ $order->order_no }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: sans-serif; max-width: 480px; margin: 40px auto; padding: 0 16px;">
    <h2>{{ $order->merchant->name }}</h2>
    <p>订单号：{{ $order->order_no }}</p>
    <p>客户：{{ $order->customer_first_name }} {{ $order->customer_last_name }}</p>
    <p>应付金额：{{ $order->currency }} {{ $order->amount }}</p>

    <h3>商品明细</h3>
    <ul>
        @foreach ($order->items as $item)
            <li>{{ $item->product_name }} × {{ $item->quantity }} — {{ $order->currency }} {{ $item->total_price }}</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('payment.confirm', $order->payment_link_token) }}">
        @csrf
        <label>
            支付方式：{{ $order->payment_method }}
            <input type="hidden" name="payment_method" value="{{ $order->payment_method }}">
        </label>
        <button type="submit">确认支付</button>
    </form>
</body>
</html>
