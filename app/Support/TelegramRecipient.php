<?php

namespace App\Support;

enum TelegramRecipient: string
{
    case Merchant = 'merchant';
    case ChannelOwner = 'channel_owner';
    case ChannelMerchants = 'channel_merchants';
    case SuperAdmins = 'super_admins';
    case None = 'none';
}
