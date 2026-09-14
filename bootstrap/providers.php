<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\MerchantPanelProvider::class,
    App\Services\PaymentGateway\PaymentGatewayServiceProvider::class,
];
