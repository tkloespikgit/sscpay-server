<?php

namespace App\Services\LogisticsTracking;

use App\Models\SystemConfig;
use App\Services\LogisticsTracking\Contracts\LogisticsTrackingDriver;
use App\Services\LogisticsTracking\Drivers\SeventeenTrack\SeventeenTrackDriver;
use InvalidArgumentException;

/**
 * 物流跟踪驱动工厂与平台白名单过滤器。
 *
 * 两层约束：
 * 1. 代码注册表：`driver($name)` 仅解析已实现并注入的 Driver；
 * 2. 系统配置 `logistics_tracking.available_drivers`（JSON 数组）：平台开放给商户选用的名字；
 *    配置项 ∩ 代码可解析 = 真正可选；未实现的名字静默忽略；空数组 = 平台关闭物流跟踪。
 *
 * 本类不发起 Provider HTTP，也不读商户密钥；凭证由调用方按商户注入到各 Driver。
 */
class LogisticsTrackingManager
{
    public const AVAILABLE_DRIVERS_CONFIG_KEY = 'logistics_tracking.available_drivers';

    /** @var array<string, LogisticsTrackingDriver> 已解析驱动实例缓存 */
    private array $resolved = [];

    public function __construct(
        private readonly SeventeenTrackDriver $seventeenTrack,
    ) {}

    /**
     * 按驱动名解析实现；空名或未注册名抛 InvalidArgumentException。
     */
    public function driver(?string $name = null): LogisticsTrackingDriver
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Logistics tracking driver is not configured');
        }

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $driver = match ($name) {
            SeventeenTrackDriver::NAME => $this->seventeenTrack,
            default => throw new InvalidArgumentException("Unknown logistics tracking driver [{$name}]"),
        };

        return $this->resolved[$name] = $driver;
    }

    /**
     * 系统开放给商户选用的驱动名列表（配置 ∩ 代码已实现）；空=平台关闭。
     *
     * @return list<string>
     */
    public function availableDriverNames(): array
    {
        $names = [];

        foreach (SystemConfig::getArray(self::AVAILABLE_DRIVERS_CONFIG_KEY, []) as $name) {
            $name = trim((string) $name);

            if ($name === '' || in_array($name, $names, true)) {
                continue;
            }

            try {
                $this->driver($name);
            } catch (InvalidArgumentException) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /** 指定驱动是否同时在平台白名单且代码已实现。 */
    public function isDriverAvailable(string $name): bool
    {
        return in_array(trim($name), $this->availableDriverNames(), true);
    }

    /** 平台是否至少开放一个可用驱动（物流跟踪总开关）。 */
    public function hasAvailableDrivers(): bool
    {
        return $this->availableDriverNames() !== [];
    }

    /**
     * 商户设置页下拉选项：driver_name => 可读标签。
     *
     * @return array<string, string>
     */
    public function availableDriverOptions(): array
    {
        $labels = [
            SeventeenTrackDriver::NAME => '17TRACK',
        ];

        $options = [];

        foreach ($this->availableDriverNames() as $name) {
            $options[$name] = $labels[$name] ?? $name;
        }

        return $options;
    }
}
