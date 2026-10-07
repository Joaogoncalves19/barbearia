<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Perfil" do profissional (Fase 12.5). Edita so o que ja era editavel por
 * qualquer pessoa da equipe: o proprio nome (Minha conta) e a senha. A ficha
 * (foto, apresentacao, servicos, expediente, pausas e folgas) e consultada:
 * quem altera e a gerencia (professionals.*, schedule.*); ver P12.5-02/03.
 */
class ProfileController extends Controller
{
    use ResolvesProfessional;

    public function __invoke(Request $request): View
    {
        $user = $this->user($request);
        $pro = $this->professional($request);

        $avaliacoes = Review::query()->where('professional_id', $pro->id)->where('status', ReviewStatus::Approved->value);

        return view('professional.profile', [
            'user' => $user,
            'professional' => $pro,
            'services' => $pro->services()->ordered()->get(),
            'workingHours' => $pro->workingHours()->orderBy('weekday')->orderBy('starts_at')->get()->groupBy('weekday'),
            'breaks' => $pro->breaks()->where('is_active', true)->orderBy('weekday')->orderBy('starts_at')->get(),
            'timeOff' => $pro->timeOff()->whereDate('ends_on', '>=', BusinessTime::today())->orderBy('starts_on')->limit(5)->get(),
            'reviews' => ['count' => (clone $avaliacoes)->count(), 'average' => (clone $avaliacoes)->avg('rating')],
            'canSeeReviews' => $user->can('viewAny', Review::class),
        ]);
    }
}
