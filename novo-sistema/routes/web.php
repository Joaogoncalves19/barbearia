<?php

use App\Http\Controllers\Account\AccountHomeController;
use App\Http\Controllers\Account\AccountPasswordController;
use App\Http\Controllers\Account\AppointmentController as AccountAppointmentController;
use App\Http\Controllers\Account\BookingController as AccountBookingController;
use App\Http\Controllers\Account\CompleteProfileController;
use App\Http\Controllers\Account\ConfirmPasswordController as AccountConfirmPasswordController;
use App\Http\Controllers\Account\EmailController as AccountEmailController;
use App\Http\Controllers\Account\LoyaltyController as AccountLoyaltyController;
use App\Http\Controllers\Account\NotificationController as AccountNotificationController;
use App\Http\Controllers\Account\PreferencesController as AccountPreferencesController;
use App\Http\Controllers\Account\PrivacyController as AccountPrivacyController;
use App\Http\Controllers\Account\ProfileController as AccountProfileController;
use App\Http\Controllers\Account\ReceiptController as AccountReceiptController;
use App\Http\Controllers\Account\ReviewController as AccountReviewController;
use App\Http\Controllers\Account\SubscriptionController as AccountSubscriptionController;
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
use App\Http\Controllers\Panel\Catalog\ProductController;
use App\Http\Controllers\Panel\Catalog\ServiceController;
use App\Http\Controllers\Panel\Catalog\StockController;
use App\Http\Controllers\Panel\Checkout\AttendanceController;
use App\Http\Controllers\Panel\Checkout\CashController;
use App\Http\Controllers\Panel\Communication\CampaignController;
use App\Http\Controllers\Panel\Communication\EmailLogController;
use App\Http\Controllers\Panel\Communication\ReviewController as PanelReviewController;
use App\Http\Controllers\Panel\Communication\SettingsController as CommunicationSettingsController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\Finance\AdvanceController;
use App\Http\Controllers\Panel\Finance\CommissionController;
use App\Http\Controllers\Panel\Finance\CommissionRuleController;
use App\Http\Controllers\Panel\Finance\PayoutController;
use App\Http\Controllers\Panel\PasswordController as PanelPasswordController;
use App\Http\Controllers\Panel\Promotions\CouponController;
use App\Http\Controllers\Panel\Promotions\CustomerLoyaltyController;
use App\Http\Controllers\Panel\Promotions\GiftCardController;
use App\Http\Controllers\Panel\Promotions\PromotionSettingsController;
use App\Http\Controllers\Panel\ReceiptController as PanelReceiptController;
use App\Http\Controllers\Panel\Site\AppearanceController;
use App\Http\Controllers\Panel\Site\SiteContentController;
use App\Http\Controllers\Panel\Subscriptions\PlanController;
use App\Http\Controllers\Panel\Subscriptions\SubscriptionController as PanelSubscriptionController;
use App\Http\Controllers\Panel\Team\ProfessionalController;
use App\Http\Controllers\Panel\UserController;
use App\Http\Controllers\Professional\AgendaController as ProAgendaController;
use App\Http\Controllers\Professional\AppointmentController as ProAppointmentController;
use App\Http\Controllers\Professional\AttendanceController as ProAttendanceController;
use App\Http\Controllers\Professional\CustomerNoteController as ProCustomerNoteController;
use App\Http\Controllers\Professional\EarningsController as ProEarningsController;
use App\Http\Controllers\Professional\ProfileController as ProProfileController;
use App\Http\Controllers\Professional\TodayController as ProTodayController;
use App\Http\Controllers\Prototypes\PrototypeController;
use App\Http\Controllers\Site\BookingController as SiteBookingController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PagesController;
use App\Http\Controllers\Site\PresenceController;
use App\Http\Controllers\Site\UnsubscribeController;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Reviews\Models\Review;
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
// Site publico (Fase 11): so leitura do conteudo real; nada aqui grava.
Route::get('/', HomeController::class)->name('home');
Route::get('/servicos', [PagesController::class, 'services'])->name('site.services');
Route::get('/equipe', [PagesController::class, 'team'])->name('site.team');
Route::get('/equipe/{professional:slug}', [PagesController::class, 'professional'])->where('professional', '[a-z0-9-]+')->name('site.professional');
Route::get('/assinatura', [PagesController::class, 'plans'])->name('site.plans');
Route::get('/privacidade', [PagesController::class, 'privacy'])->name('site.privacy');
Route::get('/termos', [PagesController::class, 'terms'])->name('site.terms');
Route::get('/sitemap.xml', [PagesController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [PagesController::class, 'robots'])->name('robots');

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

// --- Links dos e-mails (Fase 10): assinados, sem login ----------------------
// Abrir (GET) so mostra; a acao e um POST. Presenca: link com validade ate o
// horario (lembretes.md §4). Descadastro: so o marketing; o POST tambem
// atende o "um clique" do leitor de e-mail (RFC 8058, sem CSRF: a
// assinatura e a garantia; consentimento.md §3).
Route::middleware(['signed', 'throttle:email-links', 'no-store'])->group(function () {
    Route::get('/presenca/{appointment:code}', [PresenceController::class, 'show'])->name('presence.show');
    Route::post('/presenca/{appointment:code}', [PresenceController::class, 'store'])->name('presence.store');
    Route::get('/descadastro/{customer:public_id}', [UnsubscribeController::class, 'show'])->name('unsubscribe.show');
    Route::post('/descadastro/{customer:public_id}', [UnsubscribeController::class, 'store'])->name('unsubscribe.store');
});

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
            Route::get('/agendar/confirmar', [AccountBookingController::class, 'confirm'])->middleware('throttle:coupon-check')->name('booking.confirm');
            Route::post('/agendamentos', [AccountBookingController::class, 'store'])
                ->middleware('throttle:booking')->name('booking.store');

            // Fase 12: lista de agendamentos (proximos e historico) e de comprovantes.
            Route::get('/agendamentos', [AccountAppointmentController::class, 'index'])->name('appointments.index');
            Route::get('/comprovantes', [AccountReceiptController::class, 'index'])->name('receipts.index');
            Route::get('/agendamentos/{appointment:code}', [AccountAppointmentController::class, 'show'])
                ->middleware('can:view,appointment')->name('appointments.show');
            Route::post('/agendamentos/{appointment:code}/cancelar', [AccountAppointmentController::class, 'cancel'])
                ->middleware(['can:cancel,appointment', 'throttle:booking'])->name('appointments.cancel');
            Route::get('/agendamentos/{appointment:code}/remarcar', [AccountAppointmentController::class, 'editReschedule'])
                ->middleware('can:reschedule,appointment')->name('appointments.reschedule');
            Route::put('/agendamentos/{appointment:code}/remarcar', [AccountAppointmentController::class, 'reschedule'])
                ->middleware(['can:reschedule,appointment', 'throttle:booking'])->name('appointments.reschedule.update');

            // Comprovante do atendimento concluido (Fase 6): so o proprio; alheio = 404.
            Route::get('/atendimentos/{attendance}', [AccountAppointmentController::class, 'receipt'])
                ->middleware('can:view,attendance')->name('attendances.show');
            // Fase 8: versao para imprimir e envio para o e-mail da propria conta.
            Route::get('/atendimentos/{attendance}/imprimir', [AccountReceiptController::class, 'show'])
                ->middleware('can:view,attendance')->name('attendances.print');
            Route::post('/atendimentos/{attendance}/enviar', [AccountReceiptController::class, 'email'])
                ->middleware(['can:view,attendance', 'throttle:receipts'])->name('attendances.email');
            // Fidelidade e indicacao (Fase 8): saldo, extrato, regras e o proprio codigo.
            Route::get('/fidelidade', [AccountLoyaltyController::class, 'show'])->name('loyalty');
            Route::post('/fidelidade/codigo', [AccountLoyaltyController::class, 'generateCode'])->middleware('throttle:booking')->name('loyalty.code');
            // Assinatura (Fase 9): so a propria; cancelar a renovacao e desfazer.
            Route::get('/assinatura', [AccountSubscriptionController::class, 'show'])->name('subscription');
            Route::post('/assinatura/cancelar', [AccountSubscriptionController::class, 'cancel'])->middleware('throttle:booking')->name('subscription.cancel');
            Route::post('/assinatura/reativar', [AccountSubscriptionController::class, 'reactivate'])->middleware('throttle:booking')->name('subscription.reactivate');
            // Comunicacao (Fase 10): preferencias de e-mail, avisos e avaliacoes.
            // Avaliar: so o proprio atendimento concluido (Policy; alheio = 404).
            Route::put('/preferencias', [AccountPreferencesController::class, 'update'])->middleware('throttle:account-actions')->name('preferences.update');
            Route::get('/avisos', [AccountNotificationController::class, 'index'])->name('notifications');
            Route::post('/avisos/lidos', [AccountNotificationController::class, 'markRead'])->middleware('throttle:account-actions')->name('notifications.read');
            Route::get('/avaliacoes', [AccountReviewController::class, 'index'])->name('reviews.index');
            Route::get('/avaliacoes/{attendance}', [AccountReviewController::class, 'create'])->middleware('can:view,attendance')->name('reviews.create');
            Route::post('/avaliacoes/{attendance}', [AccountReviewController::class, 'store'])->middleware(['can:view,attendance', 'throttle:account-actions'])->name('reviews.store');

            // Fase 12: acoes sensiveis pedem a senha de novo (customer.reauth).
            Route::get('/confirmar-senha', [AccountConfirmPasswordController::class, 'show'])->name('confirm.show');
            Route::post('/confirmar-senha', [AccountConfirmPasswordController::class, 'store'])->middleware('throttle:password-check')->name('confirm.store');
            // Privacidade (LGPD): consentimentos, exportar os dados, excluir a conta.
            Route::get('/privacidade', [AccountPrivacyController::class, 'show'])->name('privacy');
            Route::post('/privacidade/exportar', [AccountPrivacyController::class, 'export'])->middleware(['customer.reauth', 'throttle:data-export'])->name('privacy.export');
            Route::get('/encerrar-conta', [AccountPrivacyController::class, 'confirmClose'])->middleware('customer.reauth')->name('close');
            Route::delete('/encerrar-conta', [AccountPrivacyController::class, 'destroy'])->middleware(['customer.reauth', 'throttle:account-actions'])->name('close.destroy');
            // Troca de e-mail: so vale depois de confirmada pelo link enviado ao endereco novo.
            Route::get('/email', [AccountEmailController::class, 'edit'])->middleware('customer.reauth')->name('email.edit');
            Route::put('/email', [AccountEmailController::class, 'update'])->middleware(['customer.reauth', 'throttle:email-requests'])->name('email.update');
            Route::delete('/email/pedido', [AccountEmailController::class, 'cancel'])->middleware('throttle:account-actions')->name('email.cancel');
            Route::get('/email/confirmar/{token}', [AccountEmailController::class, 'show'])->middleware('throttle:token-use')->where('token', '[A-Za-z0-9]{64}')->name('email.confirm.show');
            Route::post('/email/confirmar/{token}', [AccountEmailController::class, 'confirm'])->middleware('throttle:token-use')->where('token', '[A-Za-z0-9]{64}')->name('email.confirm');
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
    ->middleware(['auth:web', 'staff.active', 'auth.session', 'no-store', 'staff.password', 'can:panel.access', 'professional.area'])
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

        // --- Atendimento (Fase 6) ---
        // Lista e encaixe: attendances.* (o profissional so os proprios). Cada
        // acao num atendimento passa pela AttendancePolicy do registro;
        // alheio = 404. Operacoes de dinheiro/estoque com throttle:money.
        Route::get('/atendimentos', [AttendanceController::class, 'index'])->middleware('can:viewAny,'.Attendance::class)->name('attendances.index');
        Route::get('/atendimentos/novo', [AttendanceController::class, 'create'])->middleware('can:create,'.Attendance::class)->name('attendances.create');
        Route::post('/atendimentos', [AttendanceController::class, 'store'])->middleware('can:create,'.Attendance::class)->name('attendances.store');
        Route::post('/agendamentos/{appointment:code}/atendimento', [AttendanceController::class, 'openFromAppointment'])->middleware('can:view,appointment')->name('attendances.open');
        Route::get('/atendimentos/{attendance}', [AttendanceController::class, 'show'])->middleware('can:view,attendance')->name('attendances.show');
        Route::post('/atendimentos/{attendance}/iniciar', [AttendanceController::class, 'start'])->middleware('can:update,attendance')->name('attendances.start');
        Route::post('/atendimentos/{attendance}/servicos', [AttendanceController::class, 'addService'])->middleware('can:update,attendance')->name('attendances.services.store');
        Route::post('/atendimentos/{attendance}/produtos', [AttendanceController::class, 'addProduct'])->middleware('can:update,attendance')->name('attendances.products.store');
        Route::delete('/atendimentos/{attendance}/itens/{item}', [AttendanceController::class, 'removeItem'])->middleware('can:update,attendance')->name('attendances.items.destroy');
        Route::post('/atendimentos/{attendance}/consumos', [AttendanceController::class, 'addConsumption'])->middleware('can:update,attendance')->name('attendances.consumptions.store');
        Route::delete('/atendimentos/{attendance}/consumos/{consumption}', [AttendanceController::class, 'removeConsumption'])->middleware('can:update,attendance')->name('attendances.consumptions.destroy');
        Route::post('/atendimentos/{attendance}/desconto', [AttendanceController::class, 'applyDiscount'])->middleware('can:discount,attendance')->name('attendances.discount.store');
        Route::delete('/atendimentos/{attendance}/desconto/{discount}', [AttendanceController::class, 'removeDiscount'])->middleware('can:discount,attendance')->name('attendances.discount.destroy');
        Route::put('/atendimentos/{attendance}/profissional', [AttendanceController::class, 'changeProfessional'])->middleware('can:update,attendance')->name('attendances.professional');
        Route::put('/atendimentos/{attendance}/observacoes', [AttendanceController::class, 'updateNotes'])->middleware('can:update,attendance')->name('attendances.notes');
        Route::post('/atendimentos/{attendance}/concluir', [AttendanceController::class, 'complete'])->middleware(['can:complete,attendance', 'throttle:money'])->name('attendances.complete');
        Route::post('/atendimentos/{attendance}/cancelar', [AttendanceController::class, 'cancel'])->middleware('can:cancel,attendance')->name('attendances.cancel');
        Route::post('/atendimentos/{attendance}/pagamentos/{payment}/estorno', [AttendanceController::class, 'refund'])->middleware(['can:view,attendance', 'can:payments.refund', 'throttle:money'])->name('attendances.refund');
        Route::post('/atendimentos/{attendance}/estoque/{movement}/devolver', [AttendanceController::class, 'returnToStock'])->middleware(['can:view,attendance', 'can:stock.adjust', 'throttle:money'])->name('attendances.return-stock');

        // --- Caixa (Fase 6) ---
        Route::get('/caixa', [CashController::class, 'index'])->middleware('can:cash.view')->name('cash.index');
        Route::post('/caixa', [CashController::class, 'open'])->middleware(['can:cash.open', 'throttle:money'])->name('cash.open');
        Route::post('/caixa/movimentos', [CashController::class, 'move'])->middleware(['can:cash.move', 'throttle:money'])->name('cash.move');
        Route::get('/caixa/{session}', [CashController::class, 'show'])->middleware('can:cash.view')->name('cash.show');
        Route::post('/caixa/{session}/fechar', [CashController::class, 'close'])->middleware(['can:cash.close', 'throttle:money'])->name('cash.close');

        // --- Produtos e estoque (Fase 6) ---
        // O saldo nunca e editado: so muda por movimentacao (entrada, saida,
        // ajuste de inventario, estorno), cada uma com a sua habilidade.
        Route::get('/produtos', [ProductController::class, 'index'])->middleware('can:products.view')->name('products.index');
        Route::get('/produtos/novo', [ProductController::class, 'create'])->middleware('can:products.create')->name('products.create');
        Route::post('/produtos', [ProductController::class, 'store'])->middleware('can:products.create')->name('products.store');
        Route::get('/produtos/{product}/editar', [ProductController::class, 'edit'])->middleware('can:products.update')->name('products.edit');
        Route::put('/produtos/{product}', [ProductController::class, 'update'])->middleware('can:products.update')->name('products.update');
        Route::post('/produtos/{product}/situacao', [ProductController::class, 'setStatus'])->middleware('can:products.toggle')->name('products.status');
        Route::delete('/produtos/{product}', [ProductController::class, 'destroy'])->middleware('can:products.toggle')->name('products.destroy');
        Route::get('/produtos/{product}/estoque', [StockController::class, 'show'])->middleware('can:stock.view')->name('stock.show');
        Route::post('/produtos/{product}/estoque/entrada', [StockController::class, 'receive'])->middleware(['can:stock.receive', 'throttle:money'])->name('stock.receive');
        Route::post('/produtos/{product}/estoque/saida', [StockController::class, 'issue'])->middleware(['can:stock.issue', 'throttle:money'])->name('stock.issue');
        Route::post('/produtos/{product}/estoque/ajuste', [StockController::class, 'adjust'])->middleware(['can:stock.adjust', 'throttle:money'])->name('stock.adjust');
        Route::post('/produtos/{product}/estoque/{movement}/estorno', [StockController::class, 'reverse'])->middleware(['can:stock.adjust', 'throttle:money'])->name('stock.reverse');

        // --- Comissao, gorjeta, vales e repasse (Fase 7) ---
        // Cada acao tem a sua habilidade (ver, configurar, corrigir, pagar,
        // estornar). Extrato: commissions.view (todos) ou o proprio
        // (viewLedger); de outro = 404. Dinheiro com throttle:money.
        Route::get('/comissoes', [CommissionController::class, 'index'])->middleware('can:commissions.view')->name('commissions.index');
        Route::get('/minhas-comissoes', [CommissionController::class, 'mine'])->middleware('can:commissions.view_own')->name('commissions.mine');
        Route::get('/comissoes/regras', [CommissionRuleController::class, 'index'])->middleware('can:commissions.configure')->name('commission-rules.index');
        Route::post('/comissoes/regras', [CommissionRuleController::class, 'store'])->middleware(['can:commissions.configure', 'throttle:money'])->name('commission-rules.store');
        Route::post('/comissoes/regras/encerrar', [CommissionRuleController::class, 'clear'])->middleware(['can:commissions.configure', 'throttle:money'])->name('commission-rules.clear');
        Route::get('/comissoes/historico', [CommissionRuleController::class, 'history'])->middleware('can:commissions.history')->name('commissions.history');
        Route::get('/comissoes/profissionais/{professional}', [CommissionController::class, 'show'])->middleware('can:viewLedger,professional')->name('commissions.show');
        Route::post('/comissoes/profissionais/{professional}/ajustes', [CommissionController::class, 'adjust'])->middleware(['can:commissions.correct', 'throttle:money'])->name('commissions.adjust');
        Route::get('/comissoes/profissionais/{professional}/repasse', [PayoutController::class, 'create'])->middleware('can:payouts.create')->name('payouts.create');
        Route::post('/comissoes/profissionais/{professional}/repasse', [PayoutController::class, 'store'])->middleware(['can:payouts.create', 'throttle:money'])->name('payouts.store');
        Route::post('/comissoes/profissionais/{professional}/vales', [AdvanceController::class, 'store'])->middleware(['can:advances.create', 'throttle:money'])->name('advances.store');
        Route::post('/vales/{advance}/estorno', [AdvanceController::class, 'reverse'])->middleware(['can:advances.reverse', 'throttle:money'])->name('advances.reverse');
        Route::get('/repasses', [PayoutController::class, 'index'])->middleware('can:payouts.view')->name('payouts.index');
        Route::get('/repasses/{payout}', [PayoutController::class, 'show'])->middleware('can:view,payout')->name('payouts.show');
        Route::post('/repasses/{payout}/estorno', [PayoutController::class, 'reverse'])->middleware(['can:payouts.reverse', 'throttle:money'])->name('payouts.reverse');

        // --- Promocoes, fidelidade e vale-presente (Fase 8) ---
        Route::get('/cupons', [CouponController::class, 'index'])->middleware('can:coupons.view')->name('coupons.index');
        Route::get('/cupons/novo', [CouponController::class, 'create'])->middleware('can:coupons.manage')->name('coupons.create');
        Route::post('/cupons', [CouponController::class, 'store'])->middleware('can:coupons.manage')->name('coupons.store');
        Route::get('/cupons/{coupon}/editar', [CouponController::class, 'edit'])->middleware('can:coupons.manage')->name('coupons.edit');
        Route::put('/cupons/{coupon}', [CouponController::class, 'update'])->middleware('can:coupons.manage')->name('coupons.update');
        Route::post('/cupons/{coupon}/situacao', [CouponController::class, 'setStatus'])->middleware('can:coupons.manage')->name('coupons.status');
        Route::get('/fidelidade', [PromotionSettingsController::class, 'edit'])->middleware('can:promotions.configure')->name('promotions.settings');
        Route::put('/fidelidade', [PromotionSettingsController::class, 'update'])->middleware(['can:promotions.configure', 'throttle:money'])->name('promotions.settings.update');
        Route::get('/fidelidade/clientes', [CustomerLoyaltyController::class, 'index'])->middleware('can:loyalty.view')->name('loyalty.customers');
        Route::get('/fidelidade/clientes/{customer:public_id}', [CustomerLoyaltyController::class, 'show'])->middleware('can:loyalty.view')->name('loyalty.customer');
        Route::post('/fidelidade/clientes/{customer:public_id}/ajuste', [CustomerLoyaltyController::class, 'adjust'])->middleware(['can:loyalty.adjust', 'throttle:money'])->name('loyalty.adjust');
        Route::post('/atendimentos/{attendance}/promocao', [AttendanceController::class, 'applyPromotion'])->middleware(['can:update,attendance', 'can:promotions.apply', 'throttle:money'])->name('attendances.promotion');
        Route::get('/vales-presente', [GiftCardController::class, 'index'])->middleware('can:gift_cards.view')->name('gift-cards.index');
        Route::get('/vales-presente/novo', [GiftCardController::class, 'create'])->middleware('can:gift_cards.sell')->name('gift-cards.create');
        Route::post('/vales-presente', [GiftCardController::class, 'store'])->middleware(['can:gift_cards.sell', 'throttle:money'])->name('gift-cards.store');
        Route::get('/vales-presente/{giftCard}', [GiftCardController::class, 'show'])->middleware('can:gift_cards.view')->name('gift-cards.show');
        Route::post('/vales-presente/{giftCard}/cancelar', [GiftCardController::class, 'cancel'])->middleware(['can:gift_cards.cancel', 'throttle:money'])->name('gift-cards.cancel');

        // Assinaturas (Fase 9). Uma permissao por acao; o estado so muda pelos servicos.
        Route::get('/assinaturas', [PanelSubscriptionController::class, 'index'])->middleware('can:subscriptions.view')->name('subscriptions.index');
        Route::get('/assinaturas/link', [PanelSubscriptionController::class, 'newLink'])->middleware('can:subscriptions.create')->name('subscriptions.link');
        Route::post('/assinaturas/link', [PanelSubscriptionController::class, 'storeLink'])->middleware(['can:subscriptions.create', 'throttle:money'])->name('subscriptions.link.store');
        Route::get('/assinaturas/eventos', [PanelSubscriptionController::class, 'events'])->middleware('can:subscriptions.history')->name('subscriptions.events');
        Route::post('/assinaturas/pagamentos/{payment}/reembolso', [PanelSubscriptionController::class, 'refund'])->middleware(['can:subscriptions.refund', 'throttle:money'])->name('subscriptions.refund');
        Route::get('/assinaturas/{subscription}', [PanelSubscriptionController::class, 'show'])->middleware('can:subscriptions.view')->name('subscriptions.show');
        Route::post('/assinaturas/{subscription}/cancelar', [PanelSubscriptionController::class, 'cancel'])->middleware(['can:subscriptions.cancel', 'throttle:money'])->name('subscriptions.cancel');
        Route::post('/assinaturas/{subscription}/link/email', [PanelSubscriptionController::class, 'emailLink'])->middleware(['can:subscriptions.create', 'throttle:email-actions'])->name('subscriptions.link.email');
        Route::post('/assinaturas/{subscription}/reativar', [PanelSubscriptionController::class, 'reactivate'])->middleware(['can:subscriptions.reactivate', 'throttle:money'])->name('subscriptions.reactivate');
        Route::get('/planos', [PlanController::class, 'index'])->middleware('can:plans.manage')->name('plans.index');
        Route::get('/planos/novo', [PlanController::class, 'create'])->middleware('can:plans.manage')->name('plans.create');
        Route::post('/planos', [PlanController::class, 'store'])->middleware(['can:plans.manage', 'throttle:money'])->name('plans.store');
        Route::get('/planos/{plan}', [PlanController::class, 'edit'])->middleware('can:plans.manage')->name('plans.edit');
        Route::post('/planos/{plan}/versoes', [PlanController::class, 'storeVersion'])->middleware(['can:plans.manage', 'throttle:money'])->name('plans.versions.store');
        Route::post('/planos/{plan}/situacao', [PlanController::class, 'setStatus'])->middleware(['can:plans.manage', 'throttle:money'])->name('plans.status');

        // --- Comunicacao e avaliacoes (Fase 10) ---
        // Ver nao da direito a moderar/responder; rascunho de campanha nao da
        // direito a disparar; ver o registro nao da direito a reenviar.
        Route::get('/avaliacoes', [PanelReviewController::class, 'index'])->middleware('can:viewAny,'.Review::class)->name('reviews.index');
        Route::post('/avaliacoes/{review}/aprovar', [PanelReviewController::class, 'approve'])->middleware(['can:reviews.moderate', 'throttle:money'])->name('reviews.approve');
        Route::post('/avaliacoes/{review}/recusar', [PanelReviewController::class, 'reject'])->middleware(['can:reviews.moderate', 'throttle:money'])->name('reviews.reject');
        Route::post('/avaliacoes/{review}/destaque', [PanelReviewController::class, 'feature'])->middleware(['can:reviews.moderate', 'throttle:money'])->name('reviews.feature');
        Route::post('/avaliacoes/{review}/resposta', [PanelReviewController::class, 'reply'])->middleware(['can:reviews.reply', 'throttle:money'])->name('reviews.reply');

        Route::get('/campanhas', [CampaignController::class, 'index'])->middleware('can:campaigns.view')->name('campaigns.index');
        Route::get('/campanhas/nova', [CampaignController::class, 'create'])->middleware('can:campaigns.manage')->name('campaigns.create');
        Route::post('/campanhas', [CampaignController::class, 'store'])->middleware(['can:campaigns.manage', 'throttle:money'])->name('campaigns.store');
        Route::get('/campanhas/{campaign}', [CampaignController::class, 'show'])->middleware('can:campaigns.view')->name('campaigns.show');
        Route::get('/campanhas/{campaign}/editar', [CampaignController::class, 'edit'])->middleware('can:campaigns.manage')->name('campaigns.edit');
        Route::put('/campanhas/{campaign}', [CampaignController::class, 'update'])->middleware(['can:campaigns.manage', 'throttle:money'])->name('campaigns.update');
        Route::post('/campanhas/{campaign}/teste', [CampaignController::class, 'test'])->middleware(['can:campaigns.manage', 'throttle:email-actions'])->name('campaigns.test');
        Route::post('/campanhas/{campaign}/disparar', [CampaignController::class, 'start'])->middleware(['can:campaigns.send', 'throttle:money'])->name('campaigns.start');
        Route::post('/campanhas/{campaign}/cancelar', [CampaignController::class, 'cancel'])->middleware(['can:campaigns.send', 'throttle:money'])->name('campaigns.cancel');

        Route::get('/emails', [EmailLogController::class, 'index'])->middleware('can:communications.view')->name('emails.index');
        Route::get('/emails/modelos/{template}', [EmailLogController::class, 'preview'])->middleware('can:communications.view')->name('emails.preview');
        Route::post('/emails/{message}/reenviar', [EmailLogController::class, 'retry'])->middleware(['can:communications.retry', 'throttle:email-actions'])->name('emails.retry');

        Route::get('/comunicacao', [CommunicationSettingsController::class, 'edit'])->middleware('can:communications.settings')->name('communication.settings');
        Route::put('/comunicacao', [CommunicationSettingsController::class, 'update'])->middleware(['can:communications.settings', 'throttle:money'])->name('communication.settings.update');

        // --- Site publico (Fase 11): textos, contatos, paginas legais e imagens ---
        Route::get('/site', [SiteContentController::class, 'edit'])->middleware('can:site.manage')->name('site.content');
        Route::put('/site', [SiteContentController::class, 'update'])->middleware(['can:site.manage', 'throttle:money'])->name('site.content.update');
        Route::get('/site/imagens', [SiteContentController::class, 'images'])->middleware('can:site.manage')->name('site.images');
        Route::post('/site/imagens', [SiteContentController::class, 'storeImage'])->middleware(['can:site.manage', 'throttle:uploads'])->name('site.images.store');
        Route::put('/site/imagens/{image}', [SiteContentController::class, 'updateImage'])->middleware(['can:site.manage', 'throttle:money'])->name('site.images.update');
        Route::post('/site/imagens/{image}/ordem', [SiteContentController::class, 'moveImage'])->middleware(['can:site.manage', 'throttle:money'])->name('site.images.move');
        Route::delete('/site/imagens/{image}', [SiteContentController::class, 'destroyImage'])->middleware(['can:site.manage', 'throttle:money'])->name('site.images.destroy');

        // --- Aparencia (refinamento visual): tema predefinido; so o proprietario ---
        Route::get('/aparencia', [AppearanceController::class, 'index'])->middleware('can:settings.manage')->name('appearance');
        Route::get('/aparencia/previa/{theme}', [AppearanceController::class, 'preview'])->middleware('can:settings.manage')->where('theme', '[a-z-]+')->name('appearance.preview');
        Route::put('/aparencia', [AppearanceController::class, 'update'])->middleware(['can:settings.manage', 'throttle:money'])->name('appearance.update');

        // --- Comprovantes impressos e por e-mail (Fase 8) ---
        // Mesmo acesso da tela do documento; envio com limite proprio.
        Route::get('/atendimentos/{attendance}/comprovante', [PanelReceiptController::class, 'attendance'])->middleware('can:view,attendance')->name('receipts.attendance');
        Route::post('/atendimentos/{attendance}/comprovante/email', [PanelReceiptController::class, 'emailAttendance'])->middleware(['can:view,attendance', 'throttle:receipts'])->name('receipts.attendance.email');
        Route::get('/repasses/{payout}/recibo', [PanelReceiptController::class, 'payout'])->middleware('can:view,payout')->name('receipts.payout');
        Route::post('/repasses/{payout}/recibo/email', [PanelReceiptController::class, 'emailPayout'])->middleware(['can:view,payout', 'throttle:receipts'])->name('receipts.payout.email');
        Route::get('/vales-presente/{giftCard}/imprimir', [PanelReceiptController::class, 'giftCard'])->middleware('can:gift_cards.view')->name('receipts.gift-card');
        Route::post('/vales-presente/{giftCard}/email', [PanelReceiptController::class, 'emailGiftCard'])->middleware(['can:gift_cards.view', 'throttle:receipts'])->name('receipts.gift-card.email');
        Route::get('/caixa/{session}/comprovante', [PanelReceiptController::class, 'cash'])->middleware('can:cash.view')->name('receipts.cash');
        Route::post('/caixa/{session}/comprovante/email', [PanelReceiptController::class, 'emailCash'])->middleware(['can:cash.view', 'throttle:receipts'])->name('receipts.cash.email');

        Route::get('/auditoria', [AuditLogController::class, 'index'])
            ->middleware('can:audit.view')->name('audit.index');
    });

