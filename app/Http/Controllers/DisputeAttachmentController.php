<?php

namespace App\Http\Controllers;

use App\Models\OrderDisputeEvent;
use App\Models\OrderDisputeEventReply;
use App\Support\DisputeAttachments;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 争议审核事件图片凭证的内联预览 / 下载。
 *
 * 鉴权分两层：
 *   1. order_disputes.view 权限——能看争议事件的人才能看它的附件；
 *   2. 记录本身的 MerchantScope 全局作用域——商户用户查不到别家商户的
 *      事件/回复，findOrFail 直接 404，所以这里不需要再手写商户比对。
 *
 * 路径只认「记录 + 下标」，不接受客户端传对象路径，避免被构造成任意
 * 读取 OSS 上其他文件的入口。
 */
class DisputeAttachmentController extends Controller
{
    public function show(Request $request, string $type, int $record, int $index): StreamedResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::ORDER_DISPUTES_VIEW), 403);

        $model = DisputeAttachments::modelFor($type);

        /** @var OrderDisputeEvent|OrderDisputeEventReply $record */
        $record = $model::query()->findOrFail($record);

        $path = array_values((array) $record->images)[$index] ?? null;

        abort_if($path === null, 404);

        $disk = Storage::disk(DisputeAttachments::DISK);

        abort_unless($disk->exists($path), 404);

        return $request->boolean('download')
            ? $disk->download($path, basename($path))
            : $disk->response($path);
    }
}
