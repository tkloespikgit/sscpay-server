<?php

namespace App\Filament\Resources;

use App\Filament\Imports\ReplaceKeywordImporter;
use App\Filament\Resources\ReplaceKeywordResource\Pages;
use App\Models\Merchant;
use App\Models\ReplaceKeyword;
use App\Support\Permissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 商户关键词替换表（COPY 商品匹配模式配套）：把订单商品名里命中的关键词
 * 忽略大小写替换成配置的替换词，见 App\Models\ReplaceKeyword::applyReplacements()。
 */
class ReplaceKeywordResource extends Resource
{
    protected static ?string $model = ReplaceKeyword::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.merchant_settings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.replace_keyword.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.replace_keyword.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $isViewerSuperAdmin = (bool) $viewer?->is_super_admin;
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();
        $canPickMerchant = $isViewerSuperAdmin || $isViewerMerchantManager;

        return $schema->components([
            Select::make('merchant_id')
                ->label(__('admin.replace_keyword.fields.merchant'))
                ->options(function () use ($isViewerMerchantManager, $viewer) {
                    if ($isViewerMerchantManager) {
                        return $viewer->ownedMerchants()->where('status', true)->pluck('name', 'id');
                    }

                    return Merchant::query()->where('status', true)->pluck('name', 'id');
                })
                ->required()
                ->searchable()
                ->disabled(! $canPickMerchant)
                ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                ->dehydrated()
                ->columnSpanFull(),
            TextInput::make('keyword')
                ->label(__('admin.replace_keyword.fields.keyword'))
                ->required()
                ->maxLength(255),
            TextInput::make('replacement')
                ->label(__('admin.replace_keyword.fields.replacement'))
                ->helperText(__('admin.replace_keyword.help.replacement'))
                ->maxLength(255),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $columns = [];

        if ((bool) auth()->user()?->isPlatformStaff()) {
            $columns[] = TextColumn::make('merchant.name')->label(__('admin.replace_keyword.fields.merchant'))->searchable()->sortable();
        }

        return $table
            ->columns(array_merge($columns, [
                TextColumn::make('keyword')->label(__('admin.replace_keyword.fields.keyword'))->searchable(),
                TextColumn::make('replacement')->label(__('admin.replace_keyword.fields.replacement'))->searchable(),
                TextColumn::make('created_at')->label(__('admin.replace_keyword.fields.created_at'))->dateTime()->sortable(),
            ]))
            ->headerActions([
                ImportAction::make()->importer(ReplaceKeywordImporter::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReplaceKeywords::route('/'),
            'create' => Pages\CreateReplaceKeyword::route('/create'),
            'edit' => Pages\EditReplaceKeyword::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::REPLACE_KEYWORDS_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }
}
