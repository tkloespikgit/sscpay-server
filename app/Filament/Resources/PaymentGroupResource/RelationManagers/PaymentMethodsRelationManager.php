<?php

namespace App\Filament\Resources\PaymentGroupResource\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 支付组内的支付方式 + 组内进单占比权重（priority，数值越大分配占比越高）。
 * 下单时按"当天已成交金额 / 权重"最小者进单，实现多通道均匀分散抗量。
 * 用 AttachAction 的 form 承载 priority 这个 pivot 字段，
 * 编辑已挂载记录的 priority 也走单独的 EditAction（同样操作 pivot）。
 */
class PaymentMethodsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentMethods';

    /**
     * 标题用 getTitle() 方法覆盖而不是静态属性——PHP 静态属性的默认值
     * 必须是编译期常量，不能在里面调用 __()；不覆盖的话 Filament 会
     * 回退到关联模型的英文复数名。
     */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.payment_group.relation_manager.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('priority')->label(__('admin.payment_group.relation_manager.priority'))->integer()->minValue(1)->default(100)->helperText(__('admin.payment_group.relation_manager.priority_help')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('method_name')
            ->columns([
                TextColumn::make('method_name')->label(__('admin.payment_method.model_label')),
                TextColumn::make('method_code')->label(__('admin.payment_method.columns.code'))->badge(),
                TextColumn::make('pivot.priority')->label(__('admin.payment_group.relation_manager.priority')),
            ])
            ->defaultSort('pivot_priority', 'desc')
            ->headerActions([
                AttachAction::make()
                    // 候选支付方式必须限定在"本支付组所属商户可用"的范围内。不加这个的话
                    // AttachAction 的候选集是「当前账号能看到的全部支付方式」减去已挂载项，
                    // 完全不参考支付组属于哪个商户——超管能看到所有商户的，商户级管理员能
                    // 看到名下多个商户的，于是可以把 B 商户的支付方式挂进 A 商户的支付组，
                    // A 的订单就会路由进 B 的通道收款（PaymentService::resolvePaymentMethod()
                    // 只取组内启用的支付方式，不再校验归属），手续费、限额、观察者可见范围
                    // 全部串到 B 去。支付组主表单早就防住了这一点（见 PaymentGroupResource
                    // 里 paymentMethods 字段的注释），关系管理器这条路径必须用同样的规则。
                    // 用 forMerchant() 而不是只比 merchant_id：它会把"分配给该商户使用的
                    // 系统级支付方式"一并算进可用范围，与主表单口径一致。
                    // recordSelectOptionsQuery 同时作用于下拉选项和提交时按 ID 取记录
                    // （见 AttachAction::getRecordSelect() 与 action 闭包），所以伪造
                    // recordId 也绕不过去。
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query->forMerchant(
                        (int) $this->getOwnerRecord()->merchant_id,
                    ))
                    ->form(fn (AttachAction $action) => [
                        $action->getRecordSelect(),
                        TextInput::make('priority')->label(__('admin.payment_group.relation_manager.priority'))->integer()->minValue(1)->default(100),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DetachAction::make(),
            ]);
    }
}
