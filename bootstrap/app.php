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
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // routes/api.php 下的对外接口全部走 App-ID + 签名鉴权，没有"网页"这个概念，
        // 异常必须始终以 JSON 呈现。Laravel 默认按 Accept: application/json 头
        // 判断要不要渲染 JSON——商户联调的签名请求经常不带这个头，一旦控制器里
        // 有没被显式 catch 的异常（例如 Merchant::findOrFail()/PaymentGroup::firstOrFail()
        // 因为参数无效抛出 ModelNotFoundException），就会退化成 Laravel 默认的 HTML
        // 404/500 报错页，而不是 JSON，商户那边的 HTTP 客户端解析不了。
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // ModelNotFoundException 会被 Laravel 内部先转换成 NotFoundHttpException 再渲染，
        // 这里统一收敛成和 ApiAuthentication::reject()/OrderController::errorResponse()
        // 一致的 {code, msg} 结构，而不是 Laravel 默认的 {"message": "..."}。
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['code' => 404, 'msg' => 'Resource not found.'], 404);
        });
    })->create();
