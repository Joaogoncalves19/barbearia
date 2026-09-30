<?php

namespace App\Http\Controllers\Panel\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\ServiceRequest;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\ServiceAdmin;
use App\Modules\Catalog\Services\ServiceCatalog;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use App\Modules\Shared\Support\Duration;
use App\Modules\Shared\Support\Ordering;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Servicos do catalogo. Autorizacao: rota (can:services.*) + ServiceRequest
 * (preco e exibicao por habilidade propria) + ServicePolicy (exclusao).
 */
class ServiceController extends Controller
{
    public function __construct(private readonly ServiceAdmin $admin) {}

    public function index(Request $request, ServiceCatalog $catalog): View
    {
        $filtro = $request->query('situacao', 'ativos');
        $filtro = in_array($filtro, ['ativos', 'inativos', 'todos'], true) ? $filtro : 'ativos';

        $servicos = Service::query()
            ->withCount('professionals')
            ->when($filtro !== 'todos', fn ($q) => $q->where('is_active', $filtro === 'ativos'))
            ->ordered()
            ->get();

        return view('panel.catalog.services.index', [
            'groups' => $catalog->groupByCategory($servicos),
            'filter' => $filtro,
        ]);
    }

    public function create(): View
    {
        return view('panel.catalog.services.form', $this->formData(new Service([
            'duration_minutes' => 30, 'is_active' => true, 'is_public' => true, 'is_featured' => false,
        ])));
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $dados = $request->serviceData() + ['is_active' => true];
        $s = $this->admin->create($dados, $request->file('image'));

        return redirect()->route('panel.services.index')->with('status', "Serviço \"{$s->name}\" criado por {$s->price()->format()}, {$s->durationLabel()}.");
    }

    public function edit(Service $service): View
    {
        return view('panel.catalog.services.form', $this->formData($service) + [
            'priceHistory' => $this->admin->priceHistory($service),
            'canDelete' => $this->admin->canDelete($service),
        ]);
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        try {
            $s = $this->admin->update($service, $request->serviceData(), $request->integer('version'),
                $request->file('image'), $request->boolean('remove_image'));
        } catch (StaleRecord) {
            return back()->withInput()->withErrors(['version' => CategoryController::STALE]);
        }

        $aviso = $s->wasChanged('price_cents')
            ? " Novo preço: {$s->price()->format()} (vale para agendamentos novos; os já feitos mantêm o valor registrado)."
            : '';

        return redirect()->route('panel.services.index')->with('status', "Serviço \"{$s->name}\" atualizado.{$aviso}");
    }

    public function status(Request $request, Service $service): RedirectResponse
    {
        $ativo = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $this->admin->setActive($service, $ativo);

        return back()->with('status', $ativo
            ? "Serviço \"{$service->name}\" ativado."
            : "Serviço \"{$service->name}\" desativado: sai dos novos agendamentos e do site. Agendamentos já feitos não mudam.");
    }

    public function move(Request $request, Service $service): RedirectResponse
    {
        $dados = $request->validate(['direction' => ['required', Rule::in([Ordering::UP, Ordering::DOWN])]]);
        $this->admin->move($service, $dados['direction']);

        return back()->with('status', 'Ordem atualizada.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        try {
            $this->admin->delete($service);
        } catch (DomainRuleViolation) {
            return back()->withErrors(['service' => 'Este serviço já foi usado e não pode ser excluído. Desative-o.']);
        }

        return redirect()->route('panel.services.index')->with('status', "Serviço \"{$service->name}\" excluído.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Service $service): array
    {
        return [
            'service' => $service,
            'categories' => ServiceCategory::query()->ordered()
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $service->category_id))
                ->get()
                ->mapWithKeys(fn (ServiceCategory $c) => [$c->id => $c->name.($c->is_active ? '' : ' (inativa)')])
                ->all(),
            'durations' => Duration::options(),
        ];
    }
}
