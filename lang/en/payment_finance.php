<?php

return [
    'title' => 'Merchant payment financial settings',
    'description' => 'Applies to this merchant only. Blank values inherit payment method defaults; 0 explicitly sets zero. Changes apply to new orders only.',
    'effective_description' => 'Fees and reserves show the effective settings for your merchant. Blank overrides inherit channel defaults. Platform administrators maintain these settings.',
    'fee_percent' => 'Transaction fee percentage',
    'fee_fixed' => 'Fixed transaction fee',
    'refund_fee' => 'Refund fee',
    'chargeback_fee' => 'Chargeback fee',
    'reserve_percent' => 'Rolling reserve percentage',
    'reserve_days' => 'Reserve lock days',
    'reserve_help' => 'The reserve is deducted from net settlement proceeds. The lock period starts when the order settles.',
    'inherit_default' => 'Leave blank to inherit payment method defaults',
    'default_value' => 'Default',
    'days' => 'days',
    'reset' => 'Restore default settings',
    'unavailable_method' => 'This payment method is neither owned by this merchant nor assigned as a system payment method.',
];
