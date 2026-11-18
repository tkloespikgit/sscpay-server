<?php

return [
    'source_path' => base_path('plugins'),
    'required' => ['sscpay-payment-paypal', 'sscpay-payment-creditcard', 'sscpay-opencart3', 'sscpay-opencart4'],
    'disk' => env('PLUGIN_RELEASE_DISK', 'local'),
    'prefix' => 'plugin-releases',
    'build_path' => storage_path('app/plugin-builds'),
];
