<?php

namespace App\Filament\Resources\SystemConfigResource\Pages;

use App\Filament\Resources\SystemConfigResource;
use App\Models\SystemConfig;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;

class EditSystemConfig extends EditRecord
{
    protected static string $resource = SystemConfigResource::class;

    /**
     * 表单里没有直接编辑 config_value 的字段，而是按 value_type 分流成
     * value_string / value_number / value_json / value_boolean / value_image
     * 五个互斥字段（见 SystemConfigResource::form()）。填充表单时，把真正的
     * config_value 塞进当前类型对应的那一个。
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
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

        $data['config_value'] = match ($type) {
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

        return $data;
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
