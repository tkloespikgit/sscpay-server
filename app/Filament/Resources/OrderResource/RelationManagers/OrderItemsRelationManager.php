<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Models\Order;
use App\Models\OrderItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 只读展示：订单商品明细在下单时就已经写死（3.8 节校验约束：
 * subtotal 必须等于所有明细 total_price 之和），后台不提供编辑，
 * 避免改了明细却不重新校验金额公式，导致订单数据不自洽。
 */
class OrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    /**
     * 标题用 getTitle() 方法覆盖而不是静态属性——PHP 静态属性的默认值
     * 必须是编译期常量，不能在里面调用 __()；不覆盖的话 Filament 会
     * 回退到关联模型的英文复数名。
     */
    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.order_item.model_label_plural');
    }

    public function table(Table $table): Table
    {
        /** @var Order $order */
        $order = $this->getOwnerRecord();

        // 明细行与汇总行统一按订单原始币种展示（unit_price / total_price
        // 存的就是原币种金额，USD 折算在 converted_unit_price 里）。
        $currency = strtoupper((string) $order->currency);
        $fmt = fn ($value) => number_format((float) $value, 2).' '.$currency;

        // 表格底部追加订单级金额汇总：商品小计 / 运费 / 折扣 / 税金 / 应付总额，
        // 与主表校验公式一致：amount = subtotal + shipping_fee - discount + tax。
        $summary = [
            __('admin.order_item.summary.subtotal') => $fmt($order->subtotal),
            __('admin.order_item.summary.shipping_fee') => $fmt($order->shipping_fee),
            __('admin.order_item.summary.discount') => '-'.$fmt($order->discount),
            __('admin.order_item.summary.tax') => $fmt($order->tax),
            __('admin.order_item.summary.amount') => $fmt($order->amount),
        ];

        return $table
            ->recordTitleAttribute('product_name')
            ->columns([
                TextColumn::make('product_sku')->label(__('admin.order_item.fields.sku')),
                TextColumn::make('product_id')->label(__('admin.order_item.fields.product_id')),
                TextColumn::make('product_name')->label(__('admin.order_item.fields.product_name')),
                TextColumn::make('product_url')
                    ->label(__('admin.order_item.fields.product_url'))
                    ->limit(30)
                    ->url(fn (OrderItem $record): ?string => $record->product_url ?: null)
                    ->openUrlInNewTab(),
                TextColumn::make('unit_price')->label(__('admin.order_item.fields.unit_price'))->money(strtolower($currency)),
                TextColumn::make('quantity')->label(__('admin.order_item.fields.quantity')),
                TextColumn::make('total_price')->label(__('admin.order_item.fields.total_price'))->money(strtolower($currency)),
            ])
            // 必须传 Blade 视图而非 HtmlString：表格模板会在 <tfoot> 里对
            // contentFooter 调用 ->with() 注入 columns/records，HtmlString 没有该方法。
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
