<?php

namespace App\Http\Middleware;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Team\Models\Professional;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem usa a area do profissional (Fase 12.5) nao navega pelo painel
 * administrativo: as paginas do painel que tem equivalente na area dele
 * levam para la (inicio, agenda, agendamento, atendimentos, atendimento,
 * extrato, ficha, minha conta). As demais paginas que ele ja podia abrir
 * (novo agendamento, remarcar, encaixe, senha, recibo) continuam onde estao,
 * na moldura do profissional; as administrativas continuam 403.
 *
 * So GET. Registro de outro profissional NAO e redirecionado: segue para a
 * rota, que responde 404 pela Policy (nao confirma que existe). As mensagens
 * da acao anterior (status, erros) sao mantidas por mais uma requisicao.
 */
class RedirectProfessionalToArea
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        if (! $request->isMethod('GET') || ! $user instanceof User || ! $user->can('professional_area.access') || $user->professional === null) {
            return $next($request);
        }

        $destino = $this->destination($request, $user);
        if ($destino === null) {
            return $next($request);
        }

        $request->session()->reflash();

        return redirect()->to($destino);
    }

    private function destination(Request $request, User $user): ?string
    {
        $rota = $request->route();
        $param = fn (string $nome) => $rota?->parameter($nome);
        $data = $request->query('data');
        $data = is_string($data) ? ['data' => $data] : [];

        return match ($rota?->getName()) {
            'panel.home' => route('pro.today'),
            'panel.agenda' => route('pro.agenda', $data),
            'panel.attendances.index' => route('pro.attendances', $data),
            'panel.appointments.show' => ($a = $param('appointment')) instanceof Appointment && $user->can('view', $a) ? route('pro.appointments.show', $a) : null,
            'panel.attendances.show' => ($a = $param('attendance')) instanceof Attendance && $user->can('view', $a) ? route('pro.attendances.show', $a) : null,
            'panel.commissions.mine' => route('pro.earnings'),
            'panel.commissions.show' => ($p = $param('professional')) instanceof Professional && $p->is($user->professional)
                ? route('pro.earnings', is_string($request->query('mes')) ? ['mes' => $request->query('mes')] : []) : null,
            'panel.professionals.show' => ($p = $param('professional')) instanceof Professional && $p->is($user->professional) ? route('pro.profile') : null,
            'panel.account.edit' => route('pro.profile'),
            default => null,
        };
    }
}
