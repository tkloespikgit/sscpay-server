<?php

namespace App\Models;

use App\Support\CryptoRuntimeSettings;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SystemConfig extends Model
{
    public const TYPE_STRING = 'string';

    public const TYPE_NUMBER = 'number';

    public const TYPE_JSON = 'json';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_IMAGE = 'image';

    public const TYPE_SECRET = 'secret';

    /**
     * 后台编辑表单据此决定渲染哪个输入控件（见 SystemConfigResource::form()）。
     */
    public const VALUE_TYPES = [
        self::TYPE_STRING,
        self::TYPE_NUMBER,
        self::TYPE_JSON,
        self::TYPE_BOOLEAN,
        self::TYPE_IMAGE,
        self::TYPE_SECRET,
    ];

    protected $fillable = [
        'config_key',
        'config_value',
        'value_type',
        'group',
        'description',
        'secret_value',
    ];

    protected $hidden = ['secret_value'];

    protected function casts(): array
    {
        return ['secret_value' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            if (CryptoRuntimeSettings::isSecret($record->config_key) || $record->value_type === self::TYPE_SECRET) {
                $record->value_type = self::TYPE_SECRET;
                if ($record->isDirty('config_value') && filled($record->config_value)) {
                    $record->secret_value = $record->config_value;
                }
                $record->config_value = null;
            }
        });
        $invalidate = function (self $record): void {
            $key = $record->config_key;
            self::forget($key);
            DB::afterCommit(fn () => self::forget($key));
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public static function forget(string $key): void
    {
        Cache::forget(static::CACHE_PREFIX.$key);
        Cache::forget('system_config_secret:'.$key);
    }

    /** Only ciphertext is shared through cache; plaintext exists only for the requesting operation. */
    public static function secret(string $key, ?string $default = null): ?string
    {
        $read = fn (): ?string => static::query()->where('config_key', $key)
            ->where('value_type', self::TYPE_SECRET)->toBase()->value('secret_value');
        $ciphertext = DB::transactionLevel() > 0 ? $read() : Cache::remember('system_config_secret:'.$key, static::CACHE_TTL_SECONDS, $read);
        if ($ciphertext === null || $ciphertext === '') {
            return $default;
        }
        try {
            return Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            throw new DomainException('Encrypted system setting cannot be read.');
        }
    }

    protected const CACHE_TTL_SECONDS = 3600; // 1 小时，与 8.1 节缓存策略一致

    protected const CACHE_PREFIX = 'system_config:';

    /**
     * 带缓存读取单个配置值。返回原始字符串（调用方自行按需 json_decode 等）。
     * 找不到时返回 $default。
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (CryptoRuntimeSettings::isSecret($key)) {
            return $default;
        }
        if (DB::transactionLevel() > 0) {
            return static::query()->where('config_key', $key)->value('config_value') ?? $default;
        }

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
    public static function set(string $key, #[\SensitiveParameter] mixed $value, ?string $group = null, ?string $description = null): void
    {
        $secret = CryptoRuntimeSettings::isSecret($key);
        $data = array_filter([
            'group' => $group,
            'description' => $description,
        ], fn ($v) => $v !== null);
        $data[$secret ? 'secret_value' : 'config_value'] = $value === null ? null : (is_string($value) ? $value : json_encode($value));
        if ($secret) {
            $data['value_type'] = self::TYPE_SECRET;
            $data['config_value'] = null;
        }
        static::query()->updateOrCreate(['config_key' => $key], $data);
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
