<?php

namespace App\Filament\Imports;

use App\Models\Merchant;
use App\Models\ReplaceKeyword;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;

/**
 * 关键词替换表的 CSV 批量导入：表头 keyword,replacement。
 * 按 (merchant_id, keyword) upsert，避免重复导入产生重复行。
 */
class ReplaceKeywordImporter extends Importer
{
    protected static ?string $model = ReplaceKeyword::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('keyword')
                ->label(__('admin.replace_keyword.fields.keyword'))
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255'])
                ->example('Mechanical'),
            ImportColumn::make('replacement')
                ->label(__('admin.replace_keyword.fields.replacement'))
                ->helperText(__('admin.replace_keyword.help.replacement'))
                ->requiredMapping()
                ->rules(['nullable', 'string', 'max:255'])
                // 空单元格会被 ImportColumn::castStateItem() 转成 null，这里显式转回
                // 空字符串：既避免写入 NOT NULL 列失败，也贴合"留空=删除关键词"的语义。
                ->castStateUsing(fn (?string $state) => $state ?? '')
                ->example('MAD'),
        ];
    }

    /**
     * 平台侧账号（超管/商户级管理员）需要选择导入到哪个商户；
     * 普通商户用户锁定成自己所在商户，不展示选择项。
     */
    public static function getOptionsFormComponents(): array
    {
        $viewer = auth()->user();
        $isViewerSuperAdmin = (bool) $viewer?->is_super_admin;
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();
        $canPickMerchant = $isViewerSuperAdmin || $isViewerMerchantManager;

        return [
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
                ->dehydrated(),
        ];
    }

    /**
     * 按 (merchant_id, keyword) upsert：同一个关键词重复导入时更新替换值，
     * 而不是按主键 find()（默认行为）或每次都新建一行。
     */
    public function resolveRecord(): ?ReplaceKeyword
    {
        $merchantId = (int) ($this->options['merchant_id'] ?? auth()->user()?->merchant_id);

        return ReplaceKeyword::query()
            ->forMerchant($merchantId)
            ->where('keyword', $this->data['keyword'] ?? null)
            ->first() ?? new ReplaceKeyword(['merchant_id' => $merchantId]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = __('admin.replace_keyword.import.completed_body', ['count' => number_format($import->successful_rows)]);

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.__('admin.replace_keyword.import.completed_body_failed', ['count' => number_format($failedRowsCount)]);
        }

        return $body;
    }
}
