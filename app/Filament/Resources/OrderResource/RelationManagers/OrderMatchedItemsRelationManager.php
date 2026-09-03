<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Models\Order;
use App\Models\OrderMatchedItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 只读展示：下单时未传商品明细的订单，系统会按订单商品金额从支付方式
 * 绑定的站点商品变体中自动匹配一份明细（存 order_matched_items，结构同
 * order_items）。这里与商户真实下单明细（商品明细）并列展示以便核对，
 * 后台不提供编辑。
 */
class OrderMatchedItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'matchedItems';

    /**
     * 标题用 getTitle() 方法覆盖而不是静态属性——PHP 静态属性的默认值
     * 必须是编译期常量，不能在里面调用 __()；不覆盖的话 Filament 会
     * 回退到关联模型的英文复数名。
     */
    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.order_matched_item.model_label_plural');
    }

    /**
     * 没有匹配明细的订单（下单时传了商品明细的）不展示该表格，
     * 避免详情页出现一堆空的关联面板。
     */
    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->matchedItems()->exists();
    }

    public function table(Table $table): Table
    {
        /** @var Order $order */
        $order = $this->getOwnerRecord();

        // 与商品明细一致：明细行按订单原始币种展示（unit_price / total_price
        // 存的就是订单币种），converted_unit_price 是折算前的 USD 原价。
        $currency = strtoupper((string) $order->currency);

        // 底部汇总：匹配明细小计（各行 total_price 之和）与匹配溢出折扣
        //（匹配凑不满的部分，订单原币种，随 /pay 以 discount_fee 发给 WordPress）。
        $summary = [
            __('admin.order_matched_item.summary.subtotal') => number_format((float) $order->matchedItems()->sum('total_price'), 2).' '.$currency,
            __('admin.order_matched_item.summary.matched_discount') => '-'.number_format((float) $order->matched_discount, 2).' '.$currency,
        ];

        return $table
            ->recordTitleAttribute('product_name')
            ->columns([
                TextColumn::make('product_sku')->label(__('admin.order_matched_item.fields.sku')),
                TextColumn::make('product_id')->label(__('admin.order_matched_item.fields.product_id')),
                TextColumn::make('product_name')->label(__('admin.order_matched_item.fields.product_name')),
                TextColumn::make('product_url')
                    ->label(__('admin.order_matched_item.fields.product_url'))
                    ->limit(30)
                    ->url(fn (OrderMatchedItem $record): ?string => $record->product_url ?: null)
                    ->openUrlInNewTab(),
                TextColumn::make('unit_price')->label(__('admin.order_matched_item.fields.unit_price'))->money(strtolower($currency)),
                TextColumn::make('converted_unit_price')->label(__('admin.order_matched_item.fields.converted_unit_price'))->money('usd'),
                TextColumn::make('quantity')->label(__('admin.order_matched_item.fields.quantity')),
                TextColumn::make('total_price')->label(__('admin.order_matched_item.fields.total_price'))->money(strtolower($currency)),
            ])
            // 复用商品明细的汇总行视图：必须传 Blade 视图而非 HtmlString，
            // 表格模板会在 <tfoot> 里对 contentFooter 调用 ->with() 注入 columns/records。
            ->contentFooter(view('filament.tables.order-items-summary', [
                'summary' => $summary,
            ]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
