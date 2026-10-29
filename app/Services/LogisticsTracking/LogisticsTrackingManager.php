<?php

namespace App\Services\LogisticsTracking;

use App\Models\SystemConfig;
use App\Services\LogisticsTracking\Contracts\LogisticsTrackingDriver;
use App\Services\LogisticsTracking\Drivers\SeventeenTrack\SeventeenTrackDriver;
use InvalidArgumentException;

/**
 * 物流跟踪驱动工厂与平台白名单。
 *
 * 两层约束（方案 B）：
 * 1. 系统配置 `logistics_tracking.available_drivers`：平台开放给商户选用的名字（可预填未实现驱动）；
 *    空数组 = 平台关闭。商户下拉与保存使用配置全量。
 * 2. 代码注册表：`driver($name)` 仅解析已实现驱动；运行时门闩要求「在配置中且已实现」，
 *    未实现名静默跳过（不 500）。
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
     * 系统配置中的驱动名全量（去空、去重）；含尚未实现的预填名。空=平台关闭。
     *
     * @return list<string>
     */
    public function configuredDriverNames(): array
    {
        $names = [];

        foreach (SystemConfig::getArray(self::AVAILABLE_DRIVERS_CONFIG_KEY, []) as $name) {
            $name = trim((string) $name);

            if ($name === '' || in_array($name, $names, true)) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @deprecated 使用 configuredDriverNames()；保留别名以免旧调用方过滤实现。
     *
     * @return list<string>
     */
    public function availableDriverNames(): array
    {
        return $this->configuredDriverNames();
    }

    /** 驱动名是否出现在平台 available_drivers 配置中（不论是否已实现）。 */
    public function isDriverConfigured(string $name): bool
    {
        return in_array(trim($name), $this->configuredDriverNames(), true);
    }

    /** 代码是否已注册该驱动实现。 */
    public function isDriverImplemented(string $name): bool
    {
        try {
            $this->driver($name);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * 运行时可用：在平台配置中且代码已实现。
     * 商户已选未实现名时门闩为 false，调用方应静默跳过。
     */
    public function isDriverAvailable(string $name): bool
    {
        $name = trim($name);

        return $this->isDriverConfigured($name) && $this->isDriverImplemented($name);
    }

    /** 平台是否开放物流跟踪（配置枚举非空，可仅含未实现预填名）。 */
    public function hasAvailableDrivers(): bool
    {
        return $this->configuredDriverNames() !== [];
    }

    /**
     * 商户设置页下拉：配置全量；已知实现用可读标签，其余回退原始 name。
     *
     * @return array<string, string>
     */
    public function availableDriverOptions(): array
    {
        $labels = [
            SeventeenTrackDriver::NAME => '17TRACK',
        ];

        $options = [];

        foreach ($this->configuredDriverNames() as $name) {
            $options[$name] = $labels[$name] ?? $name;
        }

        return $options;
    }
}
