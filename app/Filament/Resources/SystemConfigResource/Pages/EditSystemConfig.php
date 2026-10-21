<?php

namespace App\Filament\Resources\SystemConfigResource\Pages;

use App\Filament\Resources\SystemConfigResource;
use App\Models\SystemConfig;
use App\Support\OrderStatusColor;
use Closure;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use stdClass;

class EditSystemConfig extends EditRecord
{
    protected static string $resource = SystemConfigResource::class;

    /**
     * 表单里没有直接编辑 config_value 的字段，而是按 value_type 分流成
     * value_string / value_number / value_json / value_boolean / value_image
     * 等互斥字段（见 SystemConfigResource::form()）。订单状态颜色单独用
     * value_json_object 编辑 JSON 对象，其余 JSON 配置保留列表编辑方式。
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record->config_key === OrderStatusColor::CONFIG_KEY) {
            $raw = $data['config_value'] ?? '{}';
            $decoded = json_decode($raw);
            $data['value_json_object'] = $decoded instanceof stdClass
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : $raw;

            return $data;
        }

        $type = $data['value_type'] ?? SystemConfig::TYPE_STRING;

        $data['value_'.$type] = match ($type) {
            SystemConfig::TYPE_BOOLEAN => filter_var($data['config_value'] ?? false, FILTER_VALIDATE_BOOLEAN),
            SystemConfig::TYPE_JSON => json_decode($data['config_value'] ?? '', true) ?? [],
            default => $data['config_value'] ?? null,
        };

        return $data;
    }

    /**
     * 保存前反过来：把当前类型对应的 value_* 字段收拢回 config_value，
     * 其余没用到的 value_* 字段丢弃（它们不是 system_configs 表的列）。
     *
     * JSON 类型的列表项在 Repeater 里都是文本输入（TextInput 只产生字符串），
     * 像 notify.retry_intervals_seconds 这种数字列表，存回去前要把纯数字的项
     * 转回 int/float，不然 [30, 300] 会变成 ["30", "300"]。
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $type = $this->record->value_type;
        $value = $data['value_'.$type] ?? null;

        $data['config_value'] = $this->record->config_key === OrderStatusColor::CONFIG_KEY
            ? $this->validatedOrderStatusColors($data['value_json_object'] ?? null)
            : match ($type) {
                SystemConfig::TYPE_BOOLEAN => $value ? 'true' : 'false',
                SystemConfig::TYPE_JSON => json_encode(array_map(
                    fn ($item) => is_numeric($item) ? $item + 0 : $item,
                    (array) $value
                )),
                default => (string) $value,
            };

        foreach (SystemConfig::VALUE_TYPES as $valueType) {
            unset($data['value_'.$valueType]);
        }
        unset($data['value_json_object']);

        return $data;
    }

    protected function validatedOrderStatusColors(mixed $value): string
    {
        Validator::make([
            'data' => ['value_json_object' => $value],
        ], [
            'data.value_json_object' => [
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
            'data.value_json_object.json' => __('admin.system_config.order_status_colors.invalid_object'),
        ])->validate();

        return json_encode(json_decode($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * 保存后必须清缓存，否则要等 Cache TTL（1 小时）过期才生效——
     * 这是 8.1 节"更新数据时务必清除对应缓存"铁律在后台这一端的落地。
     */
    protected function afterSave(): void
    {
        Cache::forget('system_config:'.$this->record->config_key);
    }
}
