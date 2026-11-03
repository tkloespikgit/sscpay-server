<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SystemConfig;

class ApiDocumentationReferenceService
{
    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function withConfiguredReferences(array $content): array
    {
        $reference = $content['reference'];
        $lists = [
            'reference-currencies' => [
                'codes' => SystemConfig::getArray('exchange.supported_currencies', ['USD', 'EUR', 'JPY', 'GBP']),
                'names' => $reference['currency_names'],
                'unknown' => $reference['unknown_currency'],
                'empty' => $reference['empty_currencies'],
            ],
            'reference-platforms' => [
                'codes' => Order::supportedPlatforms(),
                'names' => $reference['platform_names'],
                'unknown' => $reference['unknown_platform'],
                'empty' => $reference['empty_platforms'],
            ],
        ];

        foreach ($lists as $anchor => $list) {
            $rows = $this->rows($list['codes'], $list['names'], $list['unknown']);
            foreach ($content['sections'][$anchor]['blocks'] as $index => $block) {
                if ($block['type'] === 'table') {
                    $content['sections'][$anchor]['blocks'][$index]['rows'] = $rows;
                }
            }
            if ($rows === []) {
                $content['sections'][$anchor]['blocks'][] = ['type' => 'paragraph', 'text' => $list['empty']];
            }
        }

        return $content;
    }

    /**
     * @param  array<array-key, mixed>  $codes
     * @param  array<string, string>  $names
     * @return list<array{string, string}>
     */
    private function rows(array $codes, array $names, string $unknown): array
    {
        $codes = array_unique(array_filter($codes, fn (mixed $code): bool => is_string($code) && trim($code) !== ''));

        return array_values(array_map(fn (string $code): array => [$code, $names[$code] ?? $unknown], $codes));
    }
}
