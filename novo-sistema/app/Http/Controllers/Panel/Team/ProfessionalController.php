<?php

namespace App\Http\Controllers\Panel\Team;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Catalog\CategoryController;
use App\Http\Requests\Panel\ProfessionalRequest;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\ServiceCatalog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordManager;
use App\Modules\Identity\Services\StaffAccounts;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use App\Modules\Shared\Support\Ordering;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Equipe profissional. Autorizacao: rota (can:professionals.* e, na ficha,
 * can:view,professional) + ProfessionalRequest (apresentacao publica por
 * habilidade propria). Regras em ProfessionalAdmin.
 */
class ProfessionalController extends Controller
{
    /** Nunca voltam para o formulario depois de um erro. */
    private const SECRETS = ['access_password', 'access_reset_password'];

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

    public function create(Request $request): View|RedirectResponse
    {
        if ($this->mustConfirmPassword($request)) {
            return redirect()->guest(route('panel.password.confirm'));
        }

        return view('panel.team.professionals.form', [
            'professional' => new Professional(['is_active' => true, 'is_bookable' => true, 'is_public' => true, 'is_featured' => false]),
            'accounts' => $this->availableAccounts(null),
            'canManageAccess' => $this->canManageAccess($request),
            'canResetPassword' => false,
        ]);
    }

    /**
     * Cadastro com o acesso ao painel junto (Fase 12.5, como no sistema
     * antigo): ficha e login na mesma transacao; se o login falhar, nada fica.
     */
    public function store(ProfessionalRequest $request, StaffAccounts $accounts): RedirectResponse
    {
        $acesso = $request->accessData();
        abort_if($acesso !== null && $this->mustConfirmPassword($request), 403, 'Confirme sua senha de novo para criar o acesso.');

        try {
            $p = DB::transaction(function () use ($request, $accounts, $acesso): Professional {
                $p = $this->admin->create($request->professionalData() + ['is_active' => true], $request->file('photo'));
                if ($acesso !== null) {
                    $accounts->createForProfessional($p, $acesso, $request->string('access_password')->value(), $this->actor($request));
                }

                return $p;
            });
        } catch (DomainRuleViolation) {
            return back()->withInput($request->except(self::SECRETS))->withErrors(['user_id' => 'Esta conta de acesso já está ligada a outro profissional.']);
        }

        $login = $acesso !== null
            ? " Acesso ao painel: usuário \"{$acesso['username']}\". Passe a senha provisória pessoalmente: ela será trocada no primeiro acesso."
            : '';

        return redirect()->route('panel.professionals.services.edit', $p)
            ->with('status', "Profissional \"{$p->display_name}\" cadastrado.{$login} Agora escolha os serviços que ele executa.");
    }

    public function edit(Request $request, Professional $professional): View|RedirectResponse
    {
        if ($this->mustConfirmPassword($request)) {
            return redirect()->guest(route('panel.password.confirm'));
        }
        $professional->load('user');

        return view('panel.team.professionals.form', [
            'professional' => $professional,
            'accounts' => $this->availableAccounts($professional),
            'canManageAccess' => $this->canManageAccess($request),
            'canResetPassword' => $professional->user !== null && $this->actor($request)->can('setTemporaryPassword', $professional->user),
        ]);
    }

    public function update(ProfessionalRequest $request, Professional $professional, StaffAccounts $accounts, PasswordManager $passwords): RedirectResponse
    {
        $acesso = $request->accessData();
        $novaSenha = $request->string('access_reset_password')->value();
        abort_if(($acesso !== null || $novaSenha !== '') && $this->mustConfirmPassword($request), 403, 'Confirme sua senha de novo para mexer no acesso.');
        $conta = $professional->user;
        abort_if($novaSenha !== '' && ($conta === null || ! $this->actor($request)->can('setTemporaryPassword', $conta)), 403);

        try {
            DB::transaction(function () use ($request, $professional, $accounts, $passwords, $acesso, $novaSenha, $conta): void {
                $this->admin->update($professional, $request->professionalData(), $request->integer('version'),
                    $request->file('photo'), $request->boolean('remove_photo'));
                if ($acesso !== null) {
                    $accounts->createForProfessional($professional->refresh(), $acesso, $request->string('access_password')->value(), $this->actor($request));
                }
                if ($novaSenha !== '') {
                    $passwords->setTemporary($conta, $novaSenha, $this->actor($request));
                }
            });
        } catch (StaleRecord) {
            return back()->withInput($request->except(self::SECRETS))->withErrors(['version' => CategoryController::STALE]);
        } catch (DomainRuleViolation) {
            return back()->withInput($request->except(self::SECRETS))->withErrors(['user_id' => 'Esta conta de acesso já está ligada a outro profissional.']);
        }

        $extra = match (true) {
            $acesso !== null => " Acesso ao painel criado: usuário \"{$acesso['username']}\". Passe a senha provisória pessoalmente.",
            $novaSenha !== '' => ' Nova senha provisória definida: passe pessoalmente; ela será trocada no primeiro acesso.',
            default => '',
        };

        return redirect()->route('panel.professionals.index')->with('status', 'Profissional atualizado.'.$extra);
    }

    /** Quem cria login e senha aqui: so quem gerencia usuarios (o proprietario). */
    private function canManageAccess(Request $request): bool
    {
        return $this->actor($request)->can('users.manage');
    }

    /**
     * O mesmo cuidado da tela Usuarios: quem pode criar login reconfirma a
     * senha (janela de auth.password_timeout) antes de abrir o formulario.
     */
    private function mustConfirmPassword(Request $request): bool
    {
        if (! $this->canManageAccess($request)) {
            return false;
        }

        return time() - (int) $request->session()->get('auth.password_confirmed_at', 0) > (int) config('auth.password_timeout', 900);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user('web');
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
