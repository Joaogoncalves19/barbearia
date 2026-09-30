<?php

use App\Http\Controllers\Account\AccountHomeController;
use App\Http\Controllers\Account\AccountPasswordController;
use App\Http\Controllers\Account\AppointmentController as AccountAppointmentController;
use App\Http\Controllers\Account\BookingController as AccountBookingController;
use App\Http\Controllers\Account\CompleteProfileController;
use App\Http\Controllers\Account\ProfileController as AccountProfileController;
use App\Http\Controllers\Auth\Customer\EmailVerificationController;
use App\Http\Controllers\Auth\Customer\ForgotPasswordController as CustomerForgotPasswordController;
use App\Http\Controllers\Auth\Customer\LoginController as CustomerLoginController;
use App\Http\Controllers\Auth\Customer\MagicLinkController;
use App\Http\Controllers\Auth\Customer\RegisterController;
use App\Http\Controllers\Auth\Customer\ResetPasswordController as CustomerResetPasswordController;
use App\Http\Controllers\Auth\Staff\ConfirmPasswordController;
use App\Http\Controllers\Auth\Staff\ForgotPasswordController as StaffForgotPasswordController;
use App\Http\Controllers\Auth\Staff\LoginController as StaffLoginController;
use App\Http\Controllers\Auth\Staff\ResetPasswordController as StaffResetPasswordController;
use App\Http\Controllers\Panel\AccountController as PanelAccountController;
use App\Http\Controllers\Panel\Agenda\AgendaController;
use App\Http\Controllers\Panel\Agenda\AppointmentController as PanelAppointmentController;
use App\Http\Controllers\Panel\Agenda\BlockController;
use App\Http\Controllers\Panel\Agenda\ScheduleSettingsController;
use App\Http\Controllers\Panel\Agenda\TimeOffController;
use App\Http\Controllers\Panel\Agenda\WorkingHoursController;
use App\Http\Controllers\Panel\AuditLogController;
use App\Http\Controllers\Panel\Catalog\CategoryController;
use App\Http\Controllers\Panel\Catalog\ServiceController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\PasswordController as PanelPasswordController;
use App\Http\Controllers\Panel\Team\ProfessionalController;
use App\Http\Controllers\Panel\UserController;
use App\Http\Controllers\Prototypes\PrototypeController;
use App\Http\Controllers\Site\BookingController as SiteBookingController;
use App\Http\Controllers\Site\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas web
|--------------------------------------------------------------------------
|
| DENY BY DEFAULT (testado em tests/Feature/Security/RouteAuthorizationTest.php):
| toda rota fora da lista publica explicita precisa de
|   - autenticacao COM guard explicito (auth:web ou auth:customer),
|   - revalidacao da conta (staff.active ou customer.active),
|   - e uma permissao ('can:habilidade' ou 'can:politica,registro').
| Rota nova sem isso quebra o CI.
|
| Duas contas, dois guards: equipe (web, tabela users, /painel) e clientes
| (customer, tabela customers, /entrar e /minha-conta). Uma sessao de um
| nunca abre a area do outro.
|
*/

// --- Publico ----------------------------------------------------------------
Route::get('/', HomeController::class)->name('home');

// --- Agendamento pelo site (Fase 5): sem login ate a confirmacao ---------------
// So LEITURA (servicos, profissionais, horarios livres calculados pelo
// servidor). Reservar exige conta: account.booking.*.
Route::middleware('no-store')->group(function () {
    Route::get('/agendar', [SiteBookingController::class, 'services'])->name('booking.services');
    Route::get('/agendar/{service:slug}', [SiteBookingController::class, 'professional'])->name('booking.professional');
    Route::get('/agendar/{service:slug}/horarios', [SiteBookingController::class, 'slots'])->name('booking.slots');
});

// --- Clientes: acesso -------------------------------------------------------
Route::middleware(['guest:customer', 'no-store'])->group(function () {
    Route::get('/entrar', [CustomerLoginController::class, 'create'])->name('customer.login');
    Route::post('/entrar', [CustomerLoginController::class, 'store'])
        ->middleware('throttle:login')->name('customer.login.attempt');

    Route::get('/cadastro', [RegisterController::class, 'create'])->name('customer.register');
    Route::post('/cadastro', [RegisterController::class, 'store'])
        ->middleware('throttle:registration')->name('customer.register.store');

    Route::get('/entrar/link', [MagicLinkController::class, 'create'])->name('customer.magic.request');
    Route::post('/entrar/link', [MagicLinkController::class, 'store'])
        ->middleware('throttle:email-requests')->name('customer.magic.send');
    Route::get('/entrar/link/{token}', [MagicLinkController::class, 'show'])
        ->middleware('throttle:token-use')->name('customer.magic.show');
    Route::post('/entrar/link/{token}', [MagicLinkController::class, 'consume'])
        ->middleware('throttle:token-use')->name('customer.magic.consume');

    Route::get('/esqueci-a-senha', [CustomerForgotPasswordController::class, 'create'])->name('customer.password.request');
    Route::post('/esqueci-a-senha', [CustomerForgotPasswordController::class, 'store'])
        ->middleware('throttle:email-requests')->name('customer.password.email');
    Route::get('/redefinir-senha/{token}', [CustomerResetPasswordController::class, 'create'])->name('customer.password.reset');
    Route::post('/redefinir-senha', [CustomerResetPasswordController::class, 'store'])
        ->middleware('throttle:token-use')->name('customer.password.update');
});

