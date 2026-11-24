<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackAlert;
use App\Models\User;
use App\Support\Permissions;

class ChargebackAlertPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status && ($user->is_super_admin || $user->can(Permissions::CHARGEBACK_ALERTS_VIEW));
    }

    public function view(User $user, ChargebackAlert $alert): bool
    {
        return $this->viewAny($user) && ($user->is_super_admin
            || ($alert->merchant_id !== null && $alert->order_id !== null
                && in_array((int) $alert->merchant_id, array_map('intval', $user->manageableMerchantIds() ?? []), true)));
    }

    public function matchOrder(User $user, ChargebackAlert $alert): bool
    {
        return $this->view($user, $alert) && $user->is_super_admin && $alert->order_id === null;
    }

    public function process(User $user, ChargebackAlert $alert): bool
    {
        return $alert->order_id !== null && $this->view($user, $alert)
            && ($user->is_super_admin || $user->can(Permissions::CHARGEBACK_ALERTS_PROCESS));
    }

    public function feedback(User $user, ChargebackAlert $alert): bool
    {
        return $this->process($user, $alert) || $this->matchOrder($user, $alert);
    }
}
