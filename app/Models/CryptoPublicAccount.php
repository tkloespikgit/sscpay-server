<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Global technical counter shared by identical public derivation sources, never exposed as a merchant resource. */
class CryptoPublicAccount extends Model
{
    use HasFactory;

    protected $fillable = ['fingerprint', 'family', 'extended_public_key', 'derivation_profile', 'next_index'];

    protected $hidden = ['extended_public_key'];

    protected function casts(): array
    {
        return ['next_index' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $account): void {
            if ($account->isDirty(['fingerprint', 'family', 'extended_public_key', 'derivation_profile'])
                || $account->next_index < $account->getOriginal('next_index')) {
                throw new \LogicException('Public derivation identity and consumed indices cannot be changed.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Public derivation history cannot be deleted.'));
    }
}
