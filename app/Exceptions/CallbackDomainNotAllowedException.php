<?php

namespace App\Exceptions;

use Exception;

/**
 * 商户传入的 notify_url / return_url / cancel_url 域名与本次下单所属应用绑定的
 * 域名（applications.website）不一致。防止跳转/回调到非应用绑定站点（SSRF、钓鱼跳转等）。
 */
class CallbackDomainNotAllowedException extends Exception
{
    public function __construct(public readonly string $field, public readonly string $url, public readonly string $expectedDomain)
    {
        parent::__construct($expectedDomain === ''
            ? "Callback URL domain mismatch: {$field}={$url} but the application has no bound domain (website)"
            : "Callback URL domain mismatch: {$field}={$url} does not match the application's bound domain '{$expectedDomain}'");
    }

    public function errorCode(): string
    {
        return 'CALLBACK_DOMAIN_NOT_ALLOWED';
    }
}
