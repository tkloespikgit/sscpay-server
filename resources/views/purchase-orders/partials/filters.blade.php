          <form class="filter-grid" id="order-search">
            <label class="field"><span class="field-label">订单号搜索</span><span class="control"><input name="orderNo" aria-label="订单号搜索" value="{{ $purchaseOrder->order_no }}"><button type="button" class="batch-import">批量导入</button></span></label>
            <label class="field"><span class="field-label">订单关键词</span><input name="keyword" placeholder="商品名称/订单号/下游订单号/运单号/批次号"></label>
            <label class="field"><span class="field-label">下单时间</span><select aria-label="下单时间"><option>全部</option><option>近三个月</option></select></label>
            <div class="field"><span class="field-label">收货人</span><div class="recipient"><input aria-label="收货人姓名" placeholder="姓名"><input aria-label="收货人手机电话" placeholder="手机/电话"></div></div>
            <label class="field"><span class="field-label">卖家</span><input placeholder="卖家登录名/公司/店铺名"></label>
            <label class="field"><span class="field-label">订单状态</span><select aria-label="订单状态"><option>全部</option><option>{{ $documentStatus }}</option></select></label>
            <label class="field"><span class="field-label">订单类型</span><select aria-label="订单类型"><option>全部</option></select></label>
            <label class="field"><span class="field-label">支付方式</span><select aria-label="支付方式"><option>全部</option></select></label>
            <label class="field"><span class="field-label">买家</span><input placeholder="发起人登录名/下单公司主体"></label>
            <div class="field"><span class="field-label">金额 <span style="color:#aaa">ⓘ</span></span><div class="range"><label class="price-input"><span>¥</span><input aria-label="最小金额" placeholder="最小金额"></label><span class="dash">—</span><label class="price-input"><span>¥</span><input aria-label="最大金额" placeholder="最大金额"></label></div></div>
            <div class="filter-actions"><span class="ai-filter">AI筛选 <button type="button" class="switch" role="switch" aria-checked="false" aria-label="AI筛选"></button></span><button type="button" class="collapse-filter">收起<i class="chevron up"></i></button><button type="reset" class="btn">清除选项</button><button type="submit" class="btn primary">搜索</button></div>
          </form>
