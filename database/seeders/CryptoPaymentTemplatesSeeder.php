<?php

namespace Database\Seeders;

use App\Models\PaymentMethodConfigMap;
use Illuminate\Database\Seeder;

class CryptoPaymentTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Bitcoin', 'bitcoin', 'BTC', 'bitcoin-testnet'],
            ['Litecoin', 'litecoin', 'LTC', 'litecoin-testnet'],
            ['Ethereum', 'ethereum', 'ETH', 'ethereum-sepolia'],
            ['USDT ERC20', 'ethereum', 'USDT', 'ethereum-sepolia'],
            ['USDT TRC20', 'tron', 'USDT', 'tron-shasta'],
        ] as [$name, $family, $asset, $testnet]) {
            $fields = [
                $this->field('config_version', ['1' => '1'], '1'),
                $this->field('network', [$family.'-mainnet' => $family.'-mainnet', $testnet => $testnet], $family.'-mainnet'),
                $this->field('asset', [$asset => $asset], $asset),
                $this->field('extended_public_key'),
                $this->field('derivation_profile', ['tatum-v3-'.$family.'-v1' => 'tatum-v3-'.$family.'-v1'], 'tatum-v3-'.$family.'-v1'),
                $this->field('recovery_address_0'),
                $this->field('recovery_address_1'),
                $this->field('confirmation_policy', [$family.'-configured-v1' => $family.'-configured-v1'], $family.'-configured-v1'),
            ];
            if ($asset === 'USDT') {
                $fields[] = $this->field('contract_address');
            }
            PaymentMethodConfigMap::query()->firstOrCreate(['name' => 'Crypto / '.$name], [
                'payment_type' => 'crypto', 'payment_config_tag' => 'crypto_'.$family.'_'.strtolower($asset),
                'is_active' => true, 'fields' => $fields,
            ]);
        }
    }

    /** @param array<string, string>|null $options
     * @return array<string, mixed>
     */
    private function field(string $key, ?array $options = null, ?string $default = null): array
    {
        return array_filter([
            'key' => $key, 'label' => $key, 'label_key' => 'crypto.config.'.$key,
            'required' => true, 'type' => $options === null ? 'text' : 'select', 'options' => $options, 'default' => $default,
        ], fn (mixed $value): bool => $value !== null);
    }
}
