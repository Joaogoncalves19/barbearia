<?php

namespace App\Http\Controllers\Prototypes;

use App\Http\Controllers\Controller;
use App\Support\Prototypes\SampleData;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Telas de REFERENCIA VISUAL (Fase 1). Nao implementam modulo nenhum: servem
 * para validar a identidade visual antes de replica-la em dezenas de telas.
 * Protegidas pelo middleware 'prototypes' (404 fora de local/homologacao).
 */
class PrototypeController extends Controller
{
    private const AGENDA_START = 9 * 60;   // 09:00

    private const AGENDA_END = 20 * 60;    // 20:00

    private const AGENDA_STEP = 15;        // grade de 15 minutos

    public function index(Request $request): View
    {
        return view('prototypes.index', $this->base($request));
    }

    public function home(Request $request): View
    {
        return view('prototypes.home', $this->base($request) + [
            'services' => SampleData::services(),
            'pros' => SampleData::professionals(),
            'testimonials' => SampleData::testimonials(),
            'hours' => SampleData::openingHours(),
        ]);
    }

    public function services(Request $request): View
    {
        return view('prototypes.services', $this->base($request) + [
            'services' => SampleData::services(),
        ]);
    }

    public function booking(Request $request): View
    {
        return view('prototypes.booking', $this->base($request) + [
            'services' => SampleData::services(),
            'pros' => SampleData::professionals(),
            'days' => SampleData::bookingDays(),
            'slots' => SampleData::bookingSlots(),
            'bookingJson' => [
                'services' => array_map(fn ($s) => [
                    'id' => $s['id'], 'name' => $s['name'], 'minutes' => $s['minutes'], 'price_cents' => $s['price_cents'],
                ], SampleData::flatServices()),
                'pros' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']], SampleData::professionals()),
                'days' => array_map(fn ($d) => ['value' => $d['value'], 'long' => $d['long']], SampleData::bookingDays()),
            ],
        ]);
    }

    public function dashboard(Request $request): View
    {
        $agenda = SampleData::agenda();
        $pros = collect(SampleData::professionals())->keyBy('id');
        $hoje = collect($agenda)
            ->flatMap(fn ($eventos, $pro) => collect($eventos)->map(fn ($e) => $e + ['pro' => $pros[$pro]['name']]))
            ->reject(fn ($e) => $e['status'] === 'break')
            ->sortBy('start')
            ->values();

        return view('prototypes.dashboard', $this->base($request, 'dashboard') + [
            'today' => $hoje,
            'nextIndex' => $hoje->search(fn ($e) => $e['status'] !== 'done'),
        ]);
    }

    public function agenda(Request $request): View
    {
        $pros = SampleData::professionals();
        $agenda = SampleData::agenda();
        $linhas = intdiv(self::AGENDA_END - self::AGENDA_START, self::AGENDA_STEP);

        // Posicao de cada evento na grade (linha 1 = cabecalho). As regras de
        // CSS saem num <style nonce> gerado aqui: a CSP proibe style="".
        $eventos = [];
        foreach ($pros as $col => $pro) {
            foreach ($agenda[$pro['id']] ?? [] as $i => $e) {
                $inicio = intdiv($e['start'] - self::AGENDA_START, self::AGENDA_STEP) + 2;
                $eventos[] = $e + [
                    'class' => 'ev-'.$pro['id'].'-'.$i,
                    'row' => $inicio,
                    'span' => max(1, intdiv($e['minutes'], self::AGENDA_STEP)),
                    'col' => $col + 2,
                    'pro' => $pro['name'],
                    'time' => sprintf('%02d:%02d', intdiv($e['start'], 60), $e['start'] % 60),
                    'end' => sprintf('%02d:%02d', intdiv($e['start'] + $e['minutes'], 60), ($e['start'] + $e['minutes']) % 60),
                ];
            }
        }

        $horarios = [];
        for ($m = self::AGENDA_START; $m < self::AGENDA_END; $m += self::AGENDA_STEP) {
            $horarios[] = ['label' => sprintf('%02d:%02d', intdiv($m, 60), $m % 60), 'hour' => $m % 60 === 0];
        }

        return view('prototypes.agenda', $this->base($request, 'agenda') + [
            'pros' => $pros,
            'events' => $eventos,
            'times' => $horarios,
            'rows' => $linhas,
            'view' => $request->query('visao') === 'lista' ? 'lista' : 'dia',
        ]);
    }

    public function designSystem(Request $request): View
    {
        $itens = collect(range(1, 42))->map(fn ($n) => ['n' => $n]);
        $pagina = max(1, (int) $request->query('pagina', 2));

        return view('prototypes.design-system', $this->base($request) + [
            'paginator' => new LengthAwarePaginator(
                $itens->forPage($pagina, 10), $itens->count(), 10, $pagina,
                ['path' => $request->url(), 'pageName' => 'pagina', 'query' => $request->only('direcao')],
            ),
        ]);
    }

    /**
     * Dados comuns: direcao visual escolhida, menus do site e do painel.
     *
     * @return array<string, mixed>
     */
    private function base(Request $request, string $painelAtual = ''): array
    {
        $direcao = in_array($request->query('direcao'), ['a', 'b'], true)
            ? $request->query('direcao')
            : config('barbearia.design.default_direction', 'a');
        $q = ['direcao' => $direcao];
        $rota = $request->route()?->getName();

        return [
            'direcao' => $direcao,
            'brand' => SampleData::BRAND,
            'q' => $q,
            'siteNav' => [
                ['label' => 'Serviços', 'href' => route('prototypes.services', $q), 'current' => $rota === 'prototypes.services'],
                ['label' => 'Equipe', 'href' => route('prototypes.home', $q).'#equipe'],
                ['label' => 'A barbearia', 'href' => route('prototypes.home', $q).'#sobre'],
                ['label' => 'Avaliações', 'href' => route('prototypes.home', $q).'#avaliacoes'],
                ['label' => 'Como chegar', 'href' => route('prototypes.home', $q).'#local'],
            ],
            'panelNav' => [
                ['group' => '', 'items' => [
                    ['label' => 'Hoje', 'icon' => 'layout-dashboard', 'href' => route('prototypes.dashboard', $q), 'current' => $painelAtual === 'dashboard'],
                    ['label' => 'Agenda', 'icon' => 'calendar-days', 'href' => route('prototypes.agenda', $q), 'current' => $painelAtual === 'agenda'],
                    ['label' => 'Clientes', 'icon' => 'users', 'href' => '#'],
                    ['label' => 'Caixa', 'icon' => 'wallet', 'href' => '#'],
                ]],
                ['group' => 'Gestão', 'items' => [
                    ['label' => 'Equipe', 'icon' => 'user', 'href' => '#'],
                    ['label' => 'Catálogo', 'icon' => 'scissors', 'href' => '#'],
                    ['label' => 'Promoções', 'icon' => 'sparkles', 'href' => '#'],
                    ['label' => 'Avaliações', 'icon' => 'star', 'href' => '#'],
                    ['label' => 'Site', 'icon' => 'store', 'href' => '#'],
                ]],
                ['group' => 'Negócio', 'items' => [
                    ['label' => 'Financeiro', 'icon' => 'chart-column', 'href' => '#'],
                    ['label' => 'Configurações', 'icon' => 'settings', 'href' => '#'],
                ]],
            ],
        ];
    }
}
