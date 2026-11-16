<?php

use Dotenv\Dotenv;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade-only import. Runtime code never reads these environment variables again.
        // Cached legacy config is authoritative when deploying over a config-cached release.
        $legacy = config('services.tatum', []);
        $path = app()->environmentFilePath();
        $readEnvironment = ! app()->environment('testing') || basename($path) === '.env.testing';
        $environment = $readEnvironment && is_file($path) ? Dotenv::parse(file_get_contents($path)) : [];
        $settings = [
            ['checkout_enabled', 'CRYPTO_CHECKOUT_ENABLED', 'boolean', 'false', '允许新建区块链订单；关闭后已有订单继续监听、补查和入账'],
            ['subscriptions_enabled', 'TATUM_SUBSCRIPTIONS_ENABLED', 'boolean', 'false', '地址订阅维护及回调接收开关'],
            ['verification_enabled', 'TATUM_VERIFICATION_ENABLED', 'boolean', 'false', '链上交易核验开关'],
            ['history_enabled', 'CRYPTO_HISTORY_ENABLED', 'boolean', 'false', '历史补查与冷地址扫描开关'],
            ['settlement_enabled', 'CRYPTO_SETTLEMENT_ENABLED', 'boolean', 'false', '已核验收款自动入账开关'],
            ['api_key', 'TATUM_API_KEY', 'secret', null, 'Tatum 主网及行情 API Key，加密保存'],
            ['testnet_key', 'TATUM_TESTNET_API_KEY', 'secret', null, 'Tatum 测试网 API Key，加密保存'],
            ['mainnet_hmac_secret', 'TATUM_MAINNET_HMAC_SECRET', 'secret', null, '主网回调验签密钥，16–100 字符，加密保存'],
            ['testnet_hmac_secret', 'TATUM_TESTNET_HMAC_SECRET', 'secret', null, '测试网回调验签密钥，16–100 字符，加密保存'],
            ['webhook_base_url', 'TATUM_WEBHOOK_BASE_URL', 'string', null, '公网 HTTPS 回调域名，不带路径；已有订阅保留原回调地址，需继续保持原域名可达'],
            ['history_max_head_age_seconds', 'CRYPTO_HISTORY_MAX_HEAD_AGE_SECONDS', 'number', null, '允许最新区块距今的最大秒数，1–86400；未配置时不开放收款'],
            ['timeout', null, 'number', '10', '地址派生／行情请求超时秒数，1–60'],
            ['connect_timeout', null, 'number', '3', '地址派生／行情连接超时秒数，1–60'],
            ['evm_min_confirmations.ethereum-mainnet', 'CRYPTO_ETHEREUM_MIN_CONFIRMATIONS', 'number', null, 'ethereum-mainnet 最低确认数，1–100000；新请求保存快照，旧请求保持原规则'],
            ['evm_min_confirmations.ethereum-sepolia', 'CRYPTO_SEPOLIA_MIN_CONFIRMATIONS', 'number', null, 'ethereum-sepolia 最低确认数，1–100000；新请求保存快照，旧请求保持原规则'],
            ['utxo_min_confirmations.bitcoin-mainnet', 'CRYPTO_BITCOIN_MIN_CONFIRMATIONS', 'number', null, 'bitcoin-mainnet 最低确认数，1–100000；新请求保存快照，旧请求保持原规则'],
            ['genesis_hashes.bitcoin-mainnet', 'CRYPTO_BITCOIN_GENESIS_HASH', 'string', null, 'bitcoin-mainnet 创世区块哈希，64 位十六进制；新请求保存快照'],
            ['utxo_min_confirmations.bitcoin-testnet', 'CRYPTO_BITCOIN_TESTNET_MIN_CONFIRMATIONS', 'number', null, 'bitcoin-testnet 最低确认数，1–100000；新请求保存快照，旧请求保持原规则'],
            ['genesis_hashes.bitcoin-testnet', 'CRYPTO_BITCOIN_TESTNET_GENESIS_HASH', 'string', null, 'bitcoin-testnet 创世区块哈希，64 位十六进制；新请求保存快照'],
            ['utxo_min_confirmations.litecoin-mainnet', 'CRYPTO_LITECOIN_MIN_CONFIRMATIONS', 'number', null, 'litecoin-mainnet 最低确认数，1–100000；新请求保存快照，旧请求保持原规则'],
            ['genesis_hashes.litecoin-mainnet', 'CRYPTO_LITECOIN_GENESIS_HASH', 'string', null, 'litecoin-mainnet 创世区块哈希，64 位十六进制；新请求保存快照'],
            ['utxo_min_confirmations.litecoin-testnet', 'CRYPTO_LITECOIN_TESTNET_MIN_CONFIRMATIONS', 'number', null, 'litecoin-testnet 最低确认数，1–100000；新请求保存快照，旧请求保持原规则'],
            ['genesis_hashes.litecoin-testnet', 'CRYPTO_LITECOIN_TESTNET_GENESIS_HASH', 'string', null, 'litecoin-testnet 创世区块哈希，64 位十六进制；新请求保存快照'],
            ['tron_min_confirmations.tron-mainnet', 'CRYPTO_TRON_MIN_CONFIRMATIONS', 'number', null, 'tron-mainnet 最低确认数，1–100000；新请求保存快照，旧请求保持原规则'],
            ['genesis_hashes.tron-mainnet', 'CRYPTO_TRON_GENESIS_HASH', 'string', null, 'tron-mainnet 创世区块哈希，64 位十六进制；新请求保存快照'],
            ['tron_min_confirmations.tron-shasta', 'CRYPTO_SHASTA_MIN_CONFIRMATIONS', 'number', null, 'tron-shasta 最低确认数，1–100000；新请求保存快照，旧请求保持原规则'],
            ['genesis_hashes.tron-shasta', 'CRYPTO_SHASTA_GENESIS_HASH', 'string', null, 'tron-shasta 创世区块哈希，64 位十六进制；新请求保存快照'],
            ['test_tokens.ethereum-sepolia', 'CRYPTO_TEST_ERC20_CONTRACT', 'string', null, 'Sepolia 测试 ERC20 合约地址，不使用主网 USDT 地址'],
            ['test_tokens.tron-shasta', 'CRYPTO_TEST_TRC20_CONTRACT', 'string', null, 'Shasta 测试 TRC20 合约地址，不使用主网 USDT 地址'],
        ];
        DB::transaction(function () use ($settings, $legacy, $environment, $readEnvironment): void {
            foreach ($settings as [$name, $variable, $type, $default, $description]) {
                $key = 'crypto.'.$name;
                $existing = DB::table('system_configs')->where('config_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if ($type === 'secret') {
                        $ciphertext = $existing->secret_value;
                        if (blank($ciphertext) && filled($existing->config_value)) {
                            $ciphertext = Crypt::encryptString($existing->config_value);
                        }
                        DB::table('system_configs')->where('id', $existing->id)->update([
                            'config_value' => null, 'secret_value' => $ciphertext, 'value_type' => 'secret',
                        ]);
                    }

                    continue;
                }
                $value = Arr::get($legacy, $name, $variable === null ? $default
                    : ($readEnvironment ? Env::get($variable, $environment[$variable] ?? $default) : $default));
                if ($type === 'boolean') {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                } elseif ($value === '') {
                    $value = null;
                } elseif ($value !== null) {
                    $value = (string) $value;
                }
                DB::table('system_configs')->insertOrIgnore([
                    'config_key' => $key, 'config_value' => $type === 'secret' ? null : $value,
                    'secret_value' => $type === 'secret' && $value !== null ? Crypt::encryptString($value) : null,
                    'value_type' => $type, 'group' => 'crypto', 'description' => $description,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }, 3);
        foreach ($settings as [$name]) {
            Cache::forget('system_config:crypto.'.$name);
            Cache::forget('system_config_secret:crypto.'.$name);
        }
    }

    /** Keep operator settings and credentials on rollback; never restore plaintext secrets. */
    public function down(): void {}
};
