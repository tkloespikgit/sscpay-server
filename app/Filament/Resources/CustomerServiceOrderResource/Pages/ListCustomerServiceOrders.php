<?php

namespace App\Filament\Resources\CustomerServiceOrderResource\Pages;

use App\Filament\Resources\CustomerServiceOrderResource;
use Filament\Resources\Pages\ListRecords;

/**
 * 客服订单查询列表页。
 *
 * 页头不挂任何 Action：完整订单列表有"导出物流模板 / 上传物流单号 / 手工建单"
 * 三个动作，都是写操作或涉及物流资料，不属于客服职责。行操作"查询订单"
 * 定义在 Resource 的 table()->recordActions() 里，与完整订单列表共用同一套逻辑。
 */
class ListCustomerServiceOrders extends ListRecords
{
    protected static string $resource = CustomerServiceOrderResource::class;
}
