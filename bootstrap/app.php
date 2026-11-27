<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectLocalIpToLocalhost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {
        $rawProxies = $_ENV['TRUSTED_PROXIES'] ?? $_SERVER['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');
        $trustedProxies = is_string($rawProxies) && trim($rawProxies) !== ''
            ? array_values(array_filter(
                array_map('trim', explode(',', $rawProxies)),
                static fn (string $ip): bool => $ip !== '' && $ip !== '*' && $ip !== '**',
            ))
            : ['127.0.0.1', '::1'];

        $middleware->trustProxies(at: $trustedProxies !== [] ? $trustedProxies : ['127.0.0.1', '::1']);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            RedirectLocalIpToLocalhost::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
