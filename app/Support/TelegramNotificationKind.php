<?php

namespace App\Support;

enum TelegramNotificationKind: string
{
    case PaymentMethodForbidden = 'payment_method_forbidden';
    case PaymentMethodCreationFailedDisabled = 'payment_method_creation_failed_disabled';
    case PaymentLinkCreationFailed = 'payment_link_creation_failed';
    case NoAvailablePaymentMethod = 'no_available_payment_method';
    case DesignatedOrderCreated = 'designated_order_created';
    case OrderPaid = 'order_paid';
    case OrderDisputing = 'order_disputing';
    case OrderRefundGateway = 'order_refund_gateway';
    case OrderChargebackGateway = 'order_chargeback_gateway';
    case GatewayReversalSettleFailed = 'gateway_reversal_settle_failed';
    case GatewayReversalAmountMismatch = 'gateway_reversal_amount_mismatch';
    case DisputeReviewGatewayStatusIgnored = 'dispute_review_gateway_status_ignored';
    case OrderDisputeOpened = 'order_dispute_opened';
    case OrderDisputeDueSoon = 'order_dispute_due_soon';
    case LogisticsImportCompleted = 'logistics_import_completed';
    case LogisticsAnomaly = 'logistics_anomaly';
    case UnshippedOverdue = 'unshipped_overdue';
    case ShipmentSyncFailed = 'shipment_sync_failed';
    case SiteProductsSyncCompleted = 'site_products_sync_completed';
    case SiteProductsSyncFailed = 'site_products_sync_failed';

    public function group(): string
    {
        return match ($this) {
            self::PaymentMethodForbidden,
            self::PaymentMethodCreationFailedDisabled,
            self::PaymentLinkCreationFailed,
            self::NoAvailablePaymentMethod => 'payment',
            self::LogisticsImportCompleted,
            self::LogisticsAnomaly,
            self::UnshippedOverdue,
            self::ShipmentSyncFailed => 'logistics',
            self::SiteProductsSyncCompleted,
            self::SiteProductsSyncFailed => 'products',
            default => 'order',
        };
    }

    public function allowsChannel(): bool
    {
        return ! in_array($this, [
            self::NoAvailablePaymentMethod,
            self::LogisticsImportCompleted,
            self::UnshippedOverdue,
            self::ShipmentSyncFailed,
        ], true);
    }

    /**
     * @return list<TelegramRecipient>
     */
    public function allowed(): array
    {
        $recipients = [TelegramRecipient::Merchant];

        if ($this->allowsChannel()) {
            $recipients[] = TelegramRecipient::ChannelOwner;
            $recipients[] = TelegramRecipient::ChannelMerchants;
        }

        $recipients[] = TelegramRecipient::SuperAdmins;
        $recipients[] = TelegramRecipient::None;

        return $recipients;
    }

    /**
     * @return list<TelegramRecipient>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::PaymentMethodForbidden,
            self::PaymentMethodCreationFailedDisabled => [TelegramRecipient::ChannelOwner],
            self::SiteProductsSyncCompleted,
            self::SiteProductsSyncFailed => [TelegramRecipient::ChannelMerchants],
            default => [TelegramRecipient::Merchant],
        };
    }

    /**
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $groups = [
            'payment' => [],
            'order' => [],
            'logistics' => [],
            'products' => [],
        ];

        foreach (self::cases() as $kind) {
            $groups[$kind->group()][] = $kind;
        }

        return $groups;
    }
}
