<?php

namespace App\Services;

use App\Models\SystemConfig;
use App\Support\TelegramNotificationKind;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TelegramNotificationSettings
{
    public const CONFIG_KEY = 'telegram.notification_settings';

    /** @return array<string, bool|int|string> */
    public function defaults(TelegramNotificationKind $kind): array
    {
        return [
            'enabled' => true, 'template' => '',
            ...$kind->strategyDefaults(),
        ];
    }

    /** @return array<string, bool|int|string> */
    public function get(TelegramNotificationKind $kind): array
    {
        $defaults = $this->defaults($kind);
        $stored = SystemConfig::getArray(self::CONFIG_KEY)[$kind->value] ?? null;

        if (! is_array($stored)) {
            $legacy = SystemConfig::getArray(TelegramNotificationRouter::CONFIG_KEY)[$kind->value] ?? [];
            $defaults['enabled'] = ! (is_array($legacy) && in_array('none', $legacy, true));

            return $defaults;
        }

        if (array_key_exists('message_template', $stored)) {
            $stored['template'] = $stored['message_template'];
        } else {
            $body = is_string($stored['template'] ?? null) ? $stored['template'] : '';
            $orderTemplate = is_string($stored['order_template'] ?? null) ? $stored['order_template'] : '';
            $stored['template'] = filled($body) || filled($orderTemplate)
                ? TelegramMessageTemplate::composeLegacyTemplate($kind, $body, $orderTemplate) : '';
        }

        foreach ($defaults as $key => $default) {
            $value = $stored[$key] ?? $default;
            $rules = $this->rules($kind)[$key];

            if (Validator::make([$key => $value], [$key => $rules])->passes()) {
                $defaults[$key] = is_int($default) ? (int) $value : (is_bool($default) ? (bool) $value : (string) $value);
            }
        }

        try {
            TelegramMessageTemplate::validate($kind, $defaults['template']);
        } catch (ValidationException) {
            $defaults['template'] = '';
        }

        return $defaults;
    }

    /** @param array<string, mixed> $data */
    public function save(TelegramNotificationKind $kind, array $data): void
    {
        $rules = $this->rules($kind);
        $unknown = array_diff(array_keys($data), array_keys($rules));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['enabled' => __('admin.telegram_routes.invalid_setting')]);
        }

        $validated = Validator::make($data, $rules)->validate();
        $template = trim((string) ($validated['template'] ?? ''));
        TelegramMessageTemplate::validate($kind, $template);
        $normalized = [
            'enabled' => (bool) $validated['enabled'],
            'message_template' => $template === TelegramMessageTemplate::defaultTemplate($kind) ? '' : $template,
        ];

        foreach ($kind->strategyDefaults() as $key => $default) {
            $normalized[$key] = is_int($default) ? (int) $validated[$key] : $validated[$key];
        }

        DB::transaction(function () use ($kind, $normalized): void {
            SystemConfig::query()->firstOrCreate(['config_key' => self::CONFIG_KEY], [
                'config_value' => '{}',
                'value_type' => SystemConfig::TYPE_JSON,
                'group' => 'telegram',
                'description' => 'Telegram 通知开关、完整消息和发送参数',
            ]);
            $record = SystemConfig::query()->where('config_key', self::CONFIG_KEY)->lockForUpdate()->firstOrFail();
            $settings = json_decode((string) $record->config_value, true);
            $settings = is_array($settings) ? $settings : [];
            $settings[$kind->value] = $normalized;
            $record->update(['config_value' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        }, 3);

        Cache::forget('system_config:'.self::CONFIG_KEY);
    }

    /** @return array<string, list<string>> */
    private function rules(TelegramNotificationKind $kind): array
    {
        $rules = ['enabled' => ['required', 'boolean'], 'template' => ['nullable', 'string', 'max:'.TelegramMessageTemplate::MAX_TEMPLATE_LENGTH]];

        foreach ($kind->strategyDefaults() as $key => $default) {
            $rules[$key] = match ($key) {
                'repeat_count' => ['required', 'integer', 'min:1', 'max:5'],
                'cooldown_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
                'reminder_hours' => ['required', 'integer', 'min:1', 'max:720'],
                'unshipped_days' => ['required', 'integer', 'min:1', 'max:365'],
                'send_time' => ['required', 'date_format:H:i'],
            };
        }

        return $rules;
    }
}
