<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 表单里填的是 account，库里比对唯一性的是 User::emailForAccount() 转换后的
 * email，Filament/Laravel 自带的 unique() 规则做不了这层转换，所以单独写一个。
 */
class UniqueAccountEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = User::query()
            ->where('email', User::emailForAccount((string) $value))
            ->when($this->ignoreUserId, fn ($query) => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($exists) {
            $fail(__('admin.user.help.account_taken'));
        }
    }
}
