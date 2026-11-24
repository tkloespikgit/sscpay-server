<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargebackAlertFeedback extends Model
{
    protected $table = 'chargeback_alert_feedback';

    protected $fillable = [
        'chargeback_alert_id', 'operator_id', 'status', 'payload', 'evidence_reference',
        'attempts', 'next_attempt_at', 'started_at', 'finished_at', 'result_code',
    ];

    protected $hidden = ['payload', 'evidence_reference'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ChargebackAlert::class, 'chargeback_alert_id');
    }
}
