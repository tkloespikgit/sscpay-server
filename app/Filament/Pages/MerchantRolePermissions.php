<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\MerchantRoleProvisioningService;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MerchantRolePermissions extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected string $view = 'filament.pages.merchant-role-permissions';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.role_permissions.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return __('admin.role_permissions.description');
    }

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return Filament::getCurrentPanel()?->getId() === 'admin'
            && $user instanceof User
            && $user->is_super_admin
            && $user->status;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->fillFromTemplates();
    }

    public function form(Schema $schema): Schema
    {
        $options = collect(Permissions::merchantScoped())
            ->mapWithKeys(fn (string $permission) => [
                $permission => __('admin.role_permissions.permissions.'.str_replace('.', '_', $permission)),
            ])->all();

        return $schema->components(
            collect(app(MerchantRoleProvisioningService::class)->definitions())
                ->map(fn (array $definition, string $key) => Section::make(__('admin.role_permissions.roles.'.$key))
                    ->collapsible()
                    ->collapsed($key !== 'order_admin')
                    ->schema([
                        CheckboxList::make($key)
                            ->label(__('admin.role_permissions.permissions_label'))
                            ->options($options)
                            ->columns(['default' => 1, 'md' => 3])
                            ->bulkToggleable(),
                    ]))
                ->values()->all(),
        )->statePath('data');
    }

    public function save(): void
    {
        // Livewire 后续请求也重新鉴权，不能只依赖菜单隐藏或首次 mount。
        abort_unless(static::canAccess(), 403);

        $count = app(MerchantRoleProvisioningService::class)->saveAndSync($this->form->getState());
        $this->fillFromTemplates();

        Notification::make()
            ->success()
            ->title(__('admin.role_permissions.saved'))
            ->body(__('admin.role_permissions.synced', ['count' => $count]))
            ->send();
    }

    private function fillFromTemplates(): void
    {
        $this->form->fill(collect(app(MerchantRoleProvisioningService::class)->definitions())
            ->mapWithKeys(fn (array $definition, string $key) => [$key => $definition['permissions']])
            ->all());
    }
}
