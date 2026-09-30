<?php

namespace App\Modules\Identity\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\CustomerLoginToken;
use App\Modules\Identity\Notifications\CustomerMagicLink;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

/**
 * Login do cliente por link enviado ao e-mail (sem senha).
 *
 * - Token aleatorio de 64 caracteres; no banco so o hash SHA-256.
 * - Uso unico (marcado de forma atomica), validade curta (15 min padrao).
 * - Pedir um link novo invalida os anteriores ainda nao usados.
 * - Um pedido por conta por minuto; o excesso e ignorado em silencio.
 * - A resposta nao muda se a conta existe ou nao, nem no tempo (Timebox).
 * - Abrir o link NAO faz login: mostra um botao "Entrar" (POST). Leitores
 *   de e-mail que pre-carregam links nao gastam o token.
 */
final class MagicLinkService
{
    private const RESPONSE_MICROSECONDS = 250_000;

    public function __construct(private readonly Timebox $timebox) {}

    public function send(string $email, ?string $ip): void
    {
        $this->timebox->call(function () use ($email, $ip): void {
            $customer = LoginCredentials::findCustomer($email);

            if ($customer === null || $customer->email === null || ! $customer->canSignIn()) {
                return;
            }

            $recente = CustomerLoginToken::query()
                ->where('customer_id', $customer->id)
                ->whereNull('used_at')
                ->where('created_at', '>', now()->subMinute())
                ->exists();

            if ($recente) {
                return;
            }

            $token = Str::random(64);
            $minutos = (int) config('barbearia.security.magic_link_minutes');

            DB::transaction(function () use ($customer, $token, $minutos, $ip): void {
                CustomerLoginToken::query()->where('customer_id', $customer->id)->whereNull('used_at')->delete();
                CustomerLoginToken::query()->create([
                    'customer_id' => $customer->id,
                    'token_hash' => CustomerLoginToken::hashToken($token),
                    'expires_at' => now()->addMinutes($minutos),
                    'requested_ip' => $ip,
                ]);
            });

            $customer->notify(new CustomerMagicLink($token, $minutos));
        }, self::RESPONSE_MICROSECONDS);
    }

    /** O token existe, nao venceu e nao foi usado? (para a tela de confirmacao) */
    public function isUsable(#[\SensitiveParameter] string $token): bool
    {
        return $this->usableQuery($token)->exists();
    }

    /**
     * Gasta o token e devolve o cliente, ou null se invalido, vencido, ja
     * usado ou se a conta nao puder mais entrar.
     */
    public function consume(#[\SensitiveParameter] string $token): ?Customer
    {
        $registro = $this->usableQuery($token)->first();

        if ($registro === null) {
            return null;
        }

        // Atomico: dois cliques simultaneos no mesmo link => so um entra.
        $gasto = CustomerLoginToken::query()
            ->whereKey($registro->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $customer = $registro->customer;

        if ($gasto !== 1 || $customer === null || ! $customer->canSignIn()) {
            return null;
        }

        // Quem abriu o link no e-mail provou que o e-mail e dele.
        if ($customer->email_verified_at === null) {
            $customer->forceFill(['email_verified_at' => now()])->save();
        }

        AuditTrail::record('auth.magic_link_used', $customer, $customer, 'Entrou pelo link enviado ao e-mail.');

        return $customer;
    }

    /**
     * @return Builder<CustomerLoginToken>
     */
    private function usableQuery(#[\SensitiveParameter] string $token): Builder
    {
        return CustomerLoginToken::query()
            ->where('token_hash', CustomerLoginToken::hashToken($token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }
}
