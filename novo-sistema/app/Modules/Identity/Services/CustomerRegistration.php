<?php

namespace App\Modules\Identity\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\DuplicateCustomerFinder;
use App\Modules\Customers\Services\MarketingConsentService;
use App\Modules\Customers\Support\Email;
use App\Modules\Identity\Notifications\CustomerAccountAlreadyExists;
use App\Modules\Identity\Notifications\CustomerRegistrationNotCompleted;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;

/**
 * Cadastro do cliente pelo site.
 *
 * A TELA responde sempre igual ("enviamos um e-mail"), qualquer que seja o
 * caso, para nao revelar quem ja e cliente. O que muda e o e-mail que chega
 * no endereco informado (so o dono do e-mail le):
 *
 * 1. e-mail, CPF e telefone livres  -> conta criada + link de confirmacao;
 * 2. e-mail ja cadastrado           -> "voce ja tem conta" + link para
 *                                      definir a senha (nada e criado);
 * 3. CPF ou telefone de outro cliente -> "nao conseguimos concluir"
 *                                      (nada e criado, nada e mesclado).
 *
 * Nunca mescla cadastros (estrategia de duplicidades da Fase 2). A conta
 * nasce com e-mail NAO confirmado e sem login automatico.
 */
final class CustomerRegistration
{
    private const RESPONSE_MICROSECONDS = 400_000;

    public function __construct(
        private readonly DuplicateCustomerFinder $duplicates,
        private readonly MarketingConsentService $consent,
        private readonly Timebox $timebox,
    ) {}

    /**
     * @param  array{name: string, email: string, cpf: string, phone: ?string, password: string, marketing: bool}  $data
     */
    public function register(#[\SensitiveParameter] array $data, ?string $ip): void
    {
        $this->timebox->call(fn () => $this->handle($data, $ip), self::RESPONSE_MICROSECONDS);
    }

    /**
     * @param  array{name: string, email: string, cpf: string, phone: ?string, password: string, marketing: bool}  $data
     */
    private function handle(#[\SensitiveParameter] array $data, ?string $ip): void
    {
        $email = (string) Email::normalize($data['email']);
        $conflitos = $this->duplicates->conflicts($email, $data['phone'], $data['cpf']);

        if (isset($conflitos['email'])) {
            $this->notifyExistingAccount($conflitos['email']);

            return;
        }

        if ($conflitos !== []) {
            $this->refuse($email, array_keys($conflitos), $ip);

            return;
        }

        try {
            $customer = DB::transaction(function () use ($data, $email, $ip): Customer {
                $customer = new Customer;
                $customer->fill([
                    'name' => trim($data['name']),
                    'email' => $email,
                    'cpf' => $data['cpf'],
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                ]);
                $customer->password_changed_at = now();
                $customer->save();

                if ($data['marketing']) {
                    $this->consent->grant($customer, 'signup', 'cadastro no site'.($ip ? " (IP {$ip})" : ''));
                }

                return $customer;
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida: outro cadastro com o mesmo dado entrou entre a
            // verificacao e a gravacao. Mesmo tratamento do caso 3.
            $this->refuse($email, ['concorrencia'], $ip);

            return;
        }

        AuditTrail::record('customer.registered', $customer, $customer, 'Cadastro pelo site.');
        event(new Registered($customer));
        $customer->sendEmailVerificationNotification();
    }

    private function notifyExistingAccount(int $customerId): void
    {
        $existente = Customer::query()->find($customerId);

        if ($existente === null || ! $existente->canSignIn() || $existente->email === null) {
            return;
        }

        /** @var PasswordBroker $broker */
        $broker = Password::broker('customers');

        // Respeita o limite de 1 link por minuto por conta (sem revelar nada).
        if ($broker->getRepository()->recentlyCreatedToken($existente)) {
            return;
        }

        $existente->notify(new CustomerAccountAlreadyExists($broker->createToken($existente)));
    }

    /**
     * @param  list<string>  $campos
     */
    private function refuse(string $email, array $campos, ?string $ip): void
    {
        Log::channel('security')->notice('Cadastro de cliente recusado por conflito', [
            'campos' => $campos,
            'email_hash' => hash('sha256', $email),
            'ip' => $ip,
        ]);

        Notification::route('mail', $email)->notify(new CustomerRegistrationNotCompleted);
    }
}
