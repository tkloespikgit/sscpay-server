<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackChannelBinding;
use App\Models\ChargebackProviderAccount;
use App\Models\PaymentMethod;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class ChargebackAccountConfiguration
{
    public function __construct(private ChargebackAlertDrivers $drivers, private ChargebackProviderAccountPolicy $policy) {}

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): ChargebackProviderAccount
    {
        return DB::transaction(function () use ($actor, $input, $id): ChargebackProviderAccount {
            $actor = User::query()->findOrFail($actor->id);
            $account = $id === null ? null : ChargebackProviderAccount::query()->lockForUpdate()->findOrFail($id);
            abort_unless($account ? $this->policy->update($actor, $account) : $this->policy->create($actor), 403);

            $identity = $account
                ? $account->only(['provider', 'external_id', 'environment', 'timezone'])
                : Validator::make($input, [
                    'provider' => ['required', 'string', Rule::in(array_keys($this->drivers->options()))],
                    'external_id' => [
                        'required', 'string', 'regex:/\A[A-Za-z0-9_.-]{1,120}\z/',
                        Rule::unique('chargeback_provider_accounts')->where('provider', is_string($input['provider'] ?? null) ? $input['provider'] : ''),
                    ],
                    'environment' => ['required', Rule::in(['test', 'production'])],
                    'timezone' => ['required', 'timezone:all'],
                ])->validate();

            $driver = $this->drivers->find($identity['provider']);
            abort_unless($driver, 422);
            $fields = array_keys($driver->signingFields());
            $rules = [
                'name' => ['required', 'string', 'max:120'],
                'enabled' => ['required', 'boolean'],
                'services' => ['required', 'array', 'min:1'],
                'services.*' => ['required', 'string', 'distinct', Rule::in(array_keys($driver->services()))],
                'signing_config' => ['sometimes', 'array:'.implode(',', $fields)],
            ];
            foreach ($fields as $key) {
                $rules['signing_config.'.$key] = [$account ? 'nullable' : 'required', 'string', 'max:512', 'not_regex:/[\x00-\x1f\x7f]/'];
            }
            $valid = Validator::make($input, $rules)->validate();
            $credentials = array_replace(
                $account?->signing_config ?? [],
                array_filter($valid['signing_config'] ?? [], fn ($value): bool => $value !== null && $value !== ''),
            );

            $record = $account ?? new ChargebackProviderAccount($identity + ['owner_id' => $actor->is_super_admin ? null : $actor->id]);
            $record->fill(['name' => $valid['name'], 'enabled' => $valid['enabled'], 'services' => array_values($valid['services'])]);
            if ($account === null || $credentials !== $account->signing_config) {
                $record->signing_config = $credentials;
            }
            if ($record->isDirty(['enabled', 'services', 'signing_config'])) {
                $record->fill(['next_sync_at' => null, 'sync_cursor' => null, 'sync_error' => null]);
            }
            $record->save();

            return $record;
        }, 3);
    }

    /** @return array{unassigned: Collection<int, PaymentMethod>, assigned: Collection<int, PaymentMethod>} */
    public function columns(User $actor, int $accountId, string $service, string $scopeKey): array
    {
        $actor = User::query()->findOrFail($actor->id);
        $account = ChargebackProviderAccount::query()->findOrFail($accountId);
        abort_unless($this->policy->view($actor, $account), 403);
        $methods = $this->eligibleMethods($account)->orderBy('method_name')->orderBy('id')
            ->get(['id', 'method_name', 'method_code', 'is_active', 'merchant_id']);
        $ids = $this->bindings($account, $service, trim($scopeKey))->whereNull('valid_until')->pluck('payment_method_id')->all();

        [$assigned, $unassigned] = $methods->partition(fn (PaymentMethod $method): bool => in_array($method->id, $ids, true));

        return ['unassigned' => $unassigned, 'assigned' => $assigned];
    }

    /** @param array<string, mixed> $input */
    public function setBinding(User $actor, int $accountId, int $methodId, bool $selected, array $input): void
    {
        DB::transaction(function () use ($actor, $accountId, $methodId, $selected, $input): void {
            $actor = User::query()->findOrFail($actor->id);
            $account = ChargebackProviderAccount::query()->lockForUpdate()->findOrFail($accountId);
            abort_unless($this->policy->update($actor, $account), 403);
            abort_unless($this->eligibleMethods($account)->whereKey($methodId)->lockForUpdate()->first(), 403);
            $driver = $this->drivers->find($account->provider);
            abort_unless($driver, 422);
            $service = is_string($input['service'] ?? null) ? $input['service'] : '';
            $data = Validator::make($input, [
                'service' => ['required', Rule::in($account->services)],
                'scope_key' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
                'reference_type' => [Rule::requiredIf($selected), Rule::in($driver->referenceTypes($service))],
                'valid_from' => [Rule::requiredIf($selected), 'nullable', 'date_format:Y-m-d H:i:s', 'before_or_equal:now'],
            ])->validate();
            $scopeKey = trim($data['scope_key']);
            if ($scopeKey === '') {
                throw ValidationException::withMessages(['scope_key' => __('chargeback_alerts.scope_required')]);
            }

            $bindings = $this->bindings($account, $service, $scopeKey)->where('payment_method_id', $methodId);
            $active = (clone $bindings)->whereNull('valid_until')->first();
            if (! $selected) {
                $active?->update(['valid_until' => now()]);

                return;
            }
            if ($active) {
                if ($active->reference_type !== $data['reference_type']) {
                    throw ValidationException::withMessages(['reference_type' => __('chargeback_alerts.end_binding_first')]);
                }

                return;
            }

            $start = CarbonImmutable::parse($data['valid_from']);
            if ((clone $bindings)->where('valid_until', '>', $start)->exists()) {
                throw ValidationException::withMessages(['valid_from' => __('chargeback_alerts.overlapping_binding')]);
            }

            $account->bindings()->create([
                'service' => $service, 'scope_key' => $scopeKey, 'scope_hash' => hash('sha256', $scopeKey),
                'payment_method_id' => $methodId, 'reference_type' => $data['reference_type'], 'valid_from' => $start,
            ]);
        }, 3);
    }

    private function eligibleMethods(ChargebackProviderAccount $account): Builder
    {
        $query = PaymentMethod::query()->withoutGlobalScope(MerchantScope::class)->where('payment_type', 'fiat');
        if ($account->owner_id === null) {
            return $query;
        }

        $owner = User::query()->findOrFail($account->owner_id);
        abort_unless($this->policy->viewAny($owner) && $owner->isMerchantManager(), 403);

        return $query->where(function (Builder $methods) use ($owner): void {
            $methods->where(fn (Builder $shared) => $shared->whereNull('merchant_id')->where('owner_id', $owner->id))
                ->orWhereIn('merchant_id', $owner->manageableMerchantIds());
        });
    }

    private function bindings(ChargebackProviderAccount $account, string $service, string $scopeKey): Builder
    {
        return ChargebackChannelBinding::query()->where('chargeback_provider_account_id', $account->id)
            ->where('service', $service)->where('scope_hash', hash('sha256', $scopeKey));
    }
}
