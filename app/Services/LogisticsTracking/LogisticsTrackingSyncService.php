<?php

namespace App\Services\LogisticsTracking;

use App\Models\MerchantLogisticsTrackingSetting;
use App\Models\Order;
use App\Models\OrderShipping;
use App\Services\LogisticsTracking\Contracts\LogisticsTrackingDriver;
use App\Services\LogisticsTracking\DTOs\DriverCredentials;
use App\Services\LogisticsTracking\DTOs\TrackingNumber;
use App\Services\LogisticsTracking\DTOs\TrackSnapshot;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * 物流轨迹同步编排：注册运单、应用 Webhook/主动查询快照、按本地字段重算异常。
 *
 * 启用门闩（gatesAllow）：平台开放可选驱动 ∧ 订单 logistics_tracking_enabled 快照
 * ∧ 商户已选可用驱动且凭证齐全。无历史运单回填；异常 false→true 边沿可主动查一次。
 * 与 WordPress 站点物流同步（SyncOrderTrackingJob / sync_status）无关。
 */
class LogisticsTrackingSyncService
{
    /** 发货超过阈值仍未签收。 */
    public const ANOMALY_NO_DELIVERY = 'no_delivery_15d';

    /** 轨迹事件超过阈值无更新（且尚未判为未签收异常时作为备选原因）。 */
    public const ANOMALY_STALE = 'stale_5d';

    /** 发货后未签收视为异常的小时数（15×24）。 */
    public const NO_DELIVERY_HOURS = 360;

    /** 轨迹无更新视为异常的小时数（5×24）。 */
    public const STALE_EVENT_HOURS = 120;

    /** 未发货超期通知：支付后小时数（10×24）。 */
    public const UNSHIPPED_HOURS = 240;

    /**
     * 定时重算异常时纳入的订单状态。
     *
     * @var list<string>
     */
    public const TRACKING_ORDER_STATUSES = [
        'paid', 'shipped', 'completed', 'partially_refunded', 'disputing', Order::STATUS_DISPUTE_REVIEW,
    ];

    public function __construct(private readonly LogisticsTrackingManager $manager) {}

    /** 委托 Manager 解析驱动实现。 */
    public function driver(?string $name = null): LogisticsTrackingDriver
    {
        return $this->manager->driver($name);
    }

    /** 平台是否至少开放一个可用驱动。 */
    public function isPlatformEnabled(): bool
    {
        return $this->manager->hasAvailableDrivers();
    }

    /** 订单建单时拷贝的 logistics_tracking_enabled 快照是否允许轨迹对接。 */
    public function orderAllowsTracking(OrderShipping $shipping): bool
    {
        $shipping->loadMissing('order');

        return (bool) ($shipping->order?->logistics_tracking_enabled);
    }

