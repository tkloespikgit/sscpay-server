<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Schedule::command('exchange:fetch')->hourly();

Schedule::command('db:backup:upload')->everySixHours();

Schedule::command('order-events:sync')->everyMinute()->withoutOverlapping();

Schedule::command('order-notifications:process-due')->everyMinute()->withoutOverlapping();