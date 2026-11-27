<?php

return [

    'auth' => [
        'account' => 'Account',
    ],

    'orders' => [
        'model_label' => 'Order',
        'model_label_plural' => 'Orders',
        'export' => 'Export orders',
        'columns' => [
            'merchant_name' => 'Merchant Name',
            'agent_name' => 'Agent',
            'order_no' => 'System Order No.',
            'merchant_order_no' => 'Merchant Order No.',
            'transaction_id' => 'Channel Transaction No.',
            'amount' => 'Transaction Amount',
            'converted_amount' => 'Amount (USD)',
            'settlement_amount' => 'Settlement Amount',
        ],
        'statuses' => [
            'paid' => 'Paid',
            'refunded' => 'Refunded / Partially refunded',
            'chargeback' => 'Chargeback',
            'disputing' => 'Disputing',
        ],
        'nav_label' => 'Orders',
    ],

];
