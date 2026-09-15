<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\MerchantPanelProvider::class,
    App\Providers\Filament\ObserverPanelProvider::class,
    App\Services\PaymentGateway\PaymentGatewayServiceProvider::class,
];
