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
            self::DesignatedOrderCreated,
            self::OrderPaid,
            self::SiteProductsSyncCompleted,
            self::SiteProductsSyncFailed => 'channel',
            default => 'merchant',
        };
    }

    public function recipientRule(): string
    {
        return $this->group() === 'channel' ? 'channel_owner' : 'merchant';
    }

    public function hasOrderSummary(): bool
    {
        return ! in_array($this, [self::LogisticsImportCompleted, self::SiteProductsSyncCompleted, self::SiteProductsSyncFailed], true);
    }

    public function isOrderBatch(): bool
    {
        return in_array($this, [self::UnshippedOverdue, self::ShipmentSyncFailed], true);
    }

    /**
     * @return array<string, int|string>
     */
    public function strategyDefaults(): array
    {
        return match ($this) {
            self::OrderPaid => ['repeat_count' => 5],
            self::NoAvailablePaymentMethod => ['cooldown_minutes' => 10],
            self::DisputeReviewGatewayStatusIgnored => ['cooldown_minutes' => 1440],
            self::OrderDisputeDueSoon => ['reminder_hours' => 24],
            self::UnshippedOverdue => ['unshipped_days' => 10, 'send_time' => '10:00'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::PaymentMethodForbidden => ['method_name', 'method_code', 'order_no'],
            self::PaymentMethodCreationFailedDisabled => ['method_name', 'method_code', 'order_no', 'failed_times', 'max_failed_times'],
            self::PaymentLinkCreationFailed => ['merchant_id', 'order_no', 'merchant_order_no', 'source', 'currency', 'amount', 'converted_amount', 'payment_method', 'payment_method_id', 'gateway_host', 'http_status', 'pga_code', 'error'],
            self::NoAvailablePaymentMethod => ['group_name', 'group_key', 'amount', 'reason'],
            self::DesignatedOrderCreated => ['order_no', 'merchant_order_no', 'customer_email', 'currency', 'amount', 'payment_method'],
            self::OrderPaid => ['order_no', 'currency', 'amount', 'converted_amount', 'payment_method'],
            self::OrderDisputing => ['order_no'],
            self::OrderRefundGateway, self::OrderChargebackGateway => ['order_no', 'currency', 'amount'],
            self::GatewayReversalSettleFailed => ['order_no', 'status', 'error'],
            self::GatewayReversalAmountMismatch => ['order_no', 'gateway_amount', 'order_amount', 'currency'],
            self::DisputeReviewGatewayStatusIgnored => ['order_no', 'status'],
            self::OrderDisputeOpened => ['event_no', 'due_at'],
            self::OrderDisputeDueSoon => ['order_no', 'event_no', 'due_at'],
            self::LogisticsImportCompleted => ['file_name', 'total', 'success', 'failed'],
            self::LogisticsAnomaly => ['order_no', 'merchant_order_no', 'tracking_number', 'status', 'reason'],
            self::UnshippedOverdue => ['count', 'hours'],
            self::ShipmentSyncFailed => ['count'],
            self::SiteProductsSyncCompleted => ['domain', 'total', 'created', 'updated', 'deleted', 'skipped'],
            self::SiteProductsSyncFailed => ['domain', 'method_name', 'error'],
        };
    }

    /**
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $groups = [
            'merchant' => [],
            'channel' => [],
        ];

        foreach (self::cases() as $kind) {
            $groups[$kind->group()][] = $kind;
        }

        return $groups;
    }
}
