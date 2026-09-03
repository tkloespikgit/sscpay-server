<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LogisticsImportTask extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'operator_id',
        'file_name',
        'oss_path',
        'status',
        'total_records',
        'success_count',
        'fail_count',
        'error_log',
    ];

    protected function casts(): array
    {
        return [
            'total_records' => 'integer',
            'success_count' => 'integer',
            'fail_count' => 'integer',
            'error_log' => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function markCompleted(int $successCount, int $failCount, array $errorLog = []): void
    {
        $this->update([
            'status' => 'completed',
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'error_log' => $errorLog ?: null,
        ]);
    }

    public function markFailed(array $errorLog = []): void
    {
        $this->update([
            'status' => 'failed',
            'error_log' => $errorLog ?: null,
        ]);
    }
}