    /** 按商户取物流跟踪配置（绕过租户全局 Scope）。 */
    public function settingForMerchant(int $merchantId): ?MerchantLogisticsTrackingSetting
    {
        return MerchantLogisticsTrackingSetting::query()
            ->withoutGlobalScopes()
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /** 将商户设置组装为 Driver 出站凭证 DTO。 */
    public function credentialsFromSetting(MerchantLogisticsTrackingSetting $setting): DriverCredentials
    {
        return new DriverCredentials(
            apiToken: (string) $setting->api_token,
            webhookSecret: (string) $setting->webhook_secret,
            baseUrl: filled($setting->base_url) ? (string) $setting->base_url : null,
        );
    }

    /**
     * 启用门闩：平台有可选驱动 ∧ 订单快照 ∧ 商户已选可用驱动且凭证齐全。
     * 通过时把匹配到的设置写入 $setting 引用，供调用方复用，避免重复查库。
     */
    public function gatesAllow(OrderShipping $shipping, ?MerchantLogisticsTrackingSetting &$setting = null): bool
    {
        if (! $this->isPlatformEnabled() || ! $this->orderAllowsTracking($shipping)) {
            return false;
        }

        $setting = $this->settingForMerchant((int) $shipping->merchant_id);

        return $setting !== null
            && $setting->isUsable()
            && $this->manager->isDriverAvailable((string) $setting->driver);
    }

    /**
     * 向当前商户选用的 Provider 注册运单；门闩未通过或运单号空则静默跳过。
     * 成功写入 tracking_provider / tracking_registered_at；失败写入 tracking_error。
     */
    public function registerShipping(OrderShipping $shipping, ?string $driverName = null): void
    {
        $setting = null;

        if (! $this->gatesAllow($shipping, $setting) || $setting === null) {
            return;
        }

        $driverName ??= (string) $setting->driver;

        try {
            $driver = $this->driver($driverName);
        } catch (InvalidArgumentException) {
            return;
        }

        $number = trim((string) $shipping->tracking_number);

        if ($number === '') {
            return;
        }

        $tracking = new TrackingNumber($number, $shipping->tracking_carrier_code);
        $credentials = $this->credentialsFromSetting($setting);

        try {
            $result = $driver->register($tracking, $credentials);
        } catch (Throwable $exception) {
            $shipping->update([
                'tracking_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            throw $exception;
        }

        if (! $result->accepted) {
            $shipping->update([
                'tracking_error' => $result->error,
            ]);

            return;
        }

        $shipping->update([
            'tracking_provider' => $driver->name(),
            'tracking_registered_at' => now(),
            'tracking_carrier_code' => $result->carrierCode ?: $shipping->tracking_carrier_code,
            'tracking_error' => null,
        ]);
    }

    /**
     * Webhook 批量落库：仅更新属于 $merchantId 且订单快照开启的运单。
     *
     * @param  list<TrackSnapshot>  $snapshots
     * @return int 实际写入轨迹的运单数
     */
    public function applyWebhookSnapshots(
        array $snapshots,
        string $driverName,
        int $merchantId,
    ): int {
        $updated = 0;

        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof TrackSnapshot) {
                continue;
            }

            if ($this->applySnapshot(
                $snapshot,
                driverName: $driverName,
                fromPoll: false,
                merchantId: $merchantId,
            )) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * 将单条快照写入 OrderShipping.tracking_*，并重算异常标记。
     * $fromPoll=true 时额外更新 tracking_last_polled_at；快照关闭的订单拒绝写入。
     */
    public function applySnapshot(
        TrackSnapshot $snapshot,
        ?string $driverName = null,
        bool $fromPoll = false,
        ?OrderShipping $shipping = null,
        ?int $merchantId = null,
    ): bool {
        $shipping ??= $this->findShipping($snapshot->number, $snapshot->carrierCode, $merchantId);

        if (! $shipping) {
            Log::info('logistics tracking: 未匹配到本地物流记录', [
                'number' => $snapshot->number,
                'carrier' => $snapshot->carrierCode,
                'driver' => $driverName,
                'merchant_id' => $merchantId,
            ]);

            return false;
        }

        if ($merchantId !== null && (int) $shipping->merchant_id !== $merchantId) {
            Log::warning('logistics tracking: 运单商户与 Webhook 商户不一致', [
                'order_shipping_id' => $shipping->id,
                'shipping_merchant_id' => $shipping->merchant_id,
                'webhook_merchant_id' => $merchantId,
            ]);

            return false;
        }

        if (! $this->orderAllowsTracking($shipping)) {
            return false;
        }

        $driverName ??= $shipping->tracking_provider
            ?: (string) ($this->settingForMerchant((int) $shipping->merchant_id)?->driver ?? '');

        $status = $snapshot->status ?? $shipping->tracking_status;
        $subStatus = $snapshot->subStatus ?? $shipping->tracking_sub_status;
        $deliveredAt = $shipping->tracking_delivered_at;

        if ($status === 'Delivered') {
            $deliveredAt = $snapshot->deliveredAt ?? $deliveredAt ?? now();
        } elseif ($snapshot->deliveredAt !== null) {
            $deliveredAt = $snapshot->deliveredAt;
        }

        $attributes = [
            'tracking_provider' => $driverName,
            'tracking_carrier_code' => $snapshot->carrierCode ?: $shipping->tracking_carrier_code,
            'tracking_status' => $status,
            'tracking_sub_status' => $subStatus,
            'tracking_last_event_at' => $snapshot->lastEventAt ?? $shipping->tracking_last_event_at,
            'tracking_last_event_desc' => $snapshot->lastEventDesc !== null
                ? mb_substr($snapshot->lastEventDesc, 0, 500)
                : $shipping->tracking_last_event_desc,
            'tracking_delivered_at' => $deliveredAt,
            'tracking_checked_at' => now(),
            'tracking_error' => null,
        ];

        if ($fromPoll) {
            $attributes['tracking_last_polled_at'] = now();
        }

        $shipping->fill($attributes);
        $this->applyAnomalyFlags($shipping);
        $shipping->save();

        return true;
    }

    /**
     * 重算异常；仅在 logistics_anomaly false→true 且启用门闩通过时主动查一次。
     */
    public function recalculateAnomaly(OrderShipping $shipping, ?string $driverName = null): void
    {
        $wasAnomaly = (bool) $shipping->logistics_anomaly;
        $willBeAnomaly = $this->predictAnomaly($shipping) !== null;
        $setting = null;

        if (
            ! $wasAnomaly
            && $willBeAnomaly
            && $this->gatesAllow($shipping, $setting)
            && $setting !== null
        ) {
            $this->pollWithCredentials($shipping, $setting, $driverName);

            return;
        }

        $this->applyAnomalyFlags($shipping);
        $shipping->save();
    }

    /**
     * 主动向当前驱动查询最新轨迹（详情页「查询最新物流轨迹」）。
     * 需启用门闩通过且运单号非空；成功写入快照返回 true。
     */
    public function pollTrackInfo(OrderShipping $shipping, ?string $driverName = null): bool
    {
        $setting = null;

        if (! $this->gatesAllow($shipping, $setting) || $setting === null) {
            return false;
        }

        if (trim((string) $shipping->tracking_number) === '') {
            return false;
        }

        return $this->pollWithCredentials(
            $shipping,
            $setting,
            $driverName ?? (string) $setting->driver,
        );
    }

    /**
     * 按 predictAnomaly 结果写入 logistics_anomaly / reason / at（就地改模型，不 save）。
     * 首次判异时记录 anomaly_at；恢复正常时清空 reason/at。
     */
    public function applyAnomalyFlags(OrderShipping $shipping): void
    {
        $reason = $this->predictAnomaly($shipping);

        if ($reason === null) {
            $shipping->logistics_anomaly = false;
            $shipping->logistics_anomaly_reason = null;
            $shipping->logistics_anomaly_at = null;

            return;
        }

        $shipping->logistics_anomaly = true;
        $shipping->logistics_anomaly_reason = $reason;
        $shipping->logistics_anomaly_at = $shipping->logistics_anomaly_at ?? now();
    }

    /**
     * 仅依据本地 shipped_at / tracking_* 字段预测异常原因码；已签收返回 null。
     * 优先级：超期未签收（NO_DELIVERY）优先于轨迹陈旧（STALE）。
     */
    public function predictAnomaly(OrderShipping $shipping): ?string
    {
        if ($shipping->tracking_status === 'Delivered' || $shipping->tracking_delivered_at !== null) {
            return null;
        }

        $shippedAt = $shipping->shipped_at;

        if (! $shippedAt) {
            return null;
        }

        $now = now();
        $reason = null;

        if ($shippedAt->lte($now->copy()->subHours(self::NO_DELIVERY_HOURS))) {
            $reason = self::ANOMALY_NO_DELIVERY;
        }

        $eventAnchor = $shipping->tracking_last_event_at ?? $shippedAt;

        if ($eventAnchor->lte($now->copy()->subHours(self::STALE_EVENT_HOURS))) {
            $reason ??= self::ANOMALY_STALE;
        }

        return $reason;
    }

    /**
     * 使用已校验的商户凭证调用 driver getTrackInfo。
     *
     * @return bool 是否拿到快照并 applySnapshot
     */
    private function pollWithCredentials(
        OrderShipping $shipping,
        MerchantLogisticsTrackingSetting $setting,
        ?string $driverName = null,
    ): bool {
        try {
            $driver = $this->driver(
                $driverName
                    ?? $shipping->tracking_provider
                    ?: (string) $setting->driver
            );
        } catch (InvalidArgumentException) {
            $this->applyAnomalyFlags($shipping);
            $shipping->save();

            return false;
        }

        $number = trim((string) $shipping->tracking_number);

        if ($number === '') {
            $this->applyAnomalyFlags($shipping);
            $shipping->save();

            return false;
        }

        $credentials = $this->credentialsFromSetting($setting);

        try {
            $snapshot = $driver->getTrackInfo(
                new TrackingNumber($number, $shipping->tracking_carrier_code),
                $credentials,
            );

            if ($snapshot) {
                return $this->applySnapshot($snapshot, driverName: $driver->name(), fromPoll: true, shipping: $shipping);
            }

            $shipping->tracking_last_polled_at = now();
            $this->applyAnomalyFlags($shipping);
            $shipping->save();
        } catch (Throwable $exception) {
            $shipping->tracking_error = mb_substr($exception->getMessage(), 0, 1000);
            $shipping->tracking_last_polled_at = now();
            $this->applyAnomalyFlags($shipping);
            $shipping->save();

            Log::warning('logistics tracking: 主动查询失败', [
                'order_shipping_id' => $shipping->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * 按运单号匹配本地物流记录；优先同承运商编码，否则取最新一条。
     * Webhook 场景应传入 $merchantId，避免跨商户误写。
     */
    private function findShipping(string $number, ?string $carrierCode, ?int $merchantId = null): ?OrderShipping
    {
        $query = OrderShipping::query()->withoutGlobalScopes()
            ->where('tracking_number', $number)
            ->whereNull('deleted_at');

        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        if (filled($carrierCode)) {
            $matched = (clone $query)->where('tracking_carrier_code', $carrierCode)->first();

            if ($matched) {
                return $matched;
            }
        }

        return $query->orderByDesc('id')->first();
    }
}
