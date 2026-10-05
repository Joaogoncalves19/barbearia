<?php

namespace App\Providers;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Policies\ServiceCategoryPolicy;
use App\Modules\Catalog\Policies\ServicePolicy;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Policies\AttendancePolicy;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Policies\CustomerPolicy;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Policies\CommissionPayoutPolicy;
use App\Modules\Identity\Authorization\PermissionMatrix;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\UserPolicy;
use App\Modules\Identity\Rules\MaxBytes;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Policies\AppointmentPolicy;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Policies\ProfessionalPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureUrls();
        $this->configureModels();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureQueueFailureLogging();

        // bcrypt so considera os primeiros 72 bytes: acima disso a senha
        // seria truncada em silencio, entao e recusada com mensagem clara.
        Password::defaults(fn () => Password::min(8)->letters()->numbers()->rules([new MaxBytes(72)]));
        Paginator::defaultView('components.ui.pagination');
    }

    /**
     * URLs geradas (links de e-mail, redirecionamentos) usam SEMPRE o APP_URL,
     * nunca o cabecalho Host da requisicao. Corrige na raiz o envenenamento de
     * Host do sistema antigo (achado S-06).
     */
    private function configureUrls(): void
    {
        $appUrl = (string) config('app.url');

        if ($appUrl !== '') {
            URL::useOrigin($appUrl);
        }

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }

    private function configureModels(): void
    {
        // Fora de producao: acusa lazy loading, atributos inexistentes e
        // mass assignment descartado em silencio.
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * DENY BY DEFAULT
     *
     * - Uma Gate por habilidade declarada em config/permissions.php (equipe e
     *   clientes). Habilidade nao declarada nao tem Gate e o Laravel nega.
     * - Nao existe Gate::before: nem o proprietario passa por cima das
     *   Policies (briefing da Fase 3, item 13).
     * - Policies por registro (acesso horizontal), registradas aqui porque os
     *   models vivem em app/Modules e a descoberta automatica nao os acha.
     */
    private function configureAuthorization(): void
    {
        $habilidades = array_keys(PermissionMatrix::staffAbilities() + PermissionMatrix::customerAbilities());

        foreach ($habilidades as $ability) {
            Gate::define($ability, fn ($actor) => PermissionMatrix::allows($actor, $ability));
        }

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Professional::class, ProfessionalPolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(ServiceCategory::class, ServiceCategoryPolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(CommissionPayout::class, CommissionPayoutPolicy::class);
    }

    /**
     * Limites de tentativa (resposta 429). Chaves por conta usam o hash do
     * identificador digitado, nunca o valor em claro.
     */
    private function configureRateLimiting(): void
    {
        $max = (int) config('barbearia.security.login_max_attempts', 5);
        $conta = fn (Request $r) => hash('sha256', mb_strtolower(trim((string) ($r->input('identifier') ?? $r->input('email')))));

        // Login (equipe e clientes): por conta + IP segura tentativa de senha
        // contra uma conta; so por IP (folgado) segura "password spraying".
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($max)->by('login|'.$conta($request).'|'.$request->ip()),
            Limit::perMinute($max * 6)->by('login-ip|'.$request->ip()),
        ]);

        // Pedidos que disparam e-mail (redefinicao de senha, link magico).
        RateLimiter::for('email-requests', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('email|'.$conta($request)),
            Limit::perMinutes(10, (int) config('barbearia.security.email_requests_per_ip', 10))->by('email-ip|'.$request->ip()),
        ]);

        // Cadastro de cliente.
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(
            (int) config('barbearia.security.registrations_per_ip', 5)
        )->by('register|'.$request->ip()));

        // Uso de token (redefinir senha, link magico, confirmar e-mail):
        // impede testar tokens em sequencia.
        RateLimiter::for('token-use', fn (Request $request) => Limit::perMinute(10)->by('token|'.$request->ip()));

        // Reservar/remarcar/cancelar (Fase 5): segura robo que tenta ocupar a
        // agenda. Por conta logada (equipe ou cliente) + IP.
        RateLimiter::for('booking', fn (Request $request) => Limit::perMinute(20)->by(
            'booking|'.$request->user()?->getAuthIdentifier().'|'.$request->ip()
        ));

        // Operacoes de dinheiro e estoque (Fase 6): concluir, estornar,
        // movimentar caixa e estoque. Folga para o balcao, trava robo/abuso.
        RateLimiter::for('money', fn (Request $request) => Limit::perMinute(60)->by(
            'money|'.$request->user()?->getAuthIdentifier().'|'.$request->ip()
        ));

        // Envio de comprovante por e-mail (Fase 8): evita usar o sistema para
        // disparar e-mails em serie. Por conta (equipe ou cliente) + IP.
        RateLimiter::for('receipts', fn (Request $request) => [
            Limit::perMinute(10)->by('receipts|'.$request->user()?->getAuthIdentifier().'|'.$request->ip()),
            Limit::perHour(60)->by('receipts-h|'.$request->user()?->getAuthIdentifier()),
        ]);

        // Webhooks do Stripe (Fase 9): folga para o reenvio em lote do Stripe,
        // trava inundacao por IP. A autenticidade vem da assinatura.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('webhook|'.$request->ip()));

        // Previa com cupom na confirmacao do agendamento (Fase 8): impede testar
        // codigos de cupom em serie. Sem cupom no pedido, nao conta.
        RateLimiter::for('coupon-check', fn (Request $request) => $request->filled('cupom') ? [
            Limit::perMinute(10)->by('coupon|'.$request->user()?->getAuthIdentifier().'|'.$request->ip()),
            Limit::perHour(60)->by('coupon-h|'.$request->user()?->getAuthIdentifier()),
        ] : Limit::none());

        // Troca/confirmacao de senha logado: segura quem tenta adivinhar a
        // senha atual numa sessao roubada.
        RateLimiter::for('password-check', fn (Request $request) => Limit::perMinute($max)->by(
            'pwd|'.$request->user()?->getAuthIdentifier().'|'.$request->ip()
        ));
    }

    private function configureQueueFailureLogging(): void
    {
        // Rede de seguranca: registra falhas de QUALQUER job, inclusive os que
        // nao estendem BaseJob.
        Queue::failing(function (JobFailed $event) {
            Log::channel('jobs')->error('Falha na fila', [
                'conexao' => $event->connectionName,
                'job' => $event->job->resolveName(),
                'erro' => get_class($event->exception).': '.$event->exception->getMessage(),
            ]);
        });
    }
}
