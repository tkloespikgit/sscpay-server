<footer class="site-footer">
    <div class="footer-inner">
        <div class="group-brand">
            @include('purchase-orders.details.footer-brand')
        </div>
        <div>
            <p class="footer-links">阿里巴巴集团&nbsp; | &nbsp;阿里巴巴国际站&nbsp; | &nbsp;1688&nbsp; | &nbsp;全球速卖通&nbsp; | &nbsp;淘宝网&nbsp; | &nbsp;天猫&nbsp; | &nbsp;一淘&nbsp; | &nbsp;飞猪&nbsp; | &nbsp;阿里妈妈&nbsp; | &nbsp;阿里云计算&nbsp; | &nbsp;AliOS</p>
            <p class="footer-links">阿里通信&nbsp; | &nbsp;UC&nbsp; | &nbsp;支付宝&nbsp; | &nbsp;钉钉&nbsp; | &nbsp;阿里健康&nbsp; | &nbsp;跨境供应链&nbsp; | &nbsp;Lazada&nbsp; | &nbsp;达摩院&nbsp; | &nbsp;阿里安全&nbsp; | &nbsp;淘宝海外&nbsp; | &nbsp;1688Japan&nbsp; | &nbsp;1688百科</p>
            <p class="footer-links">关于阿里巴巴&nbsp; | &nbsp;联系我们&nbsp; | &nbsp;知识产权&nbsp; | &nbsp;廉正举报&nbsp; | &nbsp;法律声明&nbsp; | &nbsp;服务条款&nbsp; | &nbsp;隐私政策&nbsp; | &nbsp;网站导航</p>
            <div class="footer-muted">
                <div>阿里巴巴集团旗下企业间电子商务平台&nbsp; 1688.com</div>
                <div>供应链服务&nbsp; | &nbsp;商家服务&nbsp; | &nbsp;消费者保障&nbsp; | &nbsp;网络安全</div>
                <div>知识产权保护&nbsp; | &nbsp;交易服务&nbsp; | &nbsp;规则中心&nbsp; | &nbsp;服务条款</div>
                <div>© 2010–{{ now()->year }} 1688.com 版权所有&nbsp; 阿里巴巴集团</div>
            </div>
            <div class="footer-badges">
                @include('purchase-orders.details.footer-badges')
            </div>
        </div>
    </div>
</footer>
