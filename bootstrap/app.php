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
        $middleware->append(\App\Http\Middleware\AssignRequestId::class);
        $middleware->alias([
            'permission' => \App\Http\Middleware\RequirePermission::class,
            'audit'      => \App\Http\Middleware\AuditMutations::class,
            'idempotent' => \App\Http\Middleware\Idempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        \App\Support\ApiExceptions::register($exceptions);
    })->create();
