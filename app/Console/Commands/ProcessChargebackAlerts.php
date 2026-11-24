<?php

namespace App\Console\Commands;

use App\Models\ChargebackAlert;
use App\Models\ChargebackAlertFeedback;
use App\Models\Scopes\MerchantScope;
use App\Services\ChargebackAlerts\ChargebackAlertDrivers;
use App\Services\ChargebackAlerts\ChargebackAlertMatcher;
use App\Services\ChargebackAlerts\ChargebackAlertProcessing;
use App\Services\ChargebackAlerts\ChargebackFeedback;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessChargebackAlerts extends Command
{
    protected $signature = 'chargeback-alerts:process';

    protected $description = 'Match chargeback alerts and process due feedback and reminders';

    public function handle(ChargebackAlertMatcher $matcher, ChargebackFeedback $feedback, ChargebackAlertProcessing $processing, ChargebackAlertDrivers $drivers): int
    {
        $services = array_keys($drivers->serviceOptions());
        $activeService = static function (Builder $alerts) use ($services): void {
            $alerts->where(function (Builder $active) use ($services): void {
                if ($services === []) {
                    $active->whereIn('service', []);
                }
                foreach ($services as $service) {
                    $active->orWhere(fn (Builder $product) => $product->where('service', $service)
                        ->whereHas('account', fn (Builder $accounts) => $accounts->where('enabled', true)->whereJsonContains('services', $service)));
                }
            });
        };
        $alerts = ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class)->where($activeService);
        $limit = 50;
        $matches = (clone $alerts)->where('next_match_at', '<=', now())->orderBy('next_match_at')->orderBy('id')->limit($limit)->pluck('id');
        $dueFeedback = ChargebackAlertFeedback::query()->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->whereHas('alert', $activeService)
            ->orderBy('next_attempt_at')->orderBy('id')->limit(4)->pluck('id');
        $interrupted = ChargebackAlertFeedback::query()->where('status', 'sending')->where('started_at', '<=', now()->subMinutes(2))
            ->orderBy('id')->limit($limit)->pluck('id');
        $notices = (clone $alerts)->where('next_notice_at', '<=', now())->orderBy('next_notice_at')->orderBy('id')->limit(8)->pluck('id');

        $ok = true;
        foreach ([
            [$interrupted, $feedback->recover(...)],
            [$matches, $matcher->attempt(...)],
            [$dueFeedback, $feedback->send(...)],
            [$notices, $processing->notify(...)],
        ] as [$ids, $action]) {
            foreach ($ids as $id) {
                try {
                    $action((int) $id);
                } catch (Throwable $error) {
                    $ok = false;
                    Log::warning('Chargeback processing failed.', ['record_id' => $id, 'exception' => $error::class]);
                }
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
