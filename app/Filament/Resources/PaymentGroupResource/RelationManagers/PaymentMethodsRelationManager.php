<?php

namespace App\Filament\Resources\PaymentGroupResource\RelationManagers;

use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Services\PaymentGroupMethodService;
use App\Services\PaymentService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

/**
 * 支付组内的支付方式 + 组内进单占比权重（priority，数值越大分配占比越高）。
 * 列表同时展示当天负载快照（成交额 / 笔数 / 负载率 / 候选序），口径见
 * PaymentService::routingLoadSnapshot()；下单时按负载排序后从前三等概率随机。
 * 直接展示双栏面板，加入、移出和权重调整均逐项保存，不使用弹窗。
 */
class PaymentMethodsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentMethods';

    /**
     * @var array<int, array{weight: int, amount: float, count: int, load_ratio: float, rank: int|null, in_top3: bool}>|null
     */
    private ?array $routingLoadSnapshotCache = null;

    public function mount(): void
    {
        $this->refreshManagedGroup();

        parent::mount();
    }

    public function hydrate(): void
    {
        $this->refreshManagedGroup();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof PaymentGroup
            && PaymentGroupMethodService::canManage(auth()->user(), $ownerRecord);
    }

    #[On('payment-group-updated')]
    public function refreshGroup(): void
    {
        $this->refreshManagedGroup();
        $this->resetValidation();
        $this->refreshMethods();
    }

    public function setPaymentMethodMembership(int $paymentMethodId, bool $included, PaymentGroupMethodService $service): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $service->setMembership($actor, $this->getOwnerRecord(), $paymentMethodId, $included);
        $this->resetValidation('weights.'.$paymentMethodId);
        $this->refreshMethods();
    }

    public function savePaymentMethodWeight(int $paymentMethodId, mixed $priority, PaymentGroupMethodService $service): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $service->saveWeight($actor, $this->getOwnerRecord(), $paymentMethodId, $priority);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'weights.'.$paymentMethodId => $exception->errors()['priority'] ?? [$exception->getMessage()],
            ]);
        }
        $this->resetValidation('weights.'.$paymentMethodId);
        $this->refreshMethods();
    }

    private function refreshManagedGroup(): void
    {
        abort_unless(PaymentGroupMethodService::canManage(auth()->user(), $this->getOwnerRecord()), 403);

        $this->ownerRecord = PaymentGroup::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->findOrFail($this->getOwnerRecord()->getKey());
    }

    private function refreshMethods(): void
    {
        $this->routingLoadSnapshotCache = null;
        $this->resetTable();
    }

    /**
     * 标题用 getTitle() 方法覆盖而不是静态属性——PHP 静态属性的默认值
     * 必须是编译期常量，不能在里面调用 __()；不覆盖的话 Filament 会
     * 回退到关联模型的英文复数名。
     */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.payment_group.relation_manager.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('method_name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->forMerchant((int) $this->getOwnerRecord()->merchant_id))
            ->header(function (PaymentGroupMethodService $service): View {
                $methods = $service->methods(auth()->user(), $this->getOwnerRecord());

                return view('filament.resources.payment-group-resource.payment-method-panel', [
                    'columns' => [
                        'available' => $methods->where('is_in_group', false),
                        'included' => $methods->where('is_in_group', true),
                    ],
                ]);
            })
            ->columns([
                TextColumn::make('method_name')->label(__('admin.payment_method.model_label')),
                TextColumn::make('method_code')->label(__('admin.payment_method.columns.code'))->badge(),
                TextColumn::make('pivot.priority')->label(__('admin.payment_group.relation_manager.priority')),
                TextColumn::make('today_paid_amount')
                    ->label(__('admin.payment_group.relation_manager.today_paid_amount'))
                    ->state(fn (PaymentMethod $record): float => $this->snapshotFor($record)['amount'])
                    ->money('usd'),
                TextColumn::make('today_paid_count')
                    ->label(__('admin.payment_group.relation_manager.today_paid_count'))
                    ->state(fn (PaymentMethod $record): int => $this->snapshotFor($record)['count']),
                TextColumn::make('load_ratio')
                    ->label(__('admin.payment_group.relation_manager.load_ratio'))
                    ->state(fn (PaymentMethod $record): float => $this->snapshotFor($record)['load_ratio'])
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2))
                    ->tooltip(__('admin.payment_group.relation_manager.load_ratio_tooltip')),
                TextColumn::make('candidate_rank')
                    ->label(__('admin.payment_group.relation_manager.candidate_rank'))
                    ->badge()
                    ->state(fn (PaymentMethod $record): ?int => $this->snapshotFor($record)['rank'])
                    ->placeholder(__('admin.payment_group.relation_manager.candidate_rank_inactive'))
                    ->formatStateUsing(function (?int $state, PaymentMethod $record): string {
                        if ($state === null) {
                            return __('admin.payment_group.relation_manager.candidate_rank_inactive');
                        }

                        return $this->snapshotFor($record)['in_top3']
                            ? __('admin.payment_group.relation_manager.candidate_rank_top3', ['rank' => $state])
                            : __('admin.payment_group.relation_manager.candidate_rank_value', ['rank' => $state]);
                    })
                    ->color(fn (?int $state, PaymentMethod $record): string => match (true) {
                        $state === null => 'gray',
                        $this->snapshotFor($record)['in_top3'] => 'success',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('pivot_priority', 'desc');
    }

    /**
     * @return array{weight: int, amount: float, count: int, load_ratio: float, rank: int|null, in_top3: bool}
     */
    private function snapshotFor(PaymentMethod $record): array
    {
        $snapshot = $this->routingLoadSnapshot();

        return $snapshot[$record->id] ?? [
            'weight' => max(1, (int) $record->pivot?->priority),
            'amount' => 0.0,
            'count' => 0,
            'load_ratio' => 0.0,
            'rank' => null,
            'in_top3' => false,
        ];
    }

    /**
     * @return array<int, array{weight: int, amount: float, count: int, load_ratio: float, rank: int|null, in_top3: bool}>
     */
    private function routingLoadSnapshot(): array
    {
        if ($this->routingLoadSnapshotCache !== null) {
            return $this->routingLoadSnapshotCache;
        }

        /** @var PaymentGroup $group */
        $group = $this->getOwnerRecord();

        return $this->routingLoadSnapshotCache = app(PaymentService::class)->routingLoadSnapshot($group);
    }
}
