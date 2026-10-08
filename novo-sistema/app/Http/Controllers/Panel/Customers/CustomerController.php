<?php

namespace App\Http\Controllers\Panel\Customers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Customers\Services\CustomerActivation;
use App\Modules\Customers\Services\CustomerErasure;
use App\Modules\Customers\Services\CustomerLookup;
use App\Modules\Customers\Services\DuplicateCustomerFinder;
use App\Modules\Customers\Support\Cpf;
use App\Modules\Customers\Support\Phone;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\CustomerRegistration;
use App\Modules\Loyalty\Pricing\PromotionEngine;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Clientes no painel (Fase 13, P13-01): lista, ficha, cadastro pelo balcao,
 * edicao, ativar/desativar e anonimizacao.
 *
 * Nada de regra nova: a matriz de permissoes (customers.*) e a CustomerPolicy
 * decidem quem ve, edita, ve o CPF completo e anonimiza; a busca e a do
 * balcao (CustomerLookup), os beneficios os do motor de promocoes, os pontos
 * os do extrato e a anonimizacao a mesma da conta do cliente (CustomerErasure).
 * Alteracoes do cadastro vao para a auditoria pelo model (Auditable, CPF
 * mascarado).
 *
 * O que a equipe NAO altera aqui, de proposito (decisao do dono): e-mail
 * (identidade de acesso do cliente; troca so por ele, confirmada no endereco
 * novo; no cadastro pelo balcao so pode ser INFORMADO e segue o link de
 * confirmacao), senha, consentimentos (prova do proprio cliente) e mesclagem.
 */
class CustomerController extends Controller
{
    public function index(Request $request, CustomerLookup $lookup): View
    {
        $termo = trim((string) $request->query('busca', ''));
        $situacao = (string) $request->query('situacao', 'ativos');
        $situacao = array_key_exists($situacao, CustomerLookup::SITUATIONS) ? $situacao : 'ativos';

        return view('panel.customers.index', [
            'term' => $termo,
            'situation' => $situacao,
            'situations' => CustomerLookup::SITUATIONS,
            'customers' => $lookup->browse($this->user($request), $termo, $situacao),
        ]);
    }

    public function show(Request $request, Customer $customer, LoyaltyLedger $ledger, PromotionEngine $promotions, CustomerErasure $erasure): View
    {
        $user = $this->user($request);
        $agora = BusinessTime::now();
        $ativo = $customer->anonymized_at === null;
        $abertos = ['pending', 'confirmed'];

        return view('panel.customers.show', [
            'customer' => $customer->load('favoriteProfessionals:id,display_name'),
            'mergedInto' => $customer->merged_into_customer_id !== null ? Customer::query()->find($customer->merged_into_customer_id) : null,
            'canSeeCpf' => Gate::forUser($user)->allows('viewCpf', $customer),
            'upcoming' => $customer->appointments()->with('items')->whereIn('status', $abertos)->where('ends_at', '>', $agora)
                ->orderBy('starts_at')->limit(5)->get(),
            'appointments' => $customer->appointments()->with('items')->orderByDesc('starts_at')->orderByDesc('id')
                ->paginate(10, ['*'], 'agendamentos')->withQueryString(),
            'attendances' => Attendance::query()->where('customer_id', $customer->id)->orderByDesc('opened_at')->orderByDesc('id')
                ->paginate(10, ['*'], 'atendimentos')->withQueryString(),
            'notes' => CustomerNote::query()->where('customer_id', $customer->id)->latest('id')->limit(20)->get(),
            'subscription' => Subscription::query()->where('customer_id', $customer->id)
                ->whereIn('status', SubscriptionStatus::currentValues())->latest('id')->first(),
            'points' => $ativo && $user->can('loyalty.view') ? ['balance' => $ledger->balance($customer), 'available' => $ledger->available($customer)] : null,
            'entitlements' => $ativo && $customer->status === CustomerStatus::Active ? $promotions->entitlements($customer, BusinessTime::today()) : [],
            'blockers' => $ativo && Gate::forUser($user)->allows('anonymize', $customer) ? $erasure->blockers($customer, forTeam: true) : [],
        ]);
    }

    public function edit(Request $request, Customer $customer): View
    {
        return view('panel.customers.edit', [
            'customer' => $customer,
            'canEditCpf' => Gate::forUser($this->user($request))->allows('viewCpf', $customer),
        ]);
    }

    public function update(Request $request, Customer $customer, DuplicateCustomerFinder $duplicates): RedirectResponse
    {
        $podeCpf = Gate::forUser($this->user($request))->allows('viewCpf', $customer);
        // Permissao por campo, como no catalogo: pedido com CPF de quem nao
        // pode ver o CPF e recusado inteiro, sem gravar nada.
        if ($request->has('cpf') && ! $podeCpf) {
            abort(403);
        }

        $regras = $this->rules($duplicates, $customer->id);
        if ($podeCpf) {
            $regras['cpf'] = [$customer->cpf !== null ? 'required' : 'nullable', ...$regras['cpf']];
        } else {
            unset($regras['cpf']);
        }

        $dados = $request->validate($regras, [], self::ATTRIBUTES);

        $customer->name = trim($dados['name']);
        $customer->phone = $dados['phone'] ?? null;
        $customer->birth_date = $dados['birth_date'] ?? null;
        if ($podeCpf && filled($dados['cpf'] ?? null)) {
            $customer->cpf = $dados['cpf'];
        }
        $customer->save();

        return redirect()->route('panel.customers.show', $customer->public_id)->with('status', 'Cadastro atualizado.');
    }

