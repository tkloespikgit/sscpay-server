<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\MerchantPanelProvider;
use App\Providers\Filament\ObserverPanelProvider;
use App\Services\Crypto\CryptoServiceProvider;
use App\Services\PaymentGateway\PaymentGatewayServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    MerchantPanelProvider::class,
    ObserverPanelProvider::class,
    PaymentGatewayServiceProvider::class,
    CryptoServiceProvider::class,
];
