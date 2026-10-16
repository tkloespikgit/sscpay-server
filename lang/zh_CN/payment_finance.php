<?php

return [
    'title' => '商户支付方式财务配置',
    'description' => '仅对该商户生效；空值继承支付方式默认配置，填写 0 表示明确设为零。修改仅适用于新订单。',
    'effective_description' => '手续费和保证金显示当前商户的生效配置；空值继承渠道默认值，由平台管理员维护。',
    'fee_percent' => '交易手续费比例',
    'fee_fixed' => '交易固定手续费',
    'refund_fee' => '退款手续费',
    'chargeback_fee' => '拒付手续费',
    'reserve_percent' => '循环保证金比例',
    'reserve_days' => '保证金锁定天数',
    'reserve_help' => '按净结算金额划分循环保证金，锁定期从该订单结算时起算。',
    'inherit_default' => '留空继承支付方式默认值',
    'default_value' => '继承默认',
    'days' => '天',
    'reset' => '恢复默认配置',
    'unavailable_method' => '该支付方式不属于此商户，也未以系统级渠道分配给此商户。',
];
