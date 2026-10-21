<style>
    @font-face { font-family:"Alibaba Sans Price"; src:url("{{ asset('fonts/AlibabaSans102-Md.ttf') }}") format("truetype"); font-style:normal; font-weight:500; font-display:swap; }
    * { box-sizing:border-box; }
    html { scroll-behavior:smooth; }
    body { margin:0; min-width:1100px; color:#333; background:linear-gradient(#fff 0,#fafafa 440px,#f3f3f3 1372px); font:12px/1.5 "PingFang SC",Tahoma,Arial,"Microsoft YaHei","Hiragino Sans GB",STXihei,Heiti,"WenQuanYi Zen Hei",SimSun,sans-serif; }
    a { color:inherit; text-decoration:none; }
    button { font:inherit; cursor:pointer; }
    a:focus-visible,button:focus-visible { outline:2px solid #ff6800; outline-offset:3px; }
    button:disabled { cursor:default; }
    [hidden] { display:none !important; }
    .money { font-family:"Alibaba Sans Price",Arial,sans-serif; font-weight:500; font-synthesis:none; }
    .top-strip { height:30px; border-bottom:1px solid #ddd; background:#fff; color:#666; }
    .top-strip-inner { width:1200px; height:29px; margin:0 auto; display:flex; align-items:center; justify-content:space-between; }
    .top-account,.top-site-links { display:flex; align-items:center; gap:12px; white-space:nowrap; }
    .top-site-links { gap:10px; }
    .top-account-name,.orange { color:#ff6800; }
    .phone-icon { display:inline-block; width:10px; height:13px; border:1px solid #ff6800; border-radius:2px; vertical-align:-2px; margin-right:4px; }
    .top-arrow { display:inline-block; width:5px; height:5px; border-right:1px solid #888; border-bottom:1px solid #888; transform:rotate(45deg); margin-left:5px; vertical-align:3px; }
    .chat-icon { display:inline-flex; width:13px; height:13px; border-radius:50%; background:#00a8ee; color:#fff; font:bold 9px/13px Arial,sans-serif; align-items:center; justify-content:center; vertical-align:-1px; }
    .logo-strip { height:78px; border-bottom:1px solid #ff6800; background:#fff; }
    .logo-strip-inner { width:990px; height:77px; margin:0 auto; display:flex; align-items:flex-end; padding-bottom:10px; }
    .details-brand-image { display:block; width:112px; height:46px; }
    .detail-wrapper { width:990px; margin:30px auto 65px; }
    .status-card { border:1px solid #aeb4c3; }
    .timeline { height:107px; padding-top:25px; background:#fff; }
    .timeline ol { display:grid; grid-template-columns:repeat(4,180px); width:720px; margin:0 0 0 128px; padding:0; list-style:none; position:relative; }
    .timeline ol::before { content:""; position:absolute; top:4px; left:90px; right:90px; height:1px; background:#f0f0f0; }
    .timeline li { text-align:center; position:relative; }
    .timeline-dot { display:block; position:relative; width:8px; height:8px; margin:0 auto 5px; border:1px solid #e3e3e3; background:#fafafa; border-radius:50%; }
    .timeline strong { display:block; font-size:14px; line-height:24px; }
    .timeline time { display:block; margin-top:4px; color:#666; white-space:nowrap; }
    .status-information { min-height:203px; border-top:1px solid #aeb4c3; padding:22px 53px 20px; background:#faf7f2; }
    .status-information h1 { display:flex; align-items:center; gap:12px; margin:0 0 26px; font-size:14px; line-height:22px; font-weight:600; }
    .status-information h1 span { color:#ff6800; }
    .status-information ol { margin:0; padding-left:18px; line-height:26px; }
    .status-information a { color:#125ca4; }
    .detail-tabs { display:flex; height:29px; margin-top:30px; align-items:stretch; position:relative; z-index:1; }
    .detail-tab { min-width:90px; padding:0 16px; color:#444; font-size:14px; background:#fff; border:1px solid #ddd; border-right:0; }
    .detail-tab:last-child { border-right:1px solid #ddd; }
    .detail-tab.active { color:#ff6800; border-color:#aeb4c3; border-bottom-color:#fff; font-weight:600; }
    .detail-card { border:1px solid #aeb4c3; margin-top:-1px; background:#fff; }
    .detail-panel { padding:36px 24px 0; min-height:720px; }
    .party-information { display:grid; grid-template-columns:minmax(0,1fr) 390px; gap:44px; min-height:210px; padding:0 12px 14px; }
    .party-information h2 { margin:0 0 6px; font-size:12px; line-height:20px; }
    .information-list { margin:0; }
    .information-list > div { display:flex; gap:6px; align-items:baseline; min-height:25px; }
    .information-list dt { flex-shrink:0; margin-right: 9px}
    .information-list dd { margin:0; overflow-wrap:anywhere; }
    .information-list time { font-variant-numeric:tabular-nums; }
    .supplier-company { color:#125ca4; }
    .shipment-link { padding:0; background:none; border:0; margin-left:5px; color:#ff6800; }
    .items-table { width:100%; border:1px solid #edd2bf; border-collapse:separate; border-spacing:0; table-layout:fixed; }
    .items-table col.goods { width:35%; }
    .items-table col.unit { width:17%; }
    .items-table col.count { width:13%; }
    .items-table col.discount { width:18%; }
    .items-table col.state { width:17%; }
    .items-table thead { background:#faf7f2; }
    .items-table th { height:36px; font-weight:400; text-align:center; }
    .items-table th:first-child { text-align:left; padding-left:42px; }
    .items-table td { padding:12px 9px; text-align:center; vertical-align:middle; border-top:1px solid #f2e7df; overflow-wrap:anywhere; }
    .items-table tbody tr:first-child td { border-top:0; }
    .item-description { display:grid; grid-template-columns:86px minmax(0,1fr); gap:16px; padding:0 14px; text-align:left; min-height:108px; }
    .item-image { display:block; width:86px; height:86px; object-fit:contain; background:#fff; }
    .item-image-placeholder { background:#f7f7f7; color:#999; display:flex; align-items:center; justify-content:center; font-size:12px; }
    .item-title { margin:0; font-size:12px; line-height:16px; font-weight:400; overflow-wrap:anywhere; }
    .item-meta { color:#999; line-height:16px; }
    .item-perks { margin-top:5px; line-height:18px; }
    .item-perks span { display:inline; color:#ff6800; border:1px solid #ff6800; padding:0 1px; margin-right:3px; white-space:nowrap; line-height: 23px}
    .unit-price { color:#ff6800; font-weight:600; }
    .unit-price .money { white-space:nowrap; }
    .line-subtotal { color:#666; margin-top:3px; }
    .line-subtotal .money { white-space:nowrap; }
    .order-level-discount { color:#666; }
    .service-summary { display:flex; justify-content:flex-end; align-items:center; gap:12px; min-height:49px; padding-top:12px; color:#999; }
    .service-summary > span:last-child { font-size:24px; color:#bbb; font-weight:300; }
    .discount-explanation { margin:2px 0 27px; text-align:right; color:#999; }
    .amount-summary { margin:0 6px; }
    .amount-summary > div { display:flex; justify-content:space-between; min-height:32px; align-items:center; }
    .amount-summary dd { margin:0; color:#666; }
    .amount-summary .discount-row dd { color:#ff6800; }
    .amount-summary .paid-row { justify-content:flex-end; gap:8px; min-height:42px; }
    .paid-row dd { color:#333; }
    .paid-row strong { font-size:16px; font-weight:500; }
    .print-bar { height:76px; display:flex; align-items:center; justify-content:center; background:#faf7f2; }
    .orange-button { height:28px; border:1px solid #ff6800; background:#ff6800; color:#fff; padding:0 16px; font-weight:600; }
    .alternate-panel { padding:36px; }
    .alternate-panel h2 { margin:0 0 22px; font-size:14px; }
    .alternate-panel .information-list > div { min-height:34px; }
    .alternate-panel .information-list dt { width:120px; color:#777; }
    .alternate-note { padding:14px 18px; margin:24px 0; border:1px solid #edd2bf; background:#faf7f2; color:#888; }
    .site-footer { background:#ebebeb; min-height:290px; color:#888; padding:34px 0; }
    .footer-inner { width:1200px; margin:0 auto; display:grid; grid-template-columns:220px 1fr; gap:45px; }
    .group-brand { padding-top:7px; }
    .footer-brand-image { display:block; width:182px; height:32px; }
    .footer-links { line-height:28px; margin:0; }
    .footer-muted { color:#b4b4b4; line-height:24px; margin:0; }
    .footer-badges { margin-top:7px; }
    .footer-badges-image { display:block; width:494px; height:30px; }
    .back-top { position:fixed; right:10px; bottom:200px; display:flex; align-items:center; justify-content:center; width:44px; height:44px; background:#007bff; border:0; }
    .back-top span { display:block; width:14px; height:14px; border-left:2px solid #fff; border-top:2px solid #fff; transform:translateY(4px) rotate(45deg); }
    @media print {
        body { min-width:0; background:#fff; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
        .top-strip,.logo-strip,.detail-tabs,.print-bar,.site-footer,.back-top { display:none; }
        .detail-wrapper { width:100%; margin:0; }
        .detail-panel { min-height:0; padding-bottom:24px; }
        .status-card,.detail-card { border-color:#ccc; }
        .detail-card { margin-top:20px; }
        .timeline ol { margin:0 auto; }
        .items-table tr { break-inside:avoid; }
    }

    .money,.price-sign,.price-number,.price-input > span,.price-input input { font-family:"Alibaba Sans Price",Arial,sans-serif; font-synthesis:none; }

</style>
