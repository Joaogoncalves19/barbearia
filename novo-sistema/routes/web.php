<?php

use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Prototypes\PrototypeController;
use App\Http\Controllers\Site\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas web
|--------------------------------------------------------------------------
|
| Regra (testada em tests/Feature/Security/RouteAuthorizationTest.php):
| toda rota fora da lista publica explicita precisa de autenticacao E de uma
| permissao ('can:...'). Rota nova sem isso quebra o CI.
|
*/

// --- Publico ----------------------------------------------------------------
Route::get('/', HomeController::class)->name('home');

// --- Autenticacao da equipe -------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/entrar', [StaffLoginController::class, 'create'])->name('login');
    Route::post('/entrar', [StaffLoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.attempt');
});

Route::post('/sair', [StaffLoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// --- Painel da equipe (autenticado + ativo + permissao) ---------------------
Route::prefix('painel')
    ->name('panel.')
    ->middleware(['auth', 'staff.active', 'can:panel.access'])
    ->group(function () {
        Route::get('/', DashboardController::class)->name('home');
    });

// --- Referencias visuais (somente com BARBEARIA_PROTOTYPES=true) ------------
// Dados e fotos de EXEMPLO. Serao removidas quando as telas reais existirem.
Route::middleware('prototypes')->group(function () {
    Route::get('/design-system', [PrototypeController::class, 'designSystem'])->name('prototypes.design-system');

    Route::prefix('prototipos')->name('prototypes.')->group(function () {
        Route::get('/', [PrototypeController::class, 'index'])->name('index');
        Route::get('/home', [PrototypeController::class, 'home'])->name('home');
        Route::get('/servicos', [PrototypeController::class, 'services'])->name('services');
        Route::get('/agendamento', [PrototypeController::class, 'booking'])->name('booking');
        Route::get('/painel', [PrototypeController::class, 'dashboard'])->name('dashboard');
        Route::get('/agenda', [PrototypeController::class, 'agenda'])->name('agenda');
    });
});
