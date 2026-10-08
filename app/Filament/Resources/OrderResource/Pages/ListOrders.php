<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Jobs\ProcessLogisticsImportJob;
use App\Models\LogisticsImportTask;
use App\Models\User;
use App\Services\LogisticsImportService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * 导出使用列表当前筛选和搜索条件，范围限定为登录账号可管理的商户。
 * 上传物流仍绑定登录用户所属的单个商户。
 */
class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportLogisticsTemplate')
                ->label(__('admin.order.actions.export_logistics_template'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => (bool) auth()->user()?->can(Permissions::LOGISTICS_IMPORTS_MANAGE))
                ->action(function ($livewire, LogisticsImportService $service) {
                    $user = auth()->user();

                    abort_unless($user instanceof User && $user->can(Permissions::LOGISTICS_IMPORTS_MANAGE), 403);

                    // getFilteredTableQuery() 来自 Filament\Tables\Concerns\HasRecords，
                    // 那是个 trait——不能用 instanceof 判断（PHP 里对 trait 用 instanceof
                    // 永远返回 false），只能用 method_exists。
                    $query = method_exists($livewire, 'getFilteredTableQuery')
                        ? $livewire->getFilteredTableQuery()
                        : null;

                    if (! $query instanceof Builder) {
                        Notification::make()
                            ->title(__('admin.order.actions.export_failed'))
                            ->danger()
                            ->send();

                        return null;
                    }

                    $manageableIds = $user->manageableMerchantIds();

                    if ($manageableIds !== null) {
                        $query->whereIn('orders.merchant_id', $manageableIds);
                    }

                    $csv = $service->generateTemplate(null, $query);

                    return response()->streamDownload(
                        fn () => print ($csv),
                        'logistics_template_'.now()->format('Ymd_His').'.csv'
                    );
                }),

            Action::make('uploadLogistics')
                ->label(__('admin.order.actions.upload_logistics'))
                ->icon('heroicon-o-arrow-up-tray')
                // visible() 对超级管理员返回 false，这不只是把按钮藏起来：Filament 的
                // mountAction() 会先判 isDisabled()，而 isHidden() 为真时 isDisabled() 也为真，
                // 因此手工构造 Livewire 请求同样调不动这个动作。
                ->visible(fn () => self::canUploadLogistics())
                ->modalDescription(fn () => __('admin.order.actions.upload_logistics_hint'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('admin.order.actions.csv_file'))
                        ->acceptedFileTypes(['text/csv', 'text/plain'])
                        ->required()
                        ->disk('local')
                        ->directory('logistics-imports-tmp'),
                ])
                ->action(function (array $data) {
                    // 兜底再判一次：正常情况下 visible() 已经把超管挡在 mountAction 之前，
                    // 这里防的是"非超管但 merchant_id 为空"的用户——那种账号一旦放过去，
                    // logistics_import_tasks.merchant_id 会落成 NULL 直接违反非空约束。
                    $merchantId = self::uploadMerchantId();

                    if ($merchantId === null) {
                        // 用户已经选了文件，这里顺手清掉临时文件，避免 storage 里堆孤儿文件
                        if (filled($data['file'] ?? null)) {
                            Storage::disk('local')->delete($data['file']);
                        }

                        Notification::make()
                            ->title(__('admin.order.actions.upload_forbidden'))
                            ->danger()
                            ->send();

                        return null;
                    }

                    $localTmpPath = $data['file'];

                    $ossPath = "merchants/{$merchantId}/logistics_imports/".now()->format('Y-m-d')."/{$localTmpPath}";
                    Storage::disk('oss')->put($ossPath, Storage::disk('local')->get($localTmpPath));
                    Storage::disk('local')->delete($localTmpPath);

                    // withoutGlobalScopes()：显式绕开 MerchantScope，保证 merchant_id 一定按上面
                    // 解析出的 $merchantId 落库，不受登录态差异影响。
                    $task = LogisticsImportTask::query()->withoutGlobalScopes()->create([
                        'merchant_id' => $merchantId,
                        'operator_id' => auth()->id(),
                        'file_name' => basename($localTmpPath),
                        'oss_path' => $ossPath,
                        'status' => 'pending',
                    ]);

                    ProcessLogisticsImportJob::dispatch($task->id);

                    Notification::make()
                        ->title(__('admin.order.actions.upload_success'))
                        ->success()
                        ->send();
                }),

            Action::make('createManualOrder')
                ->label(__('admin.order.actions.create_manual_order'))
                ->icon('heroicon-o-plus')
                // 显隐统一走 CreateManualOrder::canAccess()：既覆盖原有的
                // orders.create_manual 权限判断，也受页面里的功能总开关控制。
                // 手工建单被临时关闭时，这个按钮会自动隐藏，无需在此重复维护开关。
                ->visible(fn () => CreateManualOrder::canAccess())
                ->url(fn () => OrderResource::getUrl('create-manual')),
        ];
    }

    /**
     * 「上传物流单号」是否可用：只有绑定了商户的普通用户才行，超级管理员即便在筛选里
     * 选了商户也不允许代传——上传会改写订单发货状态并触发回传给商户站点，属于商户
     * 自己的业务动作，平台侧只保留只读的导出模板能力。
     */
    private static function canUploadLogistics(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ! $user->is_super_admin
            && filled($user->merchant_id)
            && $user->can(Permissions::LOGISTICS_IMPORTS_MANAGE);
    }

    /** 上传动作绑定的商户 ID：只认登录态，超管或无商户账号一律 null。 */
    private static function uploadMerchantId(): ?int
    {
        return self::canUploadLogistics() ? (int) auth()->user()->merchant_id : null;
    }
}
