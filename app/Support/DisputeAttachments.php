<?php

namespace App\Support;

use App\Http\Controllers\DisputeAttachmentController;
use App\Models\OrderDisputeEvent;
use App\Models\OrderDisputeEventReply;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 争议审核事件（含回复线程）的图片凭证。图片存在私有 OSS 盘上，
 * 后台需要两种访问方式：
 *
 *   - 预览（灯箱看大图）：直连 OSS 签名 URL，不经过本站，省带宽；
 *   - 下载：走本站 dispute-attachments.show 路由带 download=1，由
 *     DisputeAttachmentController 加 Content-Disposition: attachment。
 *     不直接用 OSS 的 response-content-disposition 参数，是因为那个
 *     写法依赖当前 OSS 驱动的实现细节，换盘（比如本地开发把 oss 指到
 *     local）就失效了。
 *
 * @see DisputeAttachmentController
 */
class DisputeAttachments
{
    public const DISK = 'oss';

    public const TYPE_EVENT = 'event';

    public const TYPE_REPLY = 'reply';

    /** 预览签名 URL 的有效期，与 Filament 自己的默认值保持一致。 */
    private const PREVIEW_TTL_MINUTES = 30;

    /** @return class-string<OrderDisputeEvent|OrderDisputeEventReply> */
    public static function modelFor(string $type): string
    {
        return $type === self::TYPE_EVENT ? OrderDisputeEvent::class : OrderDisputeEventReply::class;
    }

    /**
     * 一条记录的全部图片，供灯箱视图渲染。
     *
     * @return array<int, array{url: string, download: string, name: string}>
     */
    public static function for(OrderDisputeEvent|OrderDisputeEventReply $record): array
    {
        $type = $record instanceof OrderDisputeEvent ? self::TYPE_EVENT : self::TYPE_REPLY;

        $items = [];

        foreach (array_values((array) $record->images) as $index => $path) {
            $items[] = [
                'url' => self::previewUrl((string) $path, $type, $record->getKey(), $index),
                'download' => self::routeUrl($type, $record->getKey(), $index, download: true),
                'name' => basename((string) $path),
            ];
        }

        return $items;
    }

    /**
     * 签名 URL 拿不到时（驱动不支持签名、或 oss 盘被指到了本地盘）
     * 退回本站的内联路由，保证图片始终能显示出来。
     */
    private static function previewUrl(string $path, string $type, int|string $key, int $index): string
    {
        try {
            return Storage::disk(self::DISK)->temporaryUrl($path, now()->addMinutes(self::PREVIEW_TTL_MINUTES));
        } catch (Throwable) {
            return self::routeUrl($type, $key, $index, download: false);
        }
    }

    private static function routeUrl(string $type, int|string $key, int $index, bool $download): string
    {
        return route('dispute-attachments.show', array_filter([
            'type' => $type,
            'record' => $key,
            'index' => $index,
            'download' => $download ? 1 : null,
        ], fn ($value) => $value !== null));
    }
}
