<?php

/*
|--------------------------------------------------------------------------
| bootstrap/app.php 配置片段
|--------------------------------------------------------------------------
| Laravel 12 默认不再用 app/Http/Kernel.php 注册中间件别名，而是在
| bootstrap/app.php 的 ->withMiddleware() 里配置。把下面这几行合并进你们
| 现有的 bootstrap/app.php 对应位置即可（这里单独列出只是方便复制，
| 不是一个可以直接替换整个文件的完整版本）。
*/

use App\Http\Middleware\ApiAuthentication;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'api.auth' => ApiAuthentication::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
