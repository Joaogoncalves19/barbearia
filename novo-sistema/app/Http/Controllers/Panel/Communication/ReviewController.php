<?php

namespace App\Http\Controllers\Panel\Communication;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\ReviewRejected;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\Reviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Avaliacoes no painel (avaliacoes.md §3). Quem tem reviews.view ve todas
 * (aguardando revisao primeiro); o profissional (reviews.view_own) ve SO as
 * publicadas dos proprios atendimentos. Moderar e responder: habilidades
 * proprias, conferidas na rota; tudo auditado pelo servico.
 */
class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);
        $todas = $user->can('reviews.view');
        $filtro = $todas ? (string) $request->query('situacao', 'pending') : 'approved';
        if (! in_array($filtro, ['pending', 'approved', 'rejected', 'todas'], true)) {
            $filtro = 'pending';
        }

        $q = Review::query()->with(['professional', 'customer', 'reply', 'attendance'])
            ->when($filtro !== 'todas', fn ($q) => $q->where('status', $filtro))
            ->when(! $todas, fn ($q) => $q->where('professional_id', $user->professional->id ?? 0));

        return view('panel.reviews.index', [
            'reviews' => $q->orderByDesc('reviewed_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'filter' => $filtro,
            'all' => $todas,
            'pendingCount' => $todas ? Review::query()->where('status', ReviewStatus::Pending->value)->count() : 0,
        ]);
    }

    public function approve(Request $request, Review $review, Reviews $reviews): RedirectResponse
    {
        return $this->act(fn () => $reviews->moderate($review, ReviewStatus::Approved, null, $this->user($request)), 'Avaliação aprovada e publicada.');
    }

    public function reject(Request $request, Review $review, Reviews $reviews): RedirectResponse
    {
        $dados = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo']);

        return $this->act(fn () => $reviews->moderate($review, ReviewStatus::Rejected, $dados['reason'], $this->user($request)), 'Avaliação recusada (não aparece para ninguém).');
    }

    public function feature(Request $request, Review $review, Reviews $reviews): RedirectResponse
    {
        $destacar = $request->boolean('featured');

        return $this->act(fn () => $reviews->feature($review, $destacar, $this->user($request)), $destacar ? 'Avaliação destacada.' : 'Destaque retirado.');
    }

    public function reply(Request $request, Review $review, Reviews $reviews): RedirectResponse
    {
        $dados = $request->validate(['body' => ['required', 'string', 'max:'.Reviews::MAX_COMMENT]], [], ['body' => 'resposta']);

        return $this->act(fn () => $reviews->reply($review, $dados['body'], $this->user($request)), 'Resposta publicada.');
    }

    private function act(\Closure $acao, string $ok): RedirectResponse
    {
        try {
            $acao();
        } catch (ReviewRejected $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back()->with('status', $ok);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