// Link do e-mail de confirmacao: assinado, com validade, sem exigir login
// (a pessoa pode abrir em outro aparelho). So confirma; nao faz login.
Route::get('/confirmar-email/{customer:public_id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:token-use', 'no-store'])
    ->name('customer.verification.verify');

// --- Clientes: area autenticada --------------------------------------------
Route::prefix('minha-conta')
    ->name('account.')
    ->middleware(['auth:customer', 'customer.active', 'auth.session', 'no-store', 'can:account.access'])
    ->group(function () {
        Route::post('/sair', [CustomerLoginController::class, 'destroy'])->name('logout');

        Route::get('/completar-cadastro', [CompleteProfileController::class, 'edit'])->name('complete.edit');
        Route::put('/completar-cadastro', [CompleteProfileController::class, 'update'])->name('complete.update');

        Route::middleware('customer.complete')->group(function () {
            Route::get('/', AccountHomeController::class)->name('home');
            Route::get('/dados', [AccountProfileController::class, 'edit'])->name('profile.edit');
            Route::put('/dados', [AccountProfileController::class, 'update'])->name('profile.update');
            Route::get('/senha', [AccountPasswordController::class, 'edit'])->name('password.edit');
            Route::put('/senha', [AccountPasswordController::class, 'update'])
                ->middleware('throttle:password-check')->name('password.update');

            // Agendar (Fase 5): confirmacao e reserva, sempre revalidadas no servidor.
            Route::get('/agendar/confirmar', [AccountBookingController::class, 'confirm'])->name('booking.confirm');
            Route::post('/agendamentos', [AccountBookingController::class, 'store'])
                ->middleware('throttle:booking')->name('booking.store');

            Route::get('/agendamentos/{appointment:code}', [AccountAppointmentController::class, 'show'])
                ->middleware('can:view,appointment')->name('appointments.show');
            Route::post('/agendamentos/{appointment:code}/cancelar', [AccountAppointmentController::class, 'cancel'])
                ->middleware(['can:cancel,appointment', 'throttle:booking'])->name('appointments.cancel');
            Route::get('/agendamentos/{appointment:code}/remarcar', [AccountAppointmentController::class, 'editReschedule'])
                ->middleware('can:reschedule,appointment')->name('appointments.reschedule');
            Route::put('/agendamentos/{appointment:code}/remarcar', [AccountAppointmentController::class, 'reschedule'])
                ->middleware(['can:reschedule,appointment', 'throttle:booking'])->name('appointments.reschedule.update');
        });
    });

// --- Equipe: acesso ---------------------------------------------------------
Route::prefix('painel')->middleware(['guest:web', 'no-store'])->group(function () {
    Route::get('/entrar', [StaffLoginController::class, 'create'])->name('staff.login');
    Route::post('/entrar', [StaffLoginController::class, 'store'])
        ->middleware('throttle:login')->name('staff.login.attempt');

    Route::get('/esqueci-a-senha', [StaffForgotPasswordController::class, 'create'])->name('staff.password.request');
    Route::post('/esqueci-a-senha', [StaffForgotPasswordController::class, 'store'])
        ->middleware('throttle:email-requests')->name('staff.password.email');
    Route::get('/redefinir-senha/{token}', [StaffResetPasswordController::class, 'create'])->name('staff.password.reset');
    Route::post('/redefinir-senha', [StaffResetPasswordController::class, 'store'])
        ->middleware('throttle:token-use')->name('staff.password.update');
});

Route::post('/painel/sair', [StaffLoginController::class, 'destroy'])
    ->middleware(['auth:web', 'no-store'])
    ->name('staff.logout');