// --- Area do profissional (Fase 12.5) ----------------------------------------
// Hoje, agenda, atendimentos, ganhos e perfil PROPRIOS (painel-profissional.md).
// Exige a habilidade e a ficha ligada ao usuario; o profissional vem sempre do
// usuario logado (nenhuma rota recebe id de profissional). Registro alheio =
// 404 pela Policy. As gravacoes usam as rotas do painel (mesmas regras); aqui
// so as anotacoes do cliente, que nao tinham tela.
// O profissional entra pelo mesmo login da equipe; o endereco "intuitivo" leva para la.
Route::redirect('/profissional/entrar', '/painel/entrar')->name('pro.login');

Route::prefix('profissional')
    ->name('pro.')
    ->middleware(['auth:web', 'staff.active', 'auth.session', 'no-store', 'staff.password', 'can:professional_area.access', 'professional.profile'])
    ->group(function () {
        Route::get('/', ProTodayController::class)->name('today');
        Route::get('/agenda', [ProAgendaController::class, 'index'])->name('agenda');
        Route::get('/agendamentos/{appointment:code}', [ProAppointmentController::class, 'show'])->middleware('can:view,appointment')->name('appointments.show');
        Route::get('/atendimentos', [ProAttendanceController::class, 'index'])->name('attendances');
        Route::get('/atendimentos/{attendance}', [ProAttendanceController::class, 'show'])->middleware('can:view,attendance')->name('attendances.show');
        Route::get('/ganhos', ProEarningsController::class)->middleware('can:commissions.view_own')->name('earnings');
        Route::get('/perfil', ProProfileController::class)->name('profile');
        Route::post('/clientes/{customer:public_id}/anotacoes', [ProCustomerNoteController::class, 'store'])
            ->middleware(['can:notes,customer', 'throttle:account-actions'])->name('customers.notes.store');
        Route::delete('/clientes/{customer:public_id}/anotacoes/{note}', [ProCustomerNoteController::class, 'destroy'])
            ->middleware(['can:notes,customer', 'throttle:account-actions'])->name('customers.notes.destroy');
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
