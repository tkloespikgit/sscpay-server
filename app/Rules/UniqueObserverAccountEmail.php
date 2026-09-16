<?php

namespace App\Rules;

use App\Models\Observer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 表单里填的是 account，库里比对唯一性的是 Observer::emailForAccount() 转换后的
 * email，Filament/Laravel 自带的 unique() 规则做不了这层转换，所以单独写一个
 * （同 App\Rules\UniqueAccountEmail，只是换成 Observer 表）。
 */
class UniqueObserverAccountEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreObserverId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = Observer::query()
            ->where('email', Observer::emailForAccount((string) $value))
            ->when($this->ignoreObserverId, fn ($query) => $query->whereKeyNot($this->ignoreObserverId))
            ->exists();

        if ($exists) {
            $fail(__('admin.observer.help.account_taken'));
        }
    }
}
