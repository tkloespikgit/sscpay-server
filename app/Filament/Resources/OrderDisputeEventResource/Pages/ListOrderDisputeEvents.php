<?php

namespace App\Filament\Resources\OrderDisputeEventResource\Pages;

use App\Filament\Resources\OrderDisputeEventResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderDisputeEvents extends ListRecords
{
    protected static string $resource = OrderDisputeEventResource::class;

    protected function getHeaderActions(): array
    {
        // 开立只能从订单详情页发起（前置条件校验 + 冻结资金），这里不放建表单入口。
        return [];
    }
}
