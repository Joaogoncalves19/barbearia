<?php

namespace App\Providers;

use App\Modules\Identity\Models\User;
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

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
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
     * DENY BY DEFAULT: uma Gate por habilidade declarada em
     * config/permissions.php. Habilidade nao declarada nao tem Gate e o
     * Laravel nega. Nao existe Gate::before concedendo "tudo" a ninguem.
     */
    private function configureAuthorization(): void
    {
        foreach (array_keys(config('permissions.abilities', [])) as $ability) {
            Gate::define($ability, fn (User $user) => $user->hasPermission($ability));
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $max = (int) config('barbearia.security.login_max_attempts', 5);
            $email = mb_strtolower((string) $request->input('email'));

            return [
                // Por conta + IP: segura tentativa de senha contra um e-mail.
                Limit::perMinute($max)->by($email.'|'.$request->ip()),
                // So por IP (folgado): segura "password spraying".
                Limit::perMinute($max * 6)->by('ip|'.$request->ip()),
            ];
        });
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
