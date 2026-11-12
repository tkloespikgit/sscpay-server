<?php

namespace App\Filament\Support;

use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Support\CryptoSettings;
use App\Support\OrderStatusColor;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use stdClass;

class SystemConfigForm
{
    public static function fill(array $data, SystemConfig $record): array
    {
        if ($record->config_key === OrderStatusColor::CONFIG_KEY || CryptoSettings::isObjectKey($record->config_key)) {
            $raw = $data['config_value'] ?? '{}';
            $decoded = json_decode($raw);
            $data['value_json_object'] = $decoded instanceof stdClass
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : $raw;

            return $data;
        }

        $type = $record->value_type;

        $data['value_'.$type] = match ($type) {
            SystemConfig::TYPE_BOOLEAN => filter_var($data['config_value'] ?? false, FILTER_VALIDATE_BOOLEAN),
            SystemConfig::TYPE_JSON => json_decode($data['config_value'] ?? '', true) ?? [],
            default => $data['config_value'] ?? null,
        };

        return $data;
    }

    /**
     * JSON 列表中的数字文本恢复为数值，只写入当前配置的值。
     *
     * @return array{config_value: string}
     */
    public static function save(array $data, SystemConfig $record, string $statePath = 'data'): array
    {
        if (CryptoSettings::isObjectKey($record->config_key)) {
            return ['config_value' => CryptoSettings::validatedObject($data['value_json_object'] ?? null, $statePath)];
        }
        $type = $record->value_type;
        $value = $data['value_'.$type] ?? null;

        $numberRules = static::numberRules($record);

        if ($numberRules !== []) {
            $field = $statePath.'.value_number';
            $validationData = [];
            Arr::set($validationData, $field, $value);

            Validator::make($validationData, [
                $field => ['required', ...$numberRules],
            ])->validate();
        }

        return [
            'config_value' => $record->config_key === OrderStatusColor::CONFIG_KEY
                ? static::validatedOrderStatusColors($data['value_json_object'] ?? null, $statePath)
                : match ($type) {
                    SystemConfig::TYPE_BOOLEAN => $value ? 'true' : 'false',
                    SystemConfig::TYPE_JSON => json_encode(array_map(
                        fn ($item) => is_numeric($item) ? $item + 0 : $item,
                        (array) $value
                    )),
                    default => (string) $value,
                },
        ];
    }

    /**
     * @return list<string>
     */
    public static function numberRules(?SystemConfig $record): array
    {
        if (str_starts_with($record?->config_key ?? '', 'crypto.')) {
            return CryptoSettings::numberRules($record->config_key);
        }

        return match ($record?->config_key) {
            PaymentMethod::MAX_FAILED_TIMES_CONFIG_KEY => ['integer', 'min:1', 'max:4294967295'],
            'logistics_tracking.stale_days', 'logistics_tracking.no_delivery_days' => ['integer', 'min:1', 'max:3650'],
            default => [],
        };
    }

    protected static function validatedOrderStatusColors(mixed $value, string $statePath): string
    {
        $field = $statePath.'.value_json_object';
        $validationData = [];
        Arr::set($validationData, $field, $value);

        Validator::make($validationData, [
            $field => [
                'bail',
                'required',
                'string',
                'json',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $colors = json_decode($value);

                    if (! $colors instanceof stdClass) {
                        $fail(__('admin.system_config.order_status_colors.invalid_object'));

                        return;
                    }

                    foreach (get_object_vars($colors) as $status => $color) {
                        if (! array_key_exists($status, OrderStatusColor::DEFAULT_COLORS)) {
                            $fail(__('admin.system_config.order_status_colors.unknown_status', ['status' => $status]));

                            return;
                        }

                        if (! is_string($color) || ! in_array($color, OrderStatusColor::ALLOWED_COLORS, true)) {
                            $fail(__('admin.system_config.order_status_colors.invalid_color', [
                                'status' => $status,
                                'colors' => implode(', ', OrderStatusColor::ALLOWED_COLORS),
                            ]));

                            return;
                        }
                    }
                },
            ],
        ], [
            $field.'.json' => __('admin.system_config.order_status_colors.invalid_object'),
        ])->validate();

        return json_encode(json_decode($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
