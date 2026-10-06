<?php

namespace App\Modules\Identity\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\Email;
use App\Modules\Identity\Notifications\CustomerEmailChanged;
use App\Modules\Identity\Notifications\CustomerEmailChangeLink;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Troca de e-mail pelo proprio cliente (Fase 12). O e-mail e o login, o
 * destino do link magico e da nova senha: por isso o endereco novo so vale
 * depois de confirmado pelo link enviado A ELE (prova de que a pessoa o
 * controla), e o endereco antigo recebe um aviso quando a troca acontece.
 *
 * - O banco guarda so o hash do token; o link vale poucos minutos e uma vez.
 * - Endereco que ja e de outro cadastro: a resposta na tela e a mesma (sem
 *   revelar que existe) e nada e enviado; a troca nunca toma o e-mail de
 *   outra pessoa (unicidade conferida de novo ao confirmar).
 * - Pedir de novo substitui o pedido anterior (o link antigo deixa de valer).
 */
final class CustomerEmailChange
{
    /** Pede a troca. Devolve false quando o endereco nao pode ser usado (sem dizer por que a quem pediu). */
    public function request(Customer $customer, string $newEmail): bool
    {
        $novo = Email::normalize($newEmail);
        if ($novo === null || $novo === $customer->email) {
            return false;
        }
        if (Customer::withTrashed()->where('email', $novo)->whereKeyNot($customer->id)->exists()) {
            AuditTrail::record('customer.email_change_refused', $customer, $customer, 'Pedido de troca de e-mail para um endereço que já pertence a outro cadastro.');

            return false;
        }

        $token = Str::random(64);
        DB::table('customers')->where('id', $customer->id)->update([
            'pending_email' => $novo,
            'pending_email_token' => hash('sha256', $token),
            'pending_email_expires_at' => BusinessTime::now()->addMinutes((int) config('barbearia.security.email_change_minutes', 60)),
            'updated_at' => now(),
        ]);
        AuditTrail::record('customer.email_change_requested', $customer, $customer, 'Pedido de troca de e-mail (aguardando confirmação no endereço novo).');

        Notification::route('mail', $novo)->notify(new CustomerEmailChangeLink($token));

        return true;
    }

    /** Pedido valido deste cliente para este token (ou null). */
    public function pending(Customer $customer, string $token): ?string
    {
        $linha = DB::table('customers')->where('id', $customer->id)
            ->where('pending_email_token', hash('sha256', $token))
            ->where('pending_email_expires_at', '>', BusinessTime::now())
            ->first(['pending_email']);

        return $linha !== null && is_string($linha->pending_email) ? $linha->pending_email : null;
    }

    /** Confirma: o endereco novo passa a valer (ja confirmado). False se o link nao vale mais ou o endereco foi tomado. */
    public function confirm(Customer $customer, string $token): bool
    {
        $antigo = $customer->email;

        try {
            $novo = DB::transaction(function () use ($customer, $token): ?string {
                $novo = $this->pending($customer, $token);
                if ($novo === null || Customer::withTrashed()->where('email', $novo)->whereKeyNot($customer->id)->exists()) {
                    return null;
                }
                $c = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $c->email = $novo;
                $c->forceFill([
                    'email_verified_at' => now(),
                    'pending_email' => null,
                    'pending_email_token' => null,
                    'pending_email_expires_at' => null,
                ])->save();
                // Links de acesso emitidos para o endereco antigo deixam de valer
                // (os pedidos de nova senha saem logo abaixo, pelo endereco antigo).
                DB::table('customer_login_tokens')->where('customer_id', $c->id)->whereNull('used_at')->delete();

                return $novo;
            });
        } catch (QueryException) {
            return false; // corrida: outro cadastro pegou o endereco entre a conferencia e a gravacao
        }

        if ($novo === null) {
            return false;
        }

        if ($antigo !== null) {
            DB::table('customer_password_reset_tokens')->where('email', $antigo)->delete();
            Notification::route('mail', $antigo)->notify(new CustomerEmailChanged);
        }
        AuditTrail::record('customer.email_changed', $customer->fresh(), $customer, 'Cliente trocou o e-mail da conta (confirmado pelo link enviado ao endereço novo).');

        return true;
    }

    public function cancel(Customer $customer): void
    {
        DB::table('customers')->where('id', $customer->id)->update(['pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null, 'updated_at' => now()]);
    }
}
