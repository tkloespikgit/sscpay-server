<div class="party-information">
    <section>
        <h2>{{ __('purchase_order.detail.receiving_information') }}</h2>
        <dl class="information-list">
            <div><dt>{{ __('purchase_order.detail.order_number') }}:</dt><dd>{{ $purchaseOrder->order_no }}</dd></div>
            <div><dt>{{ __('purchase_order.detail.transaction_number') }}:</dt><dd>{{ $purchaseOrder->transaction_id }}</dd></div>
            <div><dt>{{ __('purchase_order.detail.recipient') }}:</dt><dd>{{ $detailFields['buyerContactName'] }}</dd></div>
            <div><dt>{{ __('purchase_order.detail.address') }}:</dt><dd>{{ $detailFields['buyerAddress'] }}</dd></div>
            <div><dt>{{ __('purchase_order.detail.mobile') }}:</dt><dd>{{ $detailFields['buyerPhone'] }}</dd></div>
            <div><dt>{{ __('purchase_order.detail.telephone') }}:</dt><dd></dd></div>
            <div><dt>{{ __('purchase_order.detail.dispatch_time') }}:</dt><dd><time datetime="{{ $purchaseOrder->shipped_at->toIso8601String() }}">
                        {{ $purchaseOrder->shipped_at->format('m月d日 H:i:s') }}已发货</time>
                    <button type="button" class="shipment-link" data-details-tab="logistics-tab"> 查看详情 &gt;</button></dd></div>
        </dl>
    </section>
    <section>
        <h2>{{ __('purchase_order.detail.seller_information') }}</h2>
        <dl class="information-list">
            <div><dt>{{ __('purchase_order.detail.supplier') }}:</dt><dd class="supplier-company">{{ $sellerCompany }}</dd></div>
            <div><dt>{{ __('purchase_order.detail.member_name') }}:</dt><dd>{{ $detailFields['sellerContactName'] }} &nbsp;&nbsp;&nbsp;
                    <svg fill="none" version="1.1" width="14.22216796875" height="14.22265625" viewBox="0 0 14.22216796875 14.22265625">
                        <defs>
                            <linearGradient x1="0.5" y1="0" x2="0.5" y2="1" id="master_svg0_51_352154">
                                <stop offset="0%" stop-color="#00B7FF" stop-opacity="1"></stop>
                                <stop offset="100%" stop-color="#009EFF" stop-opacity="1"></stop>
                            </linearGradient>
                        </defs>
                        <g>
                            <g>
                                <path d="M7.11111,0C5.94747,0,4.78384,0.323232,3.74949,0.905051C2.84444,0.129293,1.55152,0,1.55152,0C1.42222,0,1.22828,0.0646465,1.16364,0.193939C1.03434,0.323232,0.969697,0.452525,1.03434,0.646465C1.35758,2.06869,1.29293,2.84444,1.29293,3.03838C0.452525,4.26667,0,5.62424,0,7.11111C0,11.0545,3.16768,14.2222,7.11111,14.2222C8.67936,14.2222,10.1249,13.7212,11.2973,12.8697C13.073,11.5801,14.2222,9.4863,14.2222,7.11111C14.2222,3.16768,11.0545,0,7.11111,0Z" fill="url(#master_svg0_51_352154)" fill-opacity="1"></path>
                            </g>
                            <g>
                                <path d="M7.340648454742432,5.566628642669678C7.340648454742432,5.1104876426696775,6.970878454742431,4.740712642669678,6.514734454742432,4.740712642669678C6.058593454742431,4.740712642669678,5.688818454742432,5.1104876426696775,5.688818454742432,5.566628642669678L5.688818454742432,6.759242642669678C5.688818454742432,7.215382642669677,6.058593454742431,7.585152642669678,6.514734454742432,7.585152642669678C6.970878454742431,7.585152642669678,7.340648454742432,7.215382642669677,7.340648454742432,6.759242642669678L7.340648454742432,5.566628642669678ZM11.37775845474243,5.566628642669678C11.37775845474243,5.1104876426696775,11.007988454742431,4.740712642669678,10.551848454742432,4.740712642669678C10.095698454742433,4.740712642669678,9.725928454742432,5.1104876426696775,9.725928454742432,5.566628642669678L9.725928454742432,6.759242642669678C9.725928454742432,7.215382642669677,10.095698454742433,7.585152642669678,10.551848454742432,7.585152642669678C11.007988454742431,7.585152642669678,11.37775845474243,7.215382642669677,11.37775845474243,6.759242642669678L11.37775845474243,5.566628642669678Z" fill-rule="evenodd" fill="#FFFFFF" fill-opacity="1"></path>
                            </g>
                        </g>
                    </svg>
                </dd></div>
            <div><dt>{{ __('purchase_order.detail.mobile') }}:</dt><dd>{{ $detailFields['sellerPhone'] }}</dd></div>
            <div><dt>{{ __('purchase_order.detail.telephone') }}:</dt><dd></dd></div>
        </dl>
    </section>
</div>
