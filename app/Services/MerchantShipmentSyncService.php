<?php

namespace App\Services;

use App\Jobs\SyncMerchantShipmentJob;
use App\Models\Application;
use App\Models\Carrier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderShipmentSync;
use App\Models\OrderShipmentSyncAttempt;
use App\Models\OrderShipping;
use App\Models\TelegramBot;
use App\Rules\ShipmentSyncUrl;
use App\Support\OrderTelegramSummary;
use App\Support\ShipmentSyncEndpoint;
use App\Support\SignatureCanonicalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 商户网站物流回传：按保存留快照，队列单次执行，重试与提醒待办持久化。 */
class MerchantShipmentSyncService
{
    private const RETRY_INTERVALS = [30, 300, 1800, 3600];

    public function __construct(
        private readonly TelegramNotificationService $telegram,
        private readonly ShipmentSyncEndpoint $endpoint,
    ) {}

    public function canPush(Order $order): bool
    {
        $application = $this->applicationFor($order);

        return $application && $application->status && $application->is_shipment_sync_enabled
            && filled($application->shipment_sync_url)
            && $order->shipping()->where('merchant_id', $order->merchant_id)->exists();
    }

    public function pushManually(Order $order): OrderShipmentSync
    {
        $sync = DB::transaction(function () use ($order): ?OrderShipmentSync {
            Merchant::query()->whereKey($order->merchant_id)->lockForUpdate()->firstOrFail();
            $fresh = Order::query()->forMerchant($order->merchant_id)->whereKey($order->id)->lockForUpdate()->first();
            if (! $fresh || ! $this->canPush($fresh)) {
                throw ValidationException::withMessages(['shipment_sync' => __('admin.merchant_shipment_sync.validation_unavailable')]);
            }

            return $this->record($fresh, $fresh->shipping()->where('merchant_id', $fresh->merchant_id)->firstOrFail());
        });
        if (! $sync) {
            throw ValidationException::withMessages(['shipment_sync' => __('admin.merchant_shipment_sync.validation_unavailable')]);
        }

        return $sync;
    }

