<?php

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
        // Railway ينهي HTTPS عند الوكيل؛ نثق بترويسات X-Forwarded-* حتى تُولَّد الروابط بـ https
        $middleware->trustProxies(at: '*');

        // إجابات التقييم تُحفظ حرفيًا: قص المسافات يغيّر إزاحة الكود
        $middleware->trimStrings(except: ['answer']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
