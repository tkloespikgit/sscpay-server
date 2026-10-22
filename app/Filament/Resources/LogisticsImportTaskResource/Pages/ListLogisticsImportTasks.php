<?php

namespace App\Filament\Resources\LogisticsImportTaskResource\Pages;

use App\Filament\Resources\LogisticsImportTaskResource;
use Filament\Resources\Pages\ListRecords;

/**
 * 上传记录列表：只读，页头没有任何动作。
 *
 * 「重新解析」「重试失败行」属于二期，入口不在这里预先留空按钮——按业务确认，
 * 本期只做失败行导出（在详情页）。
 */
class ListLogisticsImportTasks extends ListRecords
{
    protected static string $resource = LogisticsImportTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
