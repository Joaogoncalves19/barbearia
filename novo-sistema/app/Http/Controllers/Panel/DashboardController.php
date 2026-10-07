<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inicio do painel (redesign): o DIA da barbearia, para quem abre o painel
 * entender em segundos o que esta acontecendo e o que vem a seguir.
 *
 * So leitura, e cada bloco respeita a permissao de quem ve:
 * - agenda: todos (appointments.view_all) ou so a propria (view_own);
 * - atendimentos em andamento: todos ou so os proprios;
 * - caixa (cash.view): aberto ou fechado e o dinheiro esperado na gaveta, o
 *   mesmo numero da tela do caixa (CashRegister::expectedCash);
 * - pendencias: avaliacoes para revisar (reviews.moderate), estoque abaixo do
 *   minimo (products.view), assinaturas com pagamento em atraso
 *   (subscriptions.view).
 * Nenhum indicador financeiro novo (faturamento, ticket medio): relatorios
 * ficam para quando o dono aprovar essa parte do roadmap.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CashRegister $cash, StockLedger $stock): View
    {
        /** @var User $user */
        $user = $request->user('web');
        $agora = BusinessTime::now();
        $dia = BusinessTime::dayBounds(BusinessTime::today());

        $todos = $user->can('appointments.view_all');
        $proprio = $user->professional?->id;
        $verAgenda = $todos || ($user->can('appointments.view_own') && $proprio !== null);

        $doDia = $verAgenda
            ? Appointment::query()->with(['items', 'attendance'])
                ->when(! $todos, fn ($q) => $q->where('professional_id', $proprio))
                ->where('starts_at', '>=', $dia->start)->where('starts_at', '<', $dia->end)
                ->where('status', '!=', AppointmentStatus::Cancelled->value)
                ->orderBy('starts_at')->get()
            : collect();

        $verAtendimentos = $user->can('attendances.view') || ($user->can('attendances.view_own') && $proprio !== null);
        $emAndamento = $verAtendimentos
            ? Attendance::query()->with(['items', 'professional'])
                ->whereIn('status', [AttendanceStatus::Open->value, AttendanceStatus::InProgress->value])
                ->when(! $user->can('attendances.view'), fn ($q) => $q->where('professional_id', $proprio))
                ->orderBy('opened_at')->get()
            : collect();

        $caixa = null;
        if ($user->can('cash.view')) {
            $sessao = $cash->current();
            $caixa = ['session' => $sessao, 'expected' => $sessao !== null ? $cash->expectedCash($sessao) : null];
        }

        $pendencias = [];
        if ($user->can('reviews.moderate')) {
            $n = Review::query()->where('status', ReviewStatus::Pending->value)->count();
            if ($n > 0) {
                $pendencias[] = ['icon' => 'star', 'text' => $n === 1 ? '1 avaliação aguardando revisão' : "{$n} avaliações aguardando revisão", 'href' => route('panel.reviews.index')];
            }
        }
        if ($user->can('products.view')) {
            $comMinimo = Product::query()->where('is_active', true)->whereNotNull('min_stock')->get(['id', 'name', 'min_stock']);
            $saldos = $stock->balances($comMinimo->pluck('id')->all());
            $baixos = $comMinimo->filter(fn (Product $p) => ($saldos[$p->id] ?? 0) < (int) $p->min_stock);
            if ($baixos->isNotEmpty()) {
                $pendencias[] = ['icon' => 'package', 'text' => $baixos->count() === 1 ? $baixos->first()->name.' abaixo do estoque mínimo' : $baixos->count().' produtos abaixo do estoque mínimo', 'href' => route('panel.products.index')];
            }
        }
        if ($user->can('subscriptions.view')) {
            $n = Subscription::query()->where('status', SubscriptionStatus::PastDue->value)->count();
            if ($n > 0) {
                $pendencias[] = ['icon' => 'badge-check', 'text' => $n === 1 ? '1 assinatura com pagamento em atraso' : "{$n} assinaturas com pagamento em atraso", 'href' => route('panel.subscriptions.index')];
            }
        }

        $abertos = [AppointmentStatus::Pending, AppointmentStatus::AwaitingPayment, AppointmentStatus::Confirmed];

        return view('panel.home', [
            'user' => $user,
            'greeting' => match (true) {
                (int) BusinessTime::local($agora)->format('H') < 12 => 'Bom dia',
                (int) BusinessTime::local($agora)->format('H') < 18 => 'Boa tarde',
                default => 'Boa noite',
            },
            'canSeeAgenda' => $verAgenda,
            // A seguir: aberto, ainda nao terminado e sem atendimento ja aberto (esse esta "na cadeira").
            'upcoming' => $doDia->filter(fn (Appointment $a) => in_array($a->status, $abertos, true) && $a->ends_at !== null && $a->ends_at->gt($agora)
                && ($a->attendance === null || ! in_array($a->attendance->status, [AttendanceStatus::Open, AttendanceStatus::InProgress], true)))->take(8)->values(),
            'inProgress' => $emAndamento,
            'counts' => [
                'total' => $doDia->count(),
                'done' => $doDia->where('status', AppointmentStatus::Completed)->count(),
                'next' => $doDia->filter(fn (Appointment $a) => in_array($a->status, $abertos, true) && $a->starts_at !== null && $a->starts_at->gt($agora))->count(),
                'noShow' => $doDia->where('status', AppointmentStatus::NoShow)->count(),
            ],
            'cash' => $caixa,
            'pending' => $pendencias,
            'now' => $agora,
        ]);
    }
}
