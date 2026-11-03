<?php

namespace App\Filament\Widgets;

use App\Models\Merchant;
use App\Models\User;
use App\Support\Permissions;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MerchantBalanceOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    public function boot(): void
    {
        abort_unless(static::canView(), 403);
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->status && ! $user->isPlatformStaff()
            && $user->merchant_id !== null && $user->can(Permissions::FINANCE_VIEW)
            && Merchant::query()->whereKey($user->merchant_id)->where('status', true)->exists();
    }

    public function getHeading(): ?string
    {
        return __('admin.dashboard.balance.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboard.balance.description');
    }

    protected function getColumns(): int|array|null
    {
        return 5;
    }

    protected function getStats(): array
    {
        abort_unless(static::canView(), 403);

        $merchant = Merchant::query()->whereKey(auth()->user()->merchant_id)->where('status', true)
            ->withSum('legacyPendingWithdrawals as legacy_pending_withdrawal_balance', 'amount')->firstOrFail();

        return [
            $this->balanceStat('total', (string) $merchant->balance),
            $this->balanceStat('pending_settlement', (string) $merchant->pending_settlement_balance),
            $this->balanceStat('available', $merchant->availableBalance()),
            $this->balanceStat('pending_withdrawal', $merchant->pendingWithdrawalBalance()),
            $this->balanceStat('reserve', $merchant->reserveBalance()),
        ];
    }

    private function balanceStat(string $key, string $amount): Stat
    {
        return Stat::make(__('settlement.balances.'.$key), '$'.number_format((float) $amount, 2));
    }
}
