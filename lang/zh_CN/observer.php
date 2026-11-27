<?php

return [

    'auth' => [
        'account' => '账号',
    ],

    'orders' => [
        'model_label' => '交易订单',
        'model_label_plural' => '交易订单',
        'export' => '导出订单',
        'columns' => [
            'merchant_name' => '商户名称',
            'agent_name' => '所属代理',
            'order_no' => '系统单号',
            'merchant_order_no' => '商户单号',
            'transaction_id' => '渠道单号',
            'amount' => '交易金额',
            'converted_amount' => '美金金额',
            'settlement_amount' => '入账金额',
        ],
        'statuses' => [
            'paid' => '支付成功',
            'refunded' => '退款/部分退款',
            'chargeback' => '拒付',
            'disputing' => '争议中',
        ],
        'nav_label' => '交易订单',
    ],

];
