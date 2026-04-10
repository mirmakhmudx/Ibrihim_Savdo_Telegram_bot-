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
    ->withMiddleware(function (Middleware $middleware): void {
        // CSRF dan ozod qilingan routelar
        $middleware->validateCsrfTokens(except: [
            'shop/*',
            'admin/*',
            'webhook/*',
            'webhook*',
        ]);

        $middleware->alias([
            'telegram.webhook' => \App\Http\Middleware\TelegramWebhookMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
