<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('purchase_order.document.title', ['number' => $purchaseOrder->order_no]) }}</title>
    @include('purchase-orders.partials.styles')
</head>
<body>
    @include('purchase-orders.partials.icons')
    @include('purchase-orders.partials.header')
    @include('purchase-orders.partials.navigation')

    <main class="workspace">
        <div class="content">
            <div class="patrol">
                <span class="spark" aria-hidden="true">✦</span>
                <strong>正为您实时巡检订单</strong>
                <small>截至 {{date('H:i')}}，暂无待处理 </small>
            </div>
            <div class="notice" id="notice">
                <span>【官方公告】1688平台面向分销买家推出“官方仓退货服务”，一个地址处理多平台自动售后，转寄全网包裹。点击开通</span>
                <button type="button" class="close" aria-label="关闭公告">×</button>
            </div>
            <nav class="work-tabs" aria-label="订单工作台">
                <button type="button" class="work-tab active"><svg class="icon" aria-hidden="true"><use href="#orders"/></svg>全部订单</button>
                <button type="button" class="work-tab"><svg class="icon" aria-hidden="true"><use href="#truck"/></svg>履约异常</button>
                <button type="button" class="work-tab"><svg class="icon" aria-hidden="true"><use href="#after-sale"/></svg>售后待处理</button>
                <button type="button" class="work-tab"><svg class="icon" aria-hidden="true"><use href="#contract"/></svg>复购合约</button>
                <button type="button" class="work-tab add" aria-label="添加视图">＋</button>
            </nav>
            <section class="orders-card" aria-label="{{ __('purchase_order.document.heading') }}">
                <nav class="status-tabs" aria-label="订单状态">
                    <button type="button" class="status-tab active">全部 </button>
                    <button type="button" class="status-tab">待付款 <b class="money" style=" color: orangered;font-size: 16px"> {{rand(2,5)}}</b></button>
                    <button type="button" class="status-tab">待发货 <b class="money" style=" color: orangered;font-size: 16px"> {{rand(2,5)}}</b> <i class="chevron"></i></button>
                    <button type="button" class="status-tab">待收货  <b class="money" style=" color: orangered;font-size: 16px"> {{rand(2,5)}}</b><i class="chevron"></i></button>
                    <button type="button" class="status-tab">退款售后  <b class="money" style=" color: orangered;font-size: 16px"> {{rand(1,2)}}</b> <i class="chevron"></i></button>
                    <button type="button" class="status-tab">待评价 <b class="money" style=" color: orangered;font-size: 16px"> {{rand(2,5)}}</b></button>
                    <button type="button" class="recycle"><svg class="icon" aria-hidden="true"><use href="#trash"/></svg>订单回收站</button>
                </nav>
                <div class="filter-area">
                    @include('purchase-orders.partials.filters')
                    <div class="criteria"><div class="criteria-content">你调整了筛选条件<button type="button" id="reset-filter">重置</button><span class="separator"></span><button type="button">保存为新视图</button></div></div>
                    <div class="order-tools">
                        <label class="checkbox-label"><input type="checkbox" class="select-all" aria-label="全选订单">全选<i class="chevron"></i></label>
                        <span class="result-count" aria-live="polite">共查询到 <span class="orange result-number">1</span> 个订单</span>
                        <button type="button" class="btn" disabled>导出所选订单</button>
                        <button type="button" class="btn" id="print-order">{{ __('purchase_order.document.print') }}</button>
                        <span class="tool-separator"></span><button type="button" class="btn" disabled>批量付款</button><button type="button" class="btn" disabled><span class="orange">✦</span>AI支付</button>
                        <div class="mini-pager"><button type="button" class="square" disabled aria-label="上一页"><i class="arrow-right arrow-left"></i></button><span>1 / 1</span><button type="button" class="square" disabled aria-label="下一页"><i class="arrow-right"></i></button></div>
                    </div>

                    @include('purchase-orders.partials.order')

                    <div class="empty-state" id="empty-state">没有找到符合条件的订单</div>
                    <footer class="bottom-tools">
                        <label class="checkbox-label"><input type="checkbox" class="select-all" aria-label="全选订单">全选<i class="chevron"></i></label>
                        <button type="button" class="btn" disabled>导出所选订单</button>
                        <a class="btn" href="{{ $paymentMethodUrl }}">导出当前条件</a>
                        <div class="bottom-pager"><span>每页</span><button type="button" class="page-size" disabled>10<i class="chevron"></i></button><span>条</span><span>共1条</span><button type="button" class="square" disabled aria-label="上一页"><i class="arrow-right arrow-left"></i></button><button type="button" class="square current" aria-current="page">1</button><button type="button" class="square" disabled aria-label="下一页"><i class="arrow-right"></i></button><span>前往</span><span class="jump">请输入 确定 ›</span></div>
                    </footer>
                </div>
            </section>
            @include('purchase-orders.partials.recommendations')
        </div>
    </main>
    <div class="support bottom"><svg class="icon" aria-hidden="true"><use href="#help"/></svg><button type="button" id="back-top" aria-label="返回顶部"><svg class="icon" aria-hidden="true"><use href="#up"/></svg></button></div>

    @include('purchase-orders.partials.scripts')
</body>
</html>
