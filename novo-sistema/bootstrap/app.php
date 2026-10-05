<?php

use App\Http\Middleware\EnsureCustomerIsActive;
use App\Http\Middleware\EnsureCustomerProfileIsComplete;
use App\Http\Middleware\EnsurePrototypesEnabled;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Http\Middleware\EnsureStaffPasswordIsCurrent;
use App\Http\Middleware\PreventCaching;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/** Area da equipe? (decide para qual login mandar quem nao esta logado) */
$isPanel = fn (Request $request): bool => $request->is('painel', 'painel/*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Webhooks (Fase 9): sem sessao, cookie ou CSRF; limite proprio.
        then: function (): void {
            Route::middleware('throttle:webhooks')->group(__DIR__.'/../routes/webhooks.php');
            // Descadastro de um clique (Fase 10): sem sessao/CSRF, URL assinada.
            Route::middleware(['signed', 'throttle:email-links', SubstituteBindings::class])->group(__DIR__.'/../routes/email-links.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) use ($isPanel): void {
        $middleware->web(append: [SecurityHeaders::class]);

        $middleware->alias([
            'staff.active' => EnsureStaffIsActive::class,
            'staff.password' => EnsureStaffPasswordIsCurrent::class,
            'customer.active' => EnsureCustomerIsActive::class,
            'customer.complete' => EnsureCustomerProfileIsComplete::class,
            'no-store' => PreventCaching::class,
            'prototypes' => EnsurePrototypesEnabled::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $r) => $isPanel($r) ? route('staff.login') : route('customer.login'));
        $middleware->redirectUsersTo(fn (Request $r) => $isPanel($r) ? route('panel.home') : route('account.home'));

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
