<?php

namespace App\Support;

use App\Services\PluginCatalog;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PluginProtocol
{
    public const VERSION = '2.0';

    /** @return list<string> */
    public function files(): array
    {
        return [
            'routes/api.php', 'app/Http/Controllers/Api/OrderController.php',
            'app/Http/Requests/Api/CreateOrderRequest.php', 'app/Http/Requests/Api/SyncOrderShippingRequest.php',
            'app/Http/Middleware/ApiAuthentication.php', 'app/Support/SignatureCanonicalizer.php',
            'app/Services/OrderApiPayload.php', 'app/Services/OrderNotificationService.php',
            'app/Services/MerchantShipmentSyncService.php', 'doc/api.md',
            'app/Http/Controllers/ApiDocumentationController.php',
            'lang/en/api_docs.php', 'lang/zh_CN/api_docs.php',
            'lang/en/api_docs_v1.php', 'lang/zh_CN/api_docs_v1.php',
            'lang/en/api_docs_v2.php', 'lang/zh_CN/api_docs_v2.php',
        ];
    }

    public function fingerprint(): string
    {
        $context = hash_init('sha256');
        foreach ($this->files() as $file) {
            hash_update($context, $file."\0".File::get(base_path($file))."\0");
        }

        return hash_final($context);
    }

    public function check(PluginCatalog $catalog, ?string $base = null): void
    {
        if ($base !== null) {
            (new Process(['git', 'rev-parse', '--verify', $base.'^{commit}'], base_path()))->mustRun();
        }
        $fingerprint = $this->fingerprint();
        foreach ($catalog->all() as $plugin) {
            if ($plugin['api_version'] !== self::VERSION || ($plugin['protocol_hash'] ?? '') !== $fingerprint) {
                throw new RuntimeException("{$plugin['slug']} 尚未同步当前 API 协议。完成适配、回归和版本递增后，将 protocol_hash 更新为 {$fingerprint}");
            }
            if ($plugin['platform'] === 'wordpress') {
                $main = File::get($plugin['path'].'/'.$plugin['slug'].'.php');
                preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $main, $header);
                preg_match("/define\(\s*'SSCPAY_[A-Z]+_VERSION',\s*'([^']+)'/", $main, $constant);
                if (($header[1] ?? '') !== $plugin['version'] || ($constant[1] ?? '') !== $plugin['version']) {
                    throw new RuntimeException("{$plugin['slug']} 的清单、插件头部与版本常量不一致。");
                }
            } else {
                $installer = json_decode(File::get($plugin['path'].'/install.json'), true, flags: JSON_THROW_ON_ERROR);
                if (($installer['version'] ?? '') !== $plugin['version']) {
                    throw new RuntimeException("{$plugin['slug']} 的清单与 install.json 版本不一致。");
                }
            }
            if ($base !== null) {
                $manifest = $plugin['manifest_path'];
                $process = new Process(['git', 'show', $base.':'.$manifest], base_path());
                $process->run();
                if ($process->isSuccessful()) {
                    $previous = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                    if (($previous['protocol_hash'] ?? '') !== $fingerprint
                        && ! version_compare($plugin['version'], $previous['version'], '>')) {
                        throw new RuntimeException("API 协议已改变，{$plugin['slug']} 必须递增版本并同步发布。");
                    }
                }
            }
        }
    }
}
