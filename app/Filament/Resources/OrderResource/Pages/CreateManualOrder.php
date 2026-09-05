<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\SystemConfig;
use App\Services\ManualOrderService;
use App\Support\Permissions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * 手工创建支付订单（4.4 节）。权限：商户管理员、订单管理员（Panel 层面
 * 通过角色权限控制谁能进这个页面，这里不重复判断）。
 *
 * 表单里的金额小计只做前端展示辅助，真正的金额公式校验、汇率快照、
 * 风控锁定支付方式，全部在 ManualOrderService → OrderCreationService
 * 里重新算一遍——绝不信任前端算出来的数字（2.1 节铁律）。
 *
 * 【订单归属商户】这里必须显式选择，不能想当然地用 auth()->user()->merchant_id
 * ——超级管理员的 merchant_id 本来就是 NULL（不属于任何商户），如果直接拿
 * 这个值去查支付组/创建订单会直接报类型错误或者查出一堆空结果。所以加了
 * 一个"订单归属商户"字段：商户用户这里被锁定成自己所在商户（禁用状态，
 * 改不了），超级管理员则可以自由选择要代哪个商户创建订单（常见场景是
 * 客服代商户下单）。
 */
class CreateManualOrder extends Page
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.resources.order-resource.pages.create-manual-order';

    public ?array $data = [];

    /**
     * 手工建单功能总开关。当前按需求临时关闭整个后台手工建单入口
     * （列表页「手工建单」按钮 + 本页面路由访问）。恢复时只需把这里
     * 改回 true，下面的权限校验逻辑（orders.create_manual）原样保留、
     * 无需改动。列表页按钮的显隐也统一走 canAccess()，翻这一个开关即可。
     */
    protected static bool $featureEnabled = false;

    /**
     * 需要 orders.create_manual 权限（默认"商户管理员"和"订单管理员"角色
     * 拥有，见 MerchantRoleProvisioningService）。没有权限直接 403，
     * 而不是让用户看到表单再在提交时才发现自己没权限。
     *
     * 功能总开关 $featureEnabled 为 false 时，无条件返回 false：
     * 既隐藏入口，也让直接访问 create-manual 路由被 Filament 拦成 403。
     */
    public static function canAccess(array $parameters = []): bool
    {
        if (! static::$featureEnabled) {
            return false;
        }

        return (bool) auth()->user()?->can(Permissions::ORDERS_CREATE_MANUAL);
    }

    public function mount(): void
    {
        $user = auth()->user();

        $this->form->fill([
            'currency' => 'USD',
            'items' => [[]],
            // 商户用户直接预填自己的商户；平台侧账号（超管、商户级管理员）这里是 null，
            // 表单上会显示为"请选择"，强制他们主动选一个商户，
            // 不会因为默认值是 null 而在没选的情况下悄悄往下走。
            'merchant_id' => $user->isPlatformStaff() ? null : $user->merchant_id,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $canPickMerchant = (bool) $viewer?->isPlatformStaff();
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();
        $currencies = SystemConfig::getArray('exchange.supported_currencies', ['EUR', 'JPY', 'GBP']);

        return $schema
            ->components([
                Section::make(__('admin.create_manual_order.sections.merchant'))->schema([
                    Grid::make(2)->schema([
                        Select::make('merchant_id')
                            ->label(__('admin.create_manual_order.fields.merchant'))
                            ->options(function () use ($isViewerMerchantManager, $viewer) {
                                if ($isViewerMerchantManager) {
                                    return $viewer->ownedMerchants()->where('status', true)->pluck('name', 'id');
                                }

                                return Merchant::query()->where('status', true)->pluck('name', 'id');
                            })
                            ->required()
                            ->live()
                            ->searchable()
                            // 商户用户锁死成自己所在商户，不能替别的商户下单；
                            // 超级管理员、商户级管理员都能在自己可管理的范围内自由选择。
                            ->disabled(! $canPickMerchant)
                            ->dehydrated(),
                        // 选项来自系统配置 order.platforms；手工建单没有真实的电商网站
                        // 来源，不强制填写（API 下单则必传）。
                        Select::make('platform')
                            ->label(__('admin.create_manual_order.fields.platform'))
                            ->options(fn () => array_combine($platforms = Order::supportedPlatforms(), $platforms)),
                    ]),
                ]),

                Section::make(__('admin.create_manual_order.sections.customer_info'))->schema([
                    Grid::make(3)->schema([
                        TextInput::make('customer_first_name')->label(__('admin.create_manual_order.fields.customer_first_name'))->required(),
                        TextInput::make('customer_last_name')->label(__('admin.create_manual_order.fields.customer_last_name'))->required(),
                        TextInput::make('customer_email')->label(__('admin.create_manual_order.fields.customer_email'))->email()->required(),
                        TextInput::make('customer_phone')->label(__('admin.create_manual_order.fields.customer_phone'))->required(),
                    ]),
                ]),

                Section::make(__('admin.create_manual_order.sections.shipping_address'))->schema([
                    Grid::make(2)->schema([
                        TextInput::make('shipping_address_line1')->label(__('admin.create_manual_order.fields.address_line1'))->required(),
                        TextInput::make('shipping_address_line2')->label(__('admin.create_manual_order.fields.address_line2')),
                        TextInput::make('shipping_city')->label(__('admin.create_manual_order.fields.city'))->required(),
                        TextInput::make('shipping_state')->label(__('admin.create_manual_order.fields.state')),
                        TextInput::make('shipping_country')->label(__('admin.create_manual_order.fields.country'))->required()->maxLength(2)->placeholder('DE'),
                        TextInput::make('shipping_zip')->label(__('admin.create_manual_order.fields.zip'))->required(),
                    ]),
                ]),

                Section::make(__('admin.create_manual_order.sections.items'))->schema([
                    Repeater::make('items')
                        ->label('')
                        ->schema([
                            TextInput::make('product_sku')->label(__('admin.create_manual_order.fields.sku')),
                            TextInput::make('product_id')->label(__('admin.create_manual_order.fields.product_id'))->required(),
                            TextInput::make('product_name')->label(__('admin.create_manual_order.fields.product_name'))->required(),
                            TextInput::make('product_url')->label(__('admin.create_manual_order.fields.product_url'))->url()->required(),
                            TextInput::make('unit_price')->label(__('admin.create_manual_order.fields.unit_price'))->numeric()->required(),
                            TextInput::make('quantity')->label(__('admin.create_manual_order.fields.quantity'))->numeric()->required()->default(1),
                        ])
                        ->columns(4)
                        ->addActionLabel(__('admin.create_manual_order.actions.add_item'))
                        ->minItems(1),
                ]),

                Section::make(__('admin.create_manual_order.sections.amount_summary'))->schema([
                    Grid::make(4)->schema([
                        Select::make('currency')
                            ->label(__('admin.create_manual_order.fields.currency'))
                            ->options(array_combine($currencies, $currencies))
                            ->required(),
                        TextInput::make('shipping_fee')->label(__('admin.create_manual_order.fields.shipping_fee'))->numeric()->default(0)->required(),
                        TextInput::make('discount')->label(__('admin.create_manual_order.fields.discount'))->numeric()->default(0)->required(),
                        TextInput::make('tax')->label(__('admin.create_manual_order.fields.tax'))->numeric()->default(0)->required(),
                    ]),
                    TextInput::make('amount')
                        ->label(__('admin.create_manual_order.fields.amount'))
                        ->numeric()
                        ->required()
                        ->helperText(__('admin.create_manual_order.help.amount')),
                ]),

                Section::make(__('admin.create_manual_order.sections.payment_group'))->schema([
                    Select::make('group_key')
                        ->label(__('admin.create_manual_order.fields.group_key'))
                        ->options(function (Get $get) {
                            $merchantId = $get('merchant_id');

                            if (! $merchantId) {
                                return [];
                            }

                            return PaymentGroup::query()
                                ->forMerchant($merchantId)
                                ->where('is_active', true)
                                ->pluck('group_name', 'group_key');
                        })
                        ->helperText(__('admin.create_manual_order.help.group_key'))
                        ->required(),
                ]),

                Section::make(__('admin.create_manual_order.sections.callback_urls'))
                    ->collapsed()
                    ->schema([
                        TextInput::make('notify_url')->label(__('admin.create_manual_order.fields.notify_url'))->url(),
                        TextInput::make('return_url')->label(__('admin.create_manual_order.fields.return_url'))->url(),
                        TextInput::make('cancel_url')->label(__('admin.create_manual_order.fields.cancel_url'))->url(),
                    ]),
            ])
            ->statePath('data');
    }

    public function create(ManualOrderService $service): void
    {
        $data = $this->form->getState();

        $merchantId = $data['merchant_id'];
        unset($data['merchant_id']); // 不是 OrderCreationService 期望的字段，单独传 Merchant 模型

        // subtotal 由服务端按 items 重新计算，不信任任何前端展示值
        $data['subtotal'] = collect($data['items'])
            ->sum(fn ($item) => (float) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 0));

        $merchant = Merchant::query()->findOrFail($merchantId);

        try {
            $order = $service->createOrder(
                data: $data,
                merchant: $merchant,
                application: $this->resolveDefaultApplication($merchant->id),
                operatorId: auth()->id(),
            );
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('admin.create_manual_order.notifications.create_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('admin.create_manual_order.notifications.create_success', ['order_no' => $order->order_no]))
            ->success()
            ->send();

        $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
    }

    /**
     * 手工建单在管理后台发起，没有"调用来源应用"这个自然概念，这里取该商户下
     * 第一个启用中的 Application 作为归属。如果商户有多个 Application 且
     * 希望手工建单能指定归属，这里需要改成一个显式的 Select 字段。
     *
     * 回跳域名要与这个应用绑定的 website 一致（OrderCreationService 第 4 步），
     * 所以返回完整的 Application 实例而不只是 id。
     *
     * 如果该商户下一个启用中的 Application 都没有，firstOrFail() 会抛
     * ModelNotFoundException，被 create() 的 catch 兜住并提示建单失败——
     * 这种情况本质上是数据没配全（商户必须先有至少一个 Application 才能
     * 下单，无论是 API 还是手工），报错本身是合理的信号。
     */
    private function resolveDefaultApplication(int $merchantId): Application
    {
        return Application::query()
            ->where('merchant_id', $merchantId)
            ->where('status', true)
            ->firstOrFail();
    }
}