// --- Painel da equipe (autenticado + ativo + senha em dia + permissao) ------
Route::prefix('painel')
    ->name('panel.')
    ->middleware(['auth:web', 'staff.active', 'auth.session', 'no-store', 'staff.password', 'can:panel.access'])
    ->group(function () {
        Route::get('/', DashboardController::class)->name('home');

        // Conta propria (qualquer pessoa da equipe).
        Route::get('/minha-conta', [PanelAccountController::class, 'edit'])->name('account.edit');
        Route::put('/minha-conta', [PanelAccountController::class, 'update'])->name('account.update');
        Route::get('/minha-conta/senha', [PanelPasswordController::class, 'edit'])->name('password.edit');
        Route::put('/minha-conta/senha', [PanelPasswordController::class, 'update'])
            ->middleware('throttle:password-check')->name('password.update');

        // Reconfirmar a senha antes de acoes sensiveis (15 min).
        Route::get('/confirmar-senha', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
        Route::post('/confirmar-senha', [ConfirmPasswordController::class, 'store'])
            ->middleware('throttle:password-check')->name('password.confirm.store');

        // Usuarios da equipe (proprietario), com senha reconfirmada.
        Route::middleware(['can:users.manage', 'password.confirm:panel.password.confirm'])->group(function () {
            Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
            Route::get('/usuarios/novo', [UserController::class, 'create'])->name('users.create');
            Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
            Route::get('/usuarios/{user}/editar', [UserController::class, 'edit'])
                ->middleware('can:update,user')->name('users.edit');
            Route::put('/usuarios/{user}', [UserController::class, 'update'])
                ->middleware('can:update,user')->name('users.update');
            Route::post('/usuarios/{user}/senha-provisoria', [UserController::class, 'temporaryPassword'])
                ->middleware('can:setTemporaryPassword,user')->name('users.temporary-password');
        });

        // --- Agenda (Fase 5) ---
        // Consultar (agenda.view; todos ou so os proprios conforme
        // appointments.view_all/view_own) e separado de configurar (schedule.*).
        Route::get('/agenda', [AgendaController::class, 'index'])->middleware('can:agenda.view')->name('agenda');
        Route::get('/agenda/novo', [PanelAppointmentController::class, 'create'])->middleware('can:agenda.view')->name('appointments.create');
        Route::post('/agenda', [PanelAppointmentController::class, 'store'])->middleware(['can:agenda.view', 'throttle:booking'])->name('appointments.store');
        Route::get('/agendamentos/{appointment:code}', [PanelAppointmentController::class, 'show'])->middleware('can:view,appointment')->name('appointments.show');
        Route::put('/agendamentos/{appointment:code}/observacoes', [PanelAppointmentController::class, 'updateNotes'])->middleware('can:update,appointment')->name('appointments.notes');
        Route::post('/agendamentos/{appointment:code}/confirmar', [PanelAppointmentController::class, 'confirm'])->middleware('can:update,appointment')->name('appointments.confirm');
        Route::post('/agendamentos/{appointment:code}/falta', [PanelAppointmentController::class, 'noShow'])->middleware('can:update,appointment')->name('appointments.no-show');
        Route::post('/agendamentos/{appointment:code}/cancelar', [PanelAppointmentController::class, 'cancel'])->middleware('can:cancel,appointment')->name('appointments.cancel');
        Route::get('/agendamentos/{appointment:code}/remarcar', [PanelAppointmentController::class, 'editReschedule'])->middleware('can:reschedule,appointment')->name('appointments.reschedule');
        Route::put('/agendamentos/{appointment:code}/remarcar', [PanelAppointmentController::class, 'reschedule'])->middleware(['can:reschedule,appointment', 'throttle:booking'])->name('appointments.reschedule.update');

        Route::get('/agenda/configuracoes', [ScheduleSettingsController::class, 'edit'])->middleware('can:schedule.settings')->name('schedule.settings');
        Route::put('/agenda/configuracoes', [ScheduleSettingsController::class, 'update'])->middleware('can:schedule.settings')->name('schedule.settings.update');
        Route::get('/profissionais/{professional}/expediente', [WorkingHoursController::class, 'edit'])->middleware('can:schedule.working_hours')->name('schedule.working-hours');
        Route::put('/profissionais/{professional}/expediente', [WorkingHoursController::class, 'update'])->middleware('can:schedule.working_hours')->name('schedule.working-hours.update');
        Route::post('/profissionais/{professional}/pausas', [WorkingHoursController::class, 'storeBreak'])->middleware('can:schedule.working_hours')->name('schedule.breaks.store');
        Route::delete('/profissionais/{professional}/pausas/{break}', [WorkingHoursController::class, 'destroyBreak'])->middleware('can:schedule.working_hours')->name('schedule.breaks.destroy');
        Route::get('/folgas', [TimeOffController::class, 'index'])->middleware('can:schedule.time_off')->name('time-off.index');
        Route::post('/folgas', [TimeOffController::class, 'store'])->middleware('can:schedule.time_off')->name('time-off.store');
        Route::delete('/folgas/{timeOff}', [TimeOffController::class, 'destroy'])->middleware('can:schedule.time_off')->name('time-off.destroy');
        Route::get('/bloqueios', [BlockController::class, 'index'])->middleware('can:schedule.blocks')->name('blocks.index');
        Route::post('/bloqueios', [BlockController::class, 'store'])->middleware('can:schedule.blocks')->name('blocks.store');
        Route::delete('/bloqueios/{block}', [BlockController::class, 'destroy'])->middleware('can:schedule.blocks')->name('blocks.destroy');

        // --- Catalogo: categorias e servicos (Fase 4) ---
        // Cada acao com a sua habilidade: ver, criar, editar, ativar/desativar,
        // ordem/exibicao. Preco tem habilidade propria, conferida no
        // ServiceRequest (campo). Excluir: policy (so se nunca foi usado).
        Route::get('/categorias', [CategoryController::class, 'index'])->middleware('can:services.view')->name('categories.index');
        Route::get('/categorias/nova', [CategoryController::class, 'create'])->middleware('can:services.create')->name('categories.create');
        Route::post('/categorias', [CategoryController::class, 'store'])->middleware('can:services.create')->name('categories.store');
        Route::get('/categorias/{category}/editar', [CategoryController::class, 'edit'])->middleware('can:services.update')->name('categories.edit');
        Route::put('/categorias/{category}', [CategoryController::class, 'update'])->middleware('can:services.update')->name('categories.update');
        Route::post('/categorias/{category}/situacao', [CategoryController::class, 'status'])->middleware('can:services.toggle')->name('categories.status');
        Route::post('/categorias/{category}/ordem', [CategoryController::class, 'move'])->middleware('can:services.display')->name('categories.move');
        Route::delete('/categorias/{category}', [CategoryController::class, 'destroy'])->middleware('can:delete,category')->name('categories.destroy');

        Route::get('/servicos', [ServiceController::class, 'index'])->middleware('can:services.view')->name('services.index');
        Route::get('/servicos/novo', [ServiceController::class, 'create'])->middleware('can:services.create')->name('services.create');
        Route::post('/servicos', [ServiceController::class, 'store'])->middleware('can:services.create')->name('services.store');
        Route::get('/servicos/{service}/editar', [ServiceController::class, 'edit'])->middleware('can:services.update')->name('services.edit');
        Route::put('/servicos/{service}', [ServiceController::class, 'update'])->middleware('can:services.update')->name('services.update');
        Route::post('/servicos/{service}/situacao', [ServiceController::class, 'status'])->middleware('can:services.toggle')->name('services.status');
        Route::post('/servicos/{service}/ordem', [ServiceController::class, 'move'])->middleware('can:services.display')->name('services.move');
        Route::delete('/servicos/{service}', [ServiceController::class, 'destroy'])->middleware('can:delete,service')->name('services.destroy');

        // --- Equipe profissional (Fase 4) ---
        Route::get('/profissionais', [ProfessionalController::class, 'index'])->middleware('can:professionals.view')->name('professionals.index');
        Route::get('/profissionais/novo', [ProfessionalController::class, 'create'])->middleware('can:professionals.create')->name('professionals.create');
        Route::post('/profissionais', [ProfessionalController::class, 'store'])->middleware('can:professionals.create')->name('professionals.store');
        // Ficha: a propria (profissional) ou qualquer uma (professionals.view); outra = 404.
        Route::get('/profissionais/{professional}', [ProfessionalController::class, 'show'])->middleware('can:view,professional')->name('professionals.show');
        Route::get('/profissionais/{professional}/editar', [ProfessionalController::class, 'edit'])->middleware('can:professionals.update')->name('professionals.edit');
        Route::put('/profissionais/{professional}', [ProfessionalController::class, 'update'])->middleware('can:professionals.update')->name('professionals.update');
        Route::post('/profissionais/{professional}/situacao', [ProfessionalController::class, 'status'])->middleware('can:professionals.toggle')->name('professionals.status');
        Route::post('/profissionais/{professional}/ordem', [ProfessionalController::class, 'move'])->middleware('can:professionals.display')->name('professionals.move');
        Route::get('/profissionais/{professional}/servicos', [ProfessionalController::class, 'editServices'])->middleware('can:professionals.services')->name('professionals.services.edit');
        Route::put('/profissionais/{professional}/servicos', [ProfessionalController::class, 'updateServices'])->middleware('can:professionals.services')->name('professionals.services.update');

        Route::get('/auditoria', [AuditLogController::class, 'index'])
            ->middleware('can:audit.view')->name('audit.index');
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
