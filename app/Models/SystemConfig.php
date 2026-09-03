<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemConfig extends Model
{
    public const TYPE_STRING = 'string';

    public const TYPE_NUMBER = 'number';

    public const TYPE_JSON = 'json';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_IMAGE = 'image';

    /**
     * 后台编辑表单据此决定渲染哪个输入控件（见 SystemConfigResource::form()）。
     */
    public const VALUE_TYPES = [
        self::TYPE_STRING,
        self::TYPE_NUMBER,
        self::TYPE_JSON,
        self::TYPE_BOOLEAN,
        self::TYPE_IMAGE,
    ];

    protected $fillable = [
        'config_key',
        'config_value',
        'value_type',
        'group',
        'description',
    ];

    protected const CACHE_TTL_SECONDS = 3600; // 1 小时，与 8.1 节缓存策略一致

    protected const CACHE_PREFIX = 'system_config:';

    /**
     * 带缓存读取单个配置值。返回原始字符串（调用方自行按需 json_decode 等）。
     * 找不到时返回 $default。
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            static::CACHE_PREFIX.$key,
            static::CACHE_TTL_SECONDS,
            function () use ($key, $default) {
                $config = static::query()->where('config_key', $key)->first();

                return $config?->config_value ?? $default;
            }
        );
    }

    /**
     * 写入配置值并清除对应缓存（不能只 Cache::forget 而不更新 DB，
     * 也不能只更新 DB 不清缓存——否则下次读取仍会命中旧缓存直到 TTL 过期）。
     */
    public static function set(string $key, mixed $value, ?string $group = null, ?string $description = null): void
    {
        static::query()->updateOrInsert(
            ['config_key' => $key],
            array_filter([
                'config_value' => is_string($value) ? $value : json_encode($value),
                'group' => $group,
                'description' => $description,
            ], fn ($v) => $v !== null)
        );

        Cache::forget(static::CACHE_PREFIX.$key);
    }

    /**
     * 便捷方法：读取 JSON 数组类型的配置（如 exchange.supported_currencies）。
     */
    public static function getArray(string $key, array $default = []): array
    {
        $raw = static::get($key);

        if (! $raw) {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * 便捷方法：读取布尔类型配置（DB 里存的是 'true'/'false' 字符串）。
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $raw = static::get($key);

        if ($raw === null) {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
