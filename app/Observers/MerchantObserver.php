<?php

namespace App\Observers;

use App\Models\Merchant;
use App\Services\MerchantRoleProvisioningService;

class MerchantObserver
{
    public function created(Merchant $merchant): void
    {
        app(MerchantRoleProvisioningService::class)->provisionDefaultRoles($merchant);
    }
}
