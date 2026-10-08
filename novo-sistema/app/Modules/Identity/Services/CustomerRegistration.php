<?php

namespace App\Modules\Identity\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\DuplicateCustomerFinder;
use App\Modules\Customers\Services\MarketingConsentService;
use App\Modules\Customers\Support\Email;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\CustomerAccountAlreadyExists;
use App\Modules\Identity\Notifications\CustomerRegistrationNotCompleted;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
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
 * Cadastro do cliente pelo site (e, na Fase 13, pela equipe no painel:
 * registerAtCounter).
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
     * @param  array{name: string, email: string, cpf: string, phone: ?string, password: string, marketing: bool, referral?: ?string}  $data
     */
    public function register(#[\SensitiveParameter] array $data, ?string $ip): void
    {
        $this->timebox->call(fn () => $this->handle($data, $ip), self::RESPONSE_MICROSECONDS);
    }

    /**
     * @param  array{name: string, email: string, cpf: string, phone: ?string, password: string, marketing: bool, referral?: ?string}  $data
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
                // Indicacao (Fase 8): so com codigo valido de cliente ativo.
                $indicador = ($data['referral'] ?? null) !== null
                    ? Customer::query()->where('referral_code', $data['referral'])->whereNull('anonymized_at')->value('id')
                    : null;
                $customer->forceFill(['referred_by_customer_id' => $indicador]);
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

    /**
     * Cadastro iniciado pela EQUIPE no painel (Fase 13): os mesmos dados e
     * regras do cadastro pelo site, com duas diferencas de proposito:
     * - sem senha: so o cliente cria, pelo link magico ou "esqueci a senha"
     *   (que tambem confirmam o e-mail, provando que o endereco e dele);
     * - sem consentimento de novidades: a prova e do proprio cliente.
     * Com e-mail, sai o mesmo link de confirmacao do site e a conta nasce com o
     * e-mail NAO confirmado. Conflito de e-mail, CPF ou celular nao cria nada
     * (a equipe ja ve os clientes: a tela pode dizer qual dado conflita).
     *
     * @param  array{name: string, email: ?string, cpf: string, phone: ?string, birth_date: ?string}  $data
     *
     * @throws DomainRuleViolation se algum dado ja pertence a outro cliente
     */
    public function registerAtCounter(array $data, User $actor): Customer
    {
        $email = Email::normalize($data['email']);
        if ($this->duplicates->conflicts($email, $data['phone'], $data['cpf']) !== []) {
            throw DomainRuleViolation::rule('R-DUP', 'E-mail, CPF ou celular já pertence a outro cliente.');
        }

        try {
            $customer = DB::transaction(function () use ($data, $email): Customer {
                $customer = new Customer;
                $customer->fill([
                    'name' => trim($data['name']),
                    'email' => $email,
                    'cpf' => $data['cpf'],
                    'phone' => $data['phone'],
                    'birth_date' => $data['birth_date'],
                ]);
                $customer->save();

                return $customer;
            });
        } catch (UniqueConstraintViolationException) {
            throw DomainRuleViolation::rule('R-DUP', 'E-mail, CPF ou celular já pertence a outro cliente.');
        }

        AuditTrail::record('customer.registered', $customer, $actor, 'Cadastro pelo painel (balcão).');
        if ($customer->email !== null) {
            event(new Registered($customer));
            $customer->sendEmailVerificationNotification();
        }

        return $customer;
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
