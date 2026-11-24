<?php

use App\Http\Controllers\ChargebackWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/chargeback/{provider}', ChargebackWebhookController::class)
    ->where('provider', '[a-z0-9_-]{1,32}')
    ->middleware('throttle:600,1')
    ->name('webhooks.chargeback');
