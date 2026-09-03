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
    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
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
