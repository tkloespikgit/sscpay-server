<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackProviderAccount;
use App\Models\User;
use App\Support\Permissions;

class ChargebackProviderAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status && ($user->is_super_admin || ($user->isMerchantManager() && $user->can(Permissions::PAYMENT_METHODS_MANAGE)));
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, ChargebackProviderAccount $account): bool
    {
        return $this->viewAny($user) && ($user->is_super_admin || (int) $account->owner_id === (int) $user->id);
    }

    public function update(User $user, ChargebackProviderAccount $account): bool
    {
        return $this->view($user, $account);
    }
}
