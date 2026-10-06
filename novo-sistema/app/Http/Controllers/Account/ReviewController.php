<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Reviews\Exceptions\ReviewRejected;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\Reviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Avaliacoes do PROPRIO cliente (avaliacoes.md §2). A lista parte do
 * cliente logado; o atendimento da rota passa pela Policy (alheio = 404) e
 * o servico confere de novo dono, conclusao, prazo e unicidade.
 */
class ReviewController extends Controller
{
    public function index(Request $request, Reviews $reviews): View
    {
        $cliente = $this->customer($request);

        return view('account.reviews.index', [
            'pending' => $reviews->pendingFor($cliente)->with('professional')->orderByDesc('completed_at')->get(),
            'reviews' => Review::query()->where('customer_id', $cliente->id)->with(['professional', 'reply', 'attendance'])->orderByDesc('reviewed_at')->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function create(Attendance $attendance, Reviews $reviews): View|RedirectResponse
    {
        $motivo = $reviews->ineligibilityReason($attendance);
        if ($motivo !== null) {
            return redirect()->route('account.reviews.index')->with('status', $motivo);
        }

        return view('account.reviews.create', ['attendance' => $attendance->load('professional', 'items'), 'max' => Reviews::MAX_COMMENT]);
    }

    public function store(Request $request, Attendance $attendance, Reviews $reviews): RedirectResponse
    {
        $dados = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:'.Reviews::MAX_COMMENT],
        ], ['rating.required' => 'Escolha uma nota de 1 a 5.'], ['rating' => 'nota', 'comment' => 'comentário']);

        try {
            $reviews->submit($this->customer($request), $attendance, (int) $dados['rating'], $dados['comment'] ?? null);
        } catch (ReviewRejected $e) {
            return redirect()->route('account.reviews.index')->with('status', $e->getMessage());
        }

        return redirect()->route('account.reviews.index')->with('status', 'Obrigado pela avaliação! Ela aparece para outras pessoas depois da revisão da barbearia.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}
