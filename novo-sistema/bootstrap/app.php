<?php

use App\Http\Middleware\EnsurePrototypesEnabled;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class]);

        $middleware->alias([
            'staff.active' => EnsureStaffIsActive::class,
            'prototypes' => EnsurePrototypesEnabled::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('panel.home'));

        // Em producao/homologacao so respondemos ao host do APP_URL.
        $middleware->trustHosts(
            at: fn () => app()->environment(['production', 'homologacao'])
                ? ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$']
                : [],
            subdomains: false,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