    public function record(Order $order, OrderShipping $shipping): ?OrderShipmentSync
    {
        if ((int) $shipping->merchant_id !== (int) $order->merchant_id || (int) $shipping->order_id !== (int) $order->id) {
            throw ValidationException::withMessages(['shipment_sync' => 'Shipment does not belong to this order.']);
        }

        return DB::transaction(function () use ($order, $shipping): ?OrderShipmentSync {
            Merchant::query()->whereKey($order->merchant_id)->lockForUpdate()->firstOrFail();
            Order::query()->forMerchant($order->merchant_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            OrderShipmentSync::query()->forMerchant($order->merchant_id)->where('order_id', $order->id)
                ->whereIn('status', ['pending', 'retrying', 'paused', 'processing'])
                ->update(['status' => 'superseded', 'next_attempt_at' => null]);
            $application = $this->applicationFor($order);
            if (! $application || ! $application->status || ! $application->is_shipment_sync_enabled) {
                return null;
            }
            $shipping->refresh();
            $carrier = Carrier::query()->whereRaw('UPPER(carrier_code) = ?', [mb_strtoupper($shipping->logistics_company)])->first();
            $payload = [
                'merchant_order_no' => (string) $order->merchant_order_no,
                'carrier' => (string) ($carrier?->carrier_name ?: $shipping->logistics_company),
                'carrier_code' => (string) ($carrier?->carrier_code ?: $shipping->logistics_company),
                'tracking_number' => (string) $shipping->tracking_number,
                'shipped_at' => $shipping->shipped_at->toIso8601String(),
            ];
            if ($order->combined_order_id) {
                $parent = $order->combinedOrder()->forMerchant($order->merchant_id)->firstOrFail();
                $payload['merchant_order_no'] = (string) $parent->merchant_order_no;
                $payload += [
                    'order_type' => 'combine', 'order_no' => $order->order_no,
                    'child_merchant_order_no' => (string) $order->merchant_order_no,
                    'parent_order_no' => $parent->order_no,
                    'parent_merchant_order_no' => $parent->merchant_order_no,
                ];
            }
            if (filled($shipping->tracking_url)) {
                $payload['tracking_url'] = $shipping->tracking_url;
            }
            $sync = OrderShipmentSync::create([
                'merchant_id' => $order->merchant_id, 'order_id' => $order->id,
                'application_id' => $application->id, 'order_shipping_id' => $shipping->id,
                'payload' => $payload, 'status' => 'pending', 'attempt_count' => 0, 'next_attempt_at' => now(),
            ]);
            SyncMerchantShipmentJob::dispatch($sync->id, $sync->merchant_id)->afterCommit();

            return $sync;
        });
    }

    public function attempt(int $syncId, int $merchantId): void
    {
        $sync = OrderShipmentSync::query()->forMerchant($merchantId)->find($syncId);
        if (! $sync) {
            return;
        }
        Cache::lock("merchant-shipment:{$merchantId}:{$sync->order_id}", 60)->get(function () use ($sync): void {
            $claimed = $this->claim($sync);
            if (! $claimed) {
                return;
            }
            [$sync, $attempt, $application] = $claimed;
            $body = $sync->payload;
            $headers = [
                'App-ID' => $application->app_id,
                'Timestamp' => (string) now()->timestamp,
                'X-Nonce' => bin2hex(random_bytes(16)),
            ];
            $body['sign'] = SignatureCanonicalizer::sign($headers['App-ID'], $headers['Timestamp'], $headers['X-Nonce'], $body, $application->api_key);
            $attempt->update(['request_headers' => $headers]);
            $status = null;
            $responseBody = null;
            $error = null;
            $retryable = false;
            $success = false;
            try {
                if (! ShipmentSyncUrl::isValid($application->shipment_sync_url)) {
                    throw new \InvalidArgumentException(__('admin.application.validation.shipment_sync_url'));
                }
                $response = Http::withOptions($this->endpoint->options($application->shipment_sync_url))
                    ->connectTimeout(3)->timeout(10)->withoutRedirecting()->acceptJson()
                    ->withHeaders($headers)->post($application->shipment_sync_url, $body);
                $status = $response->status();
                $responseBody = $this->redact($response->body(), $application, $body['sign']);
                $json = $response->json();
                $success = $status === 200 && is_array($json) && ($json['code'] ?? null) === 0;
                $retryable = $status === 429 || $status >= 500;
                if (! $success) {
                    $message = is_array($json) && is_string($json['msg'] ?? null) ? $json['msg'] : 'Invalid shipment response';
                    $error = $this->redact("HTTP {$status}: {$message}", $application, $body['sign']);
                }
            } catch (ConnectionException $exception) {
                $retryable = true;
                $error = 'Connection failed or timed out.';
            } catch (Throwable $exception) {
                $error = $this->redact($exception->getMessage(), $application, $body['sign']);
            }
            DB::transaction(function () use ($sync, $attempt, $status, $responseBody, $error, $retryable, $success): void {
                $current = OrderShipmentSync::query()->forMerchant($sync->merchant_id)->whereKey($sync->id)->lockForUpdate()->firstOrFail();
                $attempt->update(['response_status' => $status, 'response_body' => $responseBody, 'error_message' => $error]);
                $superseded = $current->status === 'superseded' || $this->hasNewer($current);
                $canRetry = ! $superseded && $retryable && $current->attempt_count < 5;
                $current->update([
                    'status' => $success ? 'success' : ($superseded ? 'superseded' : ($canRetry ? 'retrying' : 'failed')),
                    'last_error' => $error, 'synced_at' => $success ? now() : null,
                    'next_attempt_at' => $canRetry ? now()->addSeconds(self::RETRY_INTERVALS[$current->attempt_count - 1]) : null,
                    'alert_status' => ! $success && ! $superseded && ! $canRetry ? 'pending' : null,
                    'next_alert_at' => ! $success && ! $superseded && ! $canRetry ? now() : null,
                ]);
            });
        });
    }

    /** @return array{OrderShipmentSync, OrderShipmentSyncAttempt, Application}|null */
    private function claim(OrderShipmentSync $sync): ?array
    {
        return DB::transaction(function () use ($sync): ?array {
            Merchant::query()->whereKey($sync->merchant_id)->lockForUpdate()->firstOrFail();
            $sync = OrderShipmentSync::query()->forMerchant($sync->merchant_id)->whereKey($sync->id)->lockForUpdate()->firstOrFail();
            if (! in_array($sync->status, ['pending', 'retrying', 'paused', 'processing'], true)
                || ($sync->next_attempt_at && $sync->next_attempt_at->isFuture())) {
                return null;
            }
            if ($this->hasNewer($sync)) {
                $sync->update(['status' => 'superseded', 'next_attempt_at' => null]);

                return null;
            }
            $order = Order::query()->forMerchant($sync->merchant_id)->find($sync->order_id);
            $application = $order ? $this->applicationFor($order) : null;
            $shipping = OrderShipping::query()->forMerchant($sync->merchant_id)->where('order_id', $sync->order_id)->find($sync->order_shipping_id);
            if (! $order || ! $shipping || ! $application || (int) $application->id !== (int) $sync->application_id) {
                $this->fail($sync, 'Order, shipment or application is unavailable.');

                return null;
            }
            if (! $application->status || ! $application->is_shipment_sync_enabled) {
                $sync->update(['status' => 'paused', 'next_attempt_at' => now()->addMinute()]);

                return null;
            }
            if (blank($application->app_id) || blank($application->api_key)) {
                $this->fail($sync, 'Application signing credentials are missing.');

                return null;
            }
            if ($sync->status === 'processing') {
                $sync->attempts()->where('merchant_id', $sync->merchant_id)->where('attempt_number', $sync->attempt_count)
                    ->update(['error_message' => 'Worker interrupted before saving the response.']);
            }
            if ($sync->attempt_count >= 5) {
                $this->fail($sync, 'The last shipment attempt was interrupted; retry limit reached.');

                return null;
            }
            $sync->update(['status' => 'processing', 'attempt_count' => $sync->attempt_count + 1, 'next_attempt_at' => now()->addMinutes(2)]);
            $attempt = OrderShipmentSyncAttempt::create([
                'merchant_id' => $sync->merchant_id, 'order_shipment_sync_id' => $sync->id,
                'attempt_number' => $sync->attempt_count, 'request_url' => (string) $application->shipment_sync_url, 'attempted_at' => now(),
            ]);

            return [$sync, $attempt, $application];
        });
    }

    public function dispatchDue(int $limit = 500): int
    {
        $syncs = OrderShipmentSync::query()->withoutGlobalScopes()
            ->whereIn('status', ['pending', 'retrying', 'paused', 'processing'])
            ->where(function ($query): void {
                $query->where('status', '!=', 'paused')
                    ->orWhereHas('application', fn ($application) => $application->where('status', true)
                        ->where('is_shipment_sync_enabled', true)
                        ->whereColumn('applications.merchant_id', 'order_shipment_syncs.merchant_id'));
            })
            ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get();
        foreach ($syncs as $sync) {
            SyncMerchantShipmentJob::dispatch($sync->id, $sync->merchant_id)->afterCommit();
        }

        return $syncs->count();
    }

    public function notifyFailures(int $limit = 500): int
    {
        $count = 0;
        $groups = OrderShipmentSync::query()->withoutGlobalScopes()->where('alert_status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_alert_at')->orWhere('next_alert_at', '<=', now()))
            ->where('status', 'failed')->orderBy('next_alert_at')->orderBy('id')->limit($limit)->get()->groupBy('merchant_id');
        foreach ($groups as $merchantId => $syncs) {
            Cache::lock("merchant-shipment-alert:{$merchantId}", 60)->get(function () use ($merchantId, $syncs, &$count): void {
                $pending = OrderShipmentSync::query()->forMerchant((int) $merchantId)->whereIn('id', $syncs->modelKeys())
                    ->where('alert_status', 'pending')->where('status', 'failed')
                    ->with(['order' => fn ($query) => $query->withoutGlobalScopes()->withTrashed()->where('merchant_id', (int) $merchantId)])
                    ->get();
                $bot = TelegramBot::query()->forMerchant((int) $merchantId)->first();
                if (! $bot?->isUsable()) {
                    OrderShipmentSync::query()->forMerchant((int) $merchantId)->whereIn('id', $pending->modelKeys())
                        ->update(['alert_status' => 'unavailable']);

                    return;
                }
                foreach ($this->failureAlertBatches($pending->take(25), (int) $merchantId) as $batch) {
                    if (! $this->telegram->send((int) $merchantId, $batch['message'])) {
                        OrderShipmentSync::query()->forMerchant((int) $merchantId)->whereIn('id', $pending->modelKeys())
                            ->where('alert_status', 'pending')->update(['next_alert_at' => now()->addMinutes(5)]);
                        break;
                    }
                    OrderShipmentSync::query()->forMerchant((int) $merchantId)->whereIn('id', $batch['ids'])
                        ->update(['alert_status' => 'sent', 'alerted_at' => now(), 'next_alert_at' => null]);
                    $count += count($batch['ids']);
                }
            });
        }

        return $count;
    }

