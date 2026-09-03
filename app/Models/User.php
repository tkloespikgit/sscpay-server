<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasFactory;
    use HasRoles;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;
    use SoftDeletes;

    /**
     * merchant_id 不在这里，因为它是通过独立迁移
     * (2026_07_05_000017_add_merchant_id_to_users_table) 加上的列，
     * 但对 Eloquent 而言就是普通字段，直接加进 $fillable 即可。
     */
    protected $fillable = [
        'merchant_id',
        'name',
        'email',
        'password',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * 平台超级管理员：merchant_id 为 NULL。
     * 普通商户用户：merchant_id 必须指向所属商户。
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Filament 面板访问权限判断（如需限制哪些用户能进后台可在此扩展，
     * 例如要求商户 status = true 才能登录）。
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->merchant()->exists() && (bool) $this->merchant?->status;
    }
}
