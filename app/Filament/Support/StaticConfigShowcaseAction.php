<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Support\StaticConfigShowcase;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Route;

/**
 * 打开当前登录者有权查看的静态配置演示页，不连库、不提交。
 * 链到当前域名的 /showcase，避免跨面板域名丢掉登录会话。
 */
class StaticConfigShowcaseAction
{
    public static function make(string $screen): Action
    {
        return Action::make('staticConfigShowcase')
            ->label(__('admin.showcase.static_config.action'))
            ->icon('heroicon-o-play-circle')
            ->color('gray')
            ->visible(function () use ($screen): bool {
                $user = auth()->user();

                return Route::has('showcase.merchant-admin')
                    && $user instanceof User
                    && StaticConfigShowcase::allows($user, $screen);
            })
            ->url(fn (): ?string => Route::has('showcase.merchant-admin')
                ? route('showcase.merchant-admin', absolute: false).'#'.$screen
                : null)
            ->openUrlInNewTab();
    }
}
