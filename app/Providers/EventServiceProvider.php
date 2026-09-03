<?php

namespace App\Providers;

use App\Listeners\SendTelegramNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $subscribe = [
        SendTelegramNotification::class,
    ];
}
