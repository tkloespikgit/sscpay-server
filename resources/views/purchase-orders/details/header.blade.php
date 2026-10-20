<div class="top-strip">
    <div class="top-strip-inner">
        <div class="top-account">
            <span><i class="phone-icon" aria-hidden="true"></i>手机阿里</span>
            <span class="top-account-name">{{ $buyerName }}</span>
            <span>消息</span><span class="chat-icon" aria-hidden="true">••</span>
        </div>
        <nav class="top-site-links" aria-label="顶部导航">
            <a href="{{ route('purchase-orders.show', ['purchaseOrder' => $purchaseOrder->getKey()]) }}">1688首页</a>
            <a href="#">我的阿里<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">采购车<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">我的收藏<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">诚信通<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">实力商家<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">超级工厂<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">我是供应商<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">客服中心<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">网站导航<i class="top-arrow" aria-hidden="true"></i></a>
            <a href="#">网站无障碍</a>
        </nav>
    </div>
</div>
<header class="logo-strip">
    <div class="logo-strip-inner">
        <a href="{{ route('purchase-orders.show', ['purchaseOrder' => $purchaseOrder->getKey()]) }}" aria-label="1688">
            @include('purchase-orders.details.logo')
        </a>
    </div>
</header>
