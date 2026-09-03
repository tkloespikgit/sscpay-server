<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 资金操作校验失败（余额/提现/退款/拒付的业务规则不满足）。
 * Filament 动作捕获它后转成 danger 通知展示给操作者，携带的 message
 * 是面向管理员的中文提示，可直接展示。
 */
class BalanceOperationException extends RuntimeException
{
}
