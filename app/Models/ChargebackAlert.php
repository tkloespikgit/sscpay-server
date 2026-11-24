<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChargebackAlert extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'chargeback_provider_account_id', 'merchant_id', 'order_id', 'service', 'external_id', 'scope_key', 'scope_is_explicit',
        'descriptor', 'arn', 'provider_transaction_id', 'provider_order_reference', 'alert_type', 'reason_code',
        'occurred_at', 'trade_at', 'trade_amount', 'trade_currency', 'case_amount', 'case_currency',
        'remote_status', 'charge_level', 'notice_observed_ms', 'match_status', 'evidence_conflict', 'next_match_at',
        'reviewed_at', 'feedback_due_at', 'remote_feedback_at', 'remote_feedback', 'next_notice_at', 'notified_at',
        'deadline_notified_at', 'notice_attempts',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'trade_at' => 'datetime',
            'trade_amount' => 'decimal:2',
            'case_amount' => 'decimal:2',
            'notice_observed_ms' => 'integer',
            'scope_is_explicit' => 'boolean',
            'evidence_conflict' => 'boolean',
            'next_match_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'feedback_due_at' => 'datetime',
            'remote_feedback_at' => 'datetime',
            'remote_feedback' => 'array',
            'next_notice_at' => 'datetime',
            'notified_at' => 'datetime',
            'deadline_notified_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChargebackProviderAccount::class, 'chargeback_provider_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChargebackAlertEvent::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(ChargebackAlertFeedback::class);
    }
}