    /**
     * @param  Collection<int, OrderShipmentSync>  $syncs
     * @return list<array{ids: list<int>, message: string}>
     */
    private function failureAlertBatches(Collection $syncs, int $merchantId): array
    {
        $batches = [];
        $ids = [];
        $heading = __('admin.merchant_shipment_sync.telegram_heading', ['count' => $syncs->count()]);
        $message = $heading;
        $orders = $syncs->map(fn (OrderShipmentSync $sync): ?Order => $sync->order)
            ->filter(fn (?Order $order): bool => $order !== null && (int) $order->merchant_id === $merchantId)
            ->unique('id')->values();
        $summaries = OrderTelegramSummary::forOrders($orders);

        foreach ($syncs as $sync) {
            $application = Application::query()->forMerchant($merchantId)->find($sync->application_id);
            $details = [
                'application' => (string) $application?->name,
                'order_no' => (string) $sync->order?->order_no,
                'merchant_order_no' => (string) ($sync->payload['merchant_order_no'] ?? ''),
                'tracking_number' => (string) ($sync->payload['tracking_number'] ?? ''),
                'error' => (string) $sync->last_error,
            ];
            $details = array_map(fn (string $value): string => htmlspecialchars(mb_substr($value, 0, 50), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $details);
            $summary = $summaries[(int) $sync->order_id]
                ?? OrderTelegramSummary::render(['merchant_order_no' => (string) ($sync->payload['merchant_order_no'] ?? '')]);
            $line = __('admin.merchant_shipment_sync.telegram_line', $details)."\n".$summary;

            if (count($ids) >= 5 || ($ids !== [] && mb_strlen($message."\n\n".$line) > 4000)) {
                $batches[] = ['ids' => $ids, 'message' => $message];
                $ids = [];
                $message = $heading;
            }

            $ids[] = (int) $sync->id;
            $message .= "\n\n".$line;
        }

        if ($ids !== []) {
            $batches[] = ['ids' => $ids, 'message' => $message];
        }

        return $batches;
    }

    private function applicationFor(Order $order): ?Application
    {
        return Application::query()->forMerchant($order->merchant_id)->find($order->application_id);
    }

    private function hasNewer(OrderShipmentSync $sync): bool
    {
        return OrderShipmentSync::query()->forMerchant($sync->merchant_id)->where('order_id', $sync->order_id)->where('id', '>', $sync->id)->exists();
    }

    private function fail(OrderShipmentSync $sync, string $error): void
    {
        $sync->update(['status' => 'failed', 'last_error' => $error, 'next_attempt_at' => null, 'alert_status' => 'pending', 'next_alert_at' => now()]);
    }

    private function redact(string $message, Application $application, string $signature): string
    {
        return mb_substr(str_replace(array_filter([$application->api_key, $signature]), '[redacted]', $message), 0, 5000);
    }
}
