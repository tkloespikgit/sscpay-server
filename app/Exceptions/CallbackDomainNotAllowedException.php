<?php

namespace App\Exceptions;

use Exception;

/**
 * 商户传入的 notify_url / return_url / cancel_url 域名不在该商户的
 * allowed_domains 白名单内。防止跳转/回调到非商户自己的域名（SSRF、钓鱼跳转等）。
 */
class CallbackDomainNotAllowedException extends Exception
{
    public function __construct(public readonly string $field, public readonly string $url)
    {
        parent::__construct("Callback URL not allowed: {$field}={$url} is not in merchant's allowed_domains whitelist");
    }

    public function errorCode(): string
    {
        return 'CALLBACK_DOMAIN_NOT_ALLOWED';
    }
}
