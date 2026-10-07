<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Services\CommissionRules;
use App\Modules\Finance\Services\ProfessionalLedger;
use App\Modules\Scheduling\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Ganhos" do profissional (Fase 12.5): o que tem a receber, o que ganhou
 * no dia, na semana e no mes, os ultimos 7 dias, o extrato do mes e os
 * repasses. So os dados DELE (a rota exige commissions.view_own e o
 * profissional vem do usuario logado; nao ha id na URL).
 *
 * Nada e calculado aqui: saldo e extrato vem do ProfessionalLedger (Fase 7),
 * a regra em vigor do CommissionRules e o faturamento dos totais congelados
 * dos atendimentos concluidos. So consulta: ajustar, pagar, estornar ou
 * configurar continua no painel, para quem tem a permissao.
 */
class EarningsController extends Controller
{
    use ResolvesProfessional;

    public function __invoke(Request $request, ProfessionalLedger $ledger, CommissionRules $rules): View
    {
        $pro = $this->professional($request);
        $hoje = BusinessTime::today();
        $mesAtual = substr($hoje, 0, 7);
        $mes = (string) $request->query('mes', $mesAtual);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $mes = $mesAtual;
        }

        $diaHoje = BusinessTime::dayBounds($hoje);
        $dHoje = CarbonImmutable::createFromFormat('Y-m-d', $hoje, BusinessTime::zone());
        $inicioSemana = BusinessTime::dayBounds($dHoje->startOfWeek(CarbonImmutable::MONDAY)->toDateString())->start;
        $inicioMes = BusinessTime::at($mes.'-01', '00:00');
        $fimMes = BusinessTime::at(CarbonImmutable::createFromFormat('Y-m-d', $mes.'-01')->addMonth()->toDateString(), '00:00');

        $seteDias = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $dHoje->subDays($i);
            $limites = BusinessTime::dayBounds($d->toDateString());
            $seteDias[] = ['date' => $d->toDateString(), 'label' => $d->locale('pt_BR')->translatedFormat('D d/m'), 'today' => $i === 0]
                + $ledger->earnedBetween($pro, $limites->start, $limites->end);
        }

        $faturado = Attendance::query()->where('professional_id', $pro->id)
            ->where('status', AttendanceStatus::Completed->value)
            ->where('completed_at', '>=', $inicioMes)->where('completed_at', '<', $fimMes);

        // Regra de comissao em vigor (so leitura), por servico que ele executa.
        $servicos = $pro->services()->ordered()->get();
        $regras = [
            'services' => $servicos->map(fn ($s) => ['name' => $s->name, 'rule' => $rules->resolve(CommissionTarget::Service, $pro->id, $s->id)?->describe()])->all(),
            'product' => $rules->resolve(CommissionTarget::Product, $pro->id, null)?->describe(),
            'subscription' => $rules->resolve(CommissionTarget::Subscription, $pro->id, null)?->describe(),
        ];

        $mesD = CarbonImmutable::createFromFormat('Y-m-d', $mes.'-01', BusinessTime::zone());

        return view('professional.earnings', [
            'professional' => $pro,
            'month' => $mes,
            'monthLabel' => $mesD->locale('pt_BR')->translatedFormat('F \d\e Y'),
            'previousMonth' => $mesD->subMonth()->format('Y-m'),
            'nextMonth' => $mes < $mesAtual ? $mesD->addMonth()->format('Y-m') : null,
            'open' => $ledger->open($pro),
            'periods' => [
                'Hoje' => $ledger->earnedBetween($pro, $diaHoje->start, $diaHoje->end),
                'Nesta semana' => $ledger->earnedBetween($pro, $inicioSemana, $diaHoje->end),
                'Neste mês' => $ledger->earnedBetween($pro, BusinessTime::at($mesAtual.'-01', '00:00'), $diaHoje->end),
            ],
            'lastDays' => $seteDias,
            'statement' => $ledger->month($pro, $mes),
            'billed' => ['count' => (clone $faturado)->count(), 'total' => (int) (clone $faturado)->sum('total_cents')],
            'payouts' => CommissionPayout::query()->where('professional_id', $pro->id)->latest('id')->limit(12)->get(),
            'rules' => $regras,
        ]);
    }
}
