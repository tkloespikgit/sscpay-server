<?php

namespace App\Console\Commands;

use App\Models\CryptoCheckout;
use App\Services\CryptoCheckoutService;
use App\Support\CryptoCheckoutGuard;
use DomainException;
use Illuminate\Console\Command;

class PrepareCryptoCheckouts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:prepare-checkouts {--limit=5 : Maximum checkouts per invocation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '准备链上付款页、补查历史并恢复订阅或显式重新报价';

    /**
     * Execute the console command.
     */
    public function handle(CryptoCheckoutService $service): int
    {
        if (! CryptoCheckoutGuard::operational()) {
            $this->info('Crypto checkout is disabled.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 50.');

            return self::INVALID;
        }
        $checkouts = CryptoCheckout::query()->withoutGlobalScopes()->where('status', 'checking')
            ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get();
        $failed = 0;
        $processed = 0;
        $deadline = microtime(true) + 45;
        foreach ($checkouts as $checkout) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $processed++;
            try {
                $service->process($checkout->merchant_id, $checkout->order_id);
                $checkout->refresh();
                $this->line('Crypto checkout '.$checkout->id.': '.$checkout->status.' ('.$checkout->phase.').');
            } catch (\Throwable $error) {
                $failed++;
                $this->error('Crypto checkout '.$checkout->id.' will retry.');
                while ($error->getPrevious() !== null) {
                    $error = $error->getPrevious();
                }
                $this->line('Cause: '.class_basename($error).' at '.basename($error->getFile()).':'.$error->getLine().'.');
                if ($error instanceof DomainException && $error->getCode() >= 400 && $error->getCode() <= 599) {
                    $this->line('Provider HTTP status: '.$error->getCode().'.');
                }
            }
        }
        $this->info('Processed '.$processed.' checkout(s); '.$failed.' failed.');
        if ($processed === 0) {
            $next = CryptoCheckout::query()->withoutGlobalScopes()->where('status', 'checking')->min('next_attempt_at');
            $this->line($next === null ? 'No checkout is scheduled for preparation.' : 'Next checkout attempt: '.$next.' ('.config('app.timezone').').');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