    /**
     * Cadastro pelo balcao (Fase 13): a equipe inicia, o cliente conclui. Sem
     * campo de senha nem de consentimento; e-mail opcional, com o mesmo link de
     * confirmacao do cadastro pelo site (CustomerRegistration::registerAtCounter).
     */
    public function create(): View
    {
        return view('panel.customers.create');
    }

    public function store(Request $request, DuplicateCustomerFinder $duplicates, CustomerRegistration $registration): RedirectResponse
    {
        $regras = $this->rules($duplicates, null);
        $regras['cpf'] = ['required', ...$regras['cpf']];
        $regras['email'] = ['nullable', 'string', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($duplicates): void {
            if (is_string($value) && isset($duplicates->conflicts($value, null, null)['email'])) {
                $fail('Este e-mail já está em outro cadastro.');
            }
        }];
        $dados = $request->validate($regras, [], self::ATTRIBUTES + ['email' => 'e-mail']);
        $telefone = trim((string) ($dados['phone'] ?? ''));

        try {
            $cliente = $registration->registerAtCounter([
                'name' => (string) $dados['name'],
                'email' => filled($dados['email'] ?? null) ? trim((string) $dados['email']) : null,
                'cpf' => (string) Cpf::normalize((string) $dados['cpf']),
                'phone' => $telefone === '' ? null : Phone::normalize($telefone),
                'birth_date' => $dados['birth_date'] ?? null,
            ], $this->user($request));
        } catch (DomainRuleViolation $e) {
            return back()->withInput()->withErrors(['cpf' => $e->getMessage()]);
        }

        return redirect()->route('panel.customers.show', $cliente->public_id)->with('status', $cliente->email !== null
            ? 'Cliente cadastrado. Ele recebe um e-mail para confirmar o endereço e cria a própria senha pelo site.'
            : 'Cliente cadastrado, sem e-mail: atendimento só pelo balcão.');
    }

    /** Ativar ou desativar o cadastro (Fase 13, decisao do dono). */
    public function setStatus(Request $request, Customer $customer, CustomerActivation $activation): RedirectResponse
    {
        $ativo = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $activation->setActive($customer, $ativo);

        return redirect()->route('panel.customers.show', $customer->public_id)->with('status', $ativo
            ? 'Cadastro reativado: o cliente volta a entrar no site e a aparecer na busca do balcão.'
            : 'Cadastro desativado: o cliente não entra mais no site e sai da busca do balcão. O histórico continua.');
    }

    /** Confirmacao da anonimizacao (rota com senha reconfirmada). */
    public function confirmAnonymize(Customer $customer, CustomerErasure $erasure): View
    {
        return view('panel.customers.anonymize', [
            'customer' => $customer,
            'blockers' => $erasure->blockers($customer, forTeam: true),
        ]);
    }

    public function anonymize(Request $request, Customer $customer, CustomerErasure $erasure): RedirectResponse
    {
        // A tela de confirmacao exige a senha de novo; o envio confere a mesma
        // janela (auth.password_timeout), como no acesso do profissional.
        $confirmada = (int) $request->session()->get('auth.password_confirmed_at', 0);
        abort_if(time() - $confirmada > (int) config('auth.password_timeout', 900), 403, 'Confirme sua senha de novo para anonimizar.');

        $request->validate(['confirmacao' => ['required', 'in:ANONIMIZAR']], [
            'confirmacao.*' => 'Digite ANONIMIZAR para confirmar.',
        ]);

        try {
            $erasure->erase($customer, $this->user($request));
        } catch (DomainRuleViolation) {
            return back()->withErrors(['anonymize' => implode(' ', $erasure->blockers($customer, forTeam: true)) ?: 'Não foi possível anonimizar agora.']);
        }

        return redirect()->route('panel.customers.show', $customer->public_id)
            ->with('status', 'Cadastro anonimizado. O histórico financeiro e da agenda continua, sem os dados pessoais.');
    }

    private const ATTRIBUTES = ['name' => 'nome', 'phone' => 'celular', 'birth_date' => 'data de nascimento', 'cpf' => 'CPF'];

    /**
     * As mesmas validacoes do cadastro pelo site e da conta do cliente (nome,
     * celular com DDD, nascimento, CPF com digitos verificadores), mais a
     * unicidade: a equipe ja ve os clientes, entao a tela diz qual dado conflita.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(DuplicateCustomerFinder $duplicates, ?int $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:25', function (string $attribute, mixed $value, Closure $fail) use ($duplicates, $ignoreId): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                if (Phone::normalize($value) === null) {
                    $fail('Informe um celular com DDD.');
                } elseif (isset($duplicates->conflicts(null, $value, null, $ignoreId)['phone'])) {
                    $fail('Este celular já está em outro cadastro.');
                }
            }],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'cpf' => ['string', 'max:20', function (string $attribute, mixed $value, Closure $fail) use ($duplicates, $ignoreId): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                if (Cpf::normalize($value) === null) {
                    $fail('Informe um CPF válido.');
                } elseif (isset($duplicates->conflicts(null, null, $value, $ignoreId)['cpf'])) {
                    $fail('Este CPF já está em outro cadastro.');
                }
            }],
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}
