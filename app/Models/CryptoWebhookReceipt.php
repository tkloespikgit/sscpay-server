<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Signed delivery evidence awaiting chain verification, never a confirmed payment event. */
class CryptoWebhookReceipt extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = ['merchant_id', 'crypto_address_monitor_id', 'crypto_payment_request_id', 'payload_hash', 'transaction_hash', 'hint_payload', 'signature_mode', 'status', 'received_at', 'processed_at', 'verification_attempts', 'last_checked_at', 'next_attempt_at', 'last_error_code'];

    protected $hidden = ['hint_payload'];

    protected function casts(): array
    {
        return ['hint_payload' => 'array', 'received_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime',
            'verification_attempts' => 'integer', 'last_checked_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            if ($receipt->isDirty(['merchant_id', 'crypto_address_monitor_id', 'crypto_payment_request_id', 'payload_hash', 'transaction_hash', 'hint_payload', 'signature_mode', 'received_at'])) {
                throw new LogicException('Webhook receipt evidence cannot be rewritten.');
            }
        });
        static::deleting(fn () => throw new LogicException('Webhook receipt evidence cannot be deleted.'));
    }
}
