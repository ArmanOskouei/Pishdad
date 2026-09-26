<?php

use App\Http\Middleware\EnsureCentralOperator;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\GateDevModeUpload;
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
            'central.operator' => EnsureCentralOperator::class,
            'perm' => EnsurePermission::class,
            'devmode.gate' => GateDevModeUpload::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
