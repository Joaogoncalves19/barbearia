<?php

namespace App\Http\Controllers\Panel\Team;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Catalog\CategoryController;
use App\Http\Requests\Panel\ProfessionalRequest;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\ServiceCatalog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use App\Modules\Shared\Support\Ordering;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Equipe profissional. Autorizacao: rota (can:professionals.* e, na ficha,
 * can:view,professional) + ProfessionalRequest (apresentacao publica por
 * habilidade propria). Regras em ProfessionalAdmin.
 */
class ProfessionalController extends Controller
{
    public function __construct(private readonly ProfessionalAdmin $admin) {}

    public function index(Request $request): View
    {
        $filtro = $request->query('situacao', 'ativos');
        $filtro = in_array($filtro, ['ativos', 'inativos', 'todos'], true) ? $filtro : 'ativos';

        return view('panel.team.professionals.index', [
            'professionals' => Professional::query()
                ->with('user')
                ->withCount('services')
                ->when($filtro !== 'todos', fn ($q) => $q->where('is_active', $filtro === 'ativos'))
                ->ordered()
                ->get(),
            'filter' => $filtro,
        ]);
    }

    /** Ficha (so leitura): o proprio profissional ou quem ve a equipe. */
    public function show(Professional $professional): View
    {
        $professional->load(['user', 'services' => fn ($q) => $q->with('category')->ordered()]);

        return view('panel.team.professionals.show', ['professional' => $professional]);
    }

    public function create(): View
    {
        return view('panel.team.professionals.form', [
            'professional' => new Professional(['is_active' => true, 'is_bookable' => true, 'is_public' => true, 'is_featured' => false]),
            'accounts' => $this->availableAccounts(null),
        ]);
    }

    public function store(ProfessionalRequest $request): RedirectResponse
    {
        try {
            $p = $this->admin->create($request->professionalData() + ['is_active' => true], $request->file('photo'));
        } catch (DomainRuleViolation $e) {
            return back()->withInput()->withErrors(['user_id' => 'Esta conta de acesso já está ligada a outro profissional.']);
        }

        return redirect()->route('panel.professionals.services.edit', $p)
            ->with('status', "Profissional \"{$p->display_name}\" cadastrado. Agora escolha os serviços que ele executa.");
    }

    public function edit(Professional $professional): View
    {
        return view('panel.team.professionals.form', [
            'professional' => $professional->load('user'),
            'accounts' => $this->availableAccounts($professional),
        ]);
    }

    public function update(ProfessionalRequest $request, Professional $professional): RedirectResponse
    {
        try {
            $this->admin->update($professional, $request->professionalData(), $request->integer('version'),
                $request->file('photo'), $request->boolean('remove_photo'));
        } catch (StaleRecord) {
            return back()->withInput()->withErrors(['version' => CategoryController::STALE]);
        } catch (DomainRuleViolation) {
            return back()->withInput()->withErrors(['user_id' => 'Esta conta de acesso já está ligada a outro profissional.']);
        }

        return redirect()->route('panel.professionals.index')->with('status', 'Profissional atualizado.');
    }

    public function status(Request $request, Professional $professional): RedirectResponse
    {
        $ativo = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $this->admin->setActive($professional, $ativo);

        $conta = $professional->user;
        $aviso = ! $ativo && $conta !== null && $conta->is_active
            ? " A conta de acesso \"{$conta->loginLabel()}\" continua ativa: se a pessoa saiu da equipe, desative-a em Usuários."
            : '';

        return back()->with('status', $ativo
            ? "{$professional->display_name} está ativo de novo."
            : "{$professional->display_name} foi desativado: sai dos novos agendamentos e do site. O histórico continua intacto.{$aviso}");
    }

    public function move(Request $request, Professional $professional): RedirectResponse
    {
        $dados = $request->validate(['direction' => ['required', Rule::in([Ordering::UP, Ordering::DOWN])]]);
        $this->admin->move($professional, $dados['direction']);

        return back()->with('status', 'Ordem atualizada.');
    }

    public function editServices(Professional $professional, ServiceCatalog $catalog): View
    {
        $vinculados = $professional->services()->withTrashed()->pluck('services.id')->map(fn ($id) => (int) $id)->all();

        return view('panel.team.professionals.services', [
            'professional' => $professional,
            'linked' => $vinculados,
            // So servicos ATIVOS podem ser escolhidos (regra do ProfessionalAdmin).
            'groups' => $catalog->groupByCategory(Service::query()->where('is_active', true)->ordered()->get()),
            'inactiveLinked' => Service::withTrashed()->whereIn('id', $vinculados)
                ->where(fn ($q) => $q->where('is_active', false)->orWhereNotNull('deleted_at'))->ordered()->get(),
        ]);
    }

    public function updateServices(Request $request, Professional $professional): RedirectResponse
    {
        $dados = $request->validate([
            'services' => ['sometimes', 'array', 'max:200'],
            'services.*' => ['integer', 'distinct'],
        ]);

        /** @var User $actor */
        $actor = $request->user('web');

        try {
            $r = $this->admin->syncServices($professional, array_values($dados['services'] ?? []), $actor);
        } catch (DomainRuleViolation) {
            return back()->withErrors(['services' => 'Um dos serviços escolhidos não está mais ativo. Nada foi salvo: confira a lista e tente de novo.']);
        }

        $total = $professional->services()->count();

        return redirect()->route('panel.professionals.index')->with('status',
            "Serviços de {$professional->display_name} salvos ({$total} no total; ".count($r['added']).' incluído(s), '.count($r['removed']).' retirado(s)).');
    }

    /**
     * Contas que podem ser vinculadas: da equipe, ativas e sem profissional
     * (mais a conta atual, se houver).
     *
     * @return array<int|string, string>
     */
    private function availableAccounts(?Professional $professional): array
    {
        $ocupadas = Professional::withTrashed()->whereNotNull('user_id')
            ->when($professional, fn ($q) => $q->whereKeyNot($professional->id))
            ->pluck('user_id');

        return ['' => 'Sem acesso ao sistema'] + User::query()
            ->whereNotIn('id', $ocupadas)
            ->where(fn ($q) => $q->where('is_active', true)->when($professional?->user_id, fn ($q2, $id) => $q2->orWhere('id', $id)))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} ({$u->loginLabel()}, {$u->role?->label()})"])
            ->all();
    }
}
