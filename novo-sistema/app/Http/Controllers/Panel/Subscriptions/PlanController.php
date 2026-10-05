<?php

namespace App\Http\Controllers\Panel\Subscriptions;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Service;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\Plans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Planos (planos.md): so o proprietario (plans.manage). Mudar preco ou
 * servicos cria uma versao nova; quem ja assina fica na versao contratada.
 */
class PlanController extends Controller
{
    public function __construct(private readonly Plans $plans) {}

    public function index(): View
    {
        return view('panel.subscriptions.plans.index', [
            'plans' => Plan::query()->with(['currentVersion.services'])->orderByDesc('is_active')->orderBy('name')->get(),
            'subscribers' => Subscription::query()->whereNotNull('active_customer_id')->selectRaw('plan_id, count(*) as n')->groupBy('plan_id')->pluck('n', 'plan_id'),
        ]);
    }

    public function create(): View
    {
        return view('panel.subscriptions.plans.form', ['plan' => null, 'services' => $this->services()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validated($request, true);
        $preco = $this->price($dados['price']);
        if ($preco === null) {
            return back()->withInput()->withErrors(['price' => 'Informe o preço (ex.: 99,90).']);
        }
        try {
            $plano = $this->plans->create($dados['name'], $dados['description'] ?? null, $preco, array_map('intval', $dados['services']), $this->user($request));
        } catch (SubscriptionRuleViolation $e) {
            return back()->withInput()->withErrors(['plan' => $e->getMessage()]);
        }

        return redirect()->route('panel.plans.edit', $plano)->with('status', 'Plano '.$plano->name.' criado.');
    }

    public function edit(Plan $plan): View
    {
        return view('panel.subscriptions.plans.form', [
            'plan' => $plan->load(['versions.services', 'currentVersion.services']),
            'services' => $this->services(),
        ]);
    }

    public function storeVersion(Request $request, Plan $plan): RedirectResponse
    {
        $dados = $this->validated($request, false);
        $preco = $this->price($dados['price']);
        if ($preco === null) {
            return back()->withInput()->withErrors(['price' => 'Informe o preço (ex.: 99,90).']);
        }
        try {
            $v = $this->plans->newVersion($plan, $preco, array_map('intval', $dados['services']), (string) ($dados['reason'] ?? ''), $this->user($request));
        } catch (SubscriptionRuleViolation $e) {
            return back()->withInput()->withErrors(['plan' => $e->getMessage()]);
        }

        return back()->with('status', 'Versão '.$v->version.' do plano criada ('.$v->priceLabel().'). Vale para novas adesões; quem já assina continua na versão contratada.');
    }

    public function setStatus(Request $request, Plan $plan): RedirectResponse
    {
        $ativo = $request->boolean('active');
        $this->plans->setActive($plan, $ativo, $this->user($request));

        return back()->with('status', $ativo ? 'Plano aberto para novas adesões.' : 'Plano fechado para novas adesões (quem assina continua).');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $novo): array
    {
        return $request->validate([
            'name' => [$novo ? 'required' : 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'string', 'max:20'],
            'services' => ['required', 'array', 'min:1', 'max:50'],
            'services.*' => ['integer'],
            'reason' => [$novo ? 'nullable' : 'required', 'string', 'min:3', 'max:255'],
        ], ['services.required' => 'Escolha ao menos um serviço incluído.'], [
            'name' => 'nome', 'description' => 'descrição', 'price' => 'preço', 'services' => 'serviços incluídos', 'reason' => 'motivo',
        ]);
    }

    private function price(string $text): ?int
    {
        $m = Money::tryParse($text);

        return $m !== null && $m->cents >= 1 ? $m->cents : null;
    }

    /**
     * @return array<int, string>
     */
    private function services(): array
    {
        return Service::query()->ordered()->get()->mapWithKeys(fn (Service $s) => [$s->id => $s->name.' · '.$s->price()->format()])->all();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}
