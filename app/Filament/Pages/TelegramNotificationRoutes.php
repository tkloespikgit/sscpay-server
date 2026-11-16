<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\TelegramNotificationRouter;
use App\Support\TelegramNotificationKind;
use App\Support\TelegramRecipient;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class TelegramNotificationRoutes extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected string $view = 'filament.pages.telegram-notification-routes';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.telegram_routes.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return __('admin.telegram_routes.description');
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
        $this->form->fill(app(TelegramNotificationRouter::class)->current());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(
            collect(TelegramNotificationKind::grouped())
                ->map(fn (array $kinds, string $group): Section => Section::make(__('admin.telegram_routes.groups.'.$group))
                    ->schema(array_map(fn (TelegramNotificationKind $kind): CheckboxList => CheckboxList::make($kind->value)
                        ->label($this->kindLabel($kind))
                        ->options(collect($kind->allowed())->mapWithKeys(fn (TelegramRecipient $recipient): array => [
                            $recipient->value => __('admin.telegram_routes.recipients.'.$recipient->value),
                        ])->all())
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old) use ($kind): void {
                            $next = $this->exclusiveRecipients($state, $old);

                            if ($next !== null) {
                                $set($kind->value, $next);
                            }
                        })
                        ->required()
                        ->markAsRequired(false)
                        ->columns(1), $kinds))
                    ->collapsible())
                ->values()
                ->all(),
        )->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            app(TelegramNotificationRouter::class)->save($this->form->getState());
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $key => $errors) {
                $messages['data.'.strtok($key, '.')] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }

        Notification::make()
            ->success()
            ->title(__('admin.telegram_routes.saved'))
            ->send();
    }

    /**
     * 「不发送」与其他接收人互斥。后勾选的一边留下，先勾选的一边去掉。
     *
     * @return list<string>|null
     */
    private function exclusiveRecipients(mixed $state, mixed $old): ?array
    {
        $selected = array_values(array_filter(is_array($state) ? $state : [], is_string(...)));
        $previous = array_values(array_filter(is_array($old) ? $old : [], is_string(...)));
        $none = TelegramRecipient::None->value;

        if (! in_array($none, $selected, true) || count($selected) < 2) {
            return null;
        }

        $added = array_values(array_diff($selected, $previous));

        if ($added === [] || in_array($none, $added, true)) {
            return [$none];
        }

        return array_values(array_filter(
            $selected,
            fn (string $value): bool => $value !== $none,
        ));
    }

    private function kindLabel(TelegramNotificationKind $kind): HtmlString
    {
        $note = rtrim(__('admin.telegram_routes.kinds.'.$kind->value.'.description'), '。.');
        $isChinese = str_starts_with(app()->getLocale(), 'zh');

        return new HtmlString(
            e(__('admin.telegram_routes.kinds.'.$kind->value.'.label'))
            .'<sup class="fi-fo-field-label-required-mark">*</sup>'
            .'<span class="ms-1 font-normal text-gray-500 dark:text-gray-400">'
            .($isChinese ? '（' : '(').e($note).($isChinese ? '）' : ')')
            .'</span>'
        );
    }
}
