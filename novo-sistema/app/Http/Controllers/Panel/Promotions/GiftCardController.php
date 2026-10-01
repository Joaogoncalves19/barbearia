<?php

namespace App\Http\Controllers\Panel\Promotions;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\GiftCardStatus;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Models\GiftCard;
use App\Modules\Loyalty\Services\GiftCards;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Vale-presente (vale-presente.md): vender (entra no caixa), consultar,
 * imprimir/enviar (ReceiptController) e cancelar (devolve pelo caixa).
 * Autorizacao na rota: gift_cards.view / sell / cancel.
 */
class GiftCardController extends Controller
{
    public function __construct(private readonly GiftCards $giftCards) {}

    public function index(Request $request): View
    {
        $filtro = (string) $request->query('situacao', 'disponiveis');
        $busca = GiftCard::normalizeCode((string) $request->query('codigo', ''));

        return view('panel.promotions.gift-cards.index', [
            'cards' => GiftCard::query()
                ->when($busca !== '', fn ($q) => $q->where('code', 'like', '%'.$busca.'%'))
                ->when($busca === '' && $filtro === 'disponiveis', fn ($q) => $q->where('status', GiftCardStatus::Available))
                ->latest('id')->limit(200)->get(),
            'filter' => $filtro,
            'search' => $busca,
        ]);
    }

    public function create(): View
    {
        return view('panel.promotions.gift-cards.create', [
            'methods' => collect(GiftCards::SALE_METHODS)->mapWithKeys(fn (PaymentMethod $m) => [$m->value => $m->label()])->all(),
            'expiry' => GiftCards::suggestedExpiry(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, GiftCards::SALE_METHODS))],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'purchaser_name' => ['nullable', 'string', 'max:255'],
            'purchaser_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'message' => ['nullable', 'string', 'max:500'],
        ], [], ['amount' => 'valor', 'method' => 'forma de pagamento', 'expires_on' => 'validade', 'purchaser_name' => 'quem comprou',
            'purchaser_email' => 'e-mail de quem comprou', 'recipient_name' => 'presenteado', 'recipient_email' => 'e-mail do presenteado', 'message' => 'mensagem']);

        $valor = Money::tryParse($dados['amount']);
        if ($valor === null || $valor->cents < 1) {
            return back()->withInput()->withErrors(['amount' => 'Informe o valor do vale (ex.: 100,00).']);
        }

        try {
            $vale = $this->giftCards->sell($valor->cents, PaymentMethod::from($dados['method']), $dados, $this->user($request), $dados['request_key']);
        } catch (PromotionRejected|CashRuleViolation $e) {
            return back()->withInput()->withErrors(['gift_card' => $e->getMessage()]);
        }

        return redirect()->route('panel.gift-cards.show', $vale)->with('status', 'Vale-presente '.$vale->code.' vendido: '.$valor->format().'.');
    }

    public function show(GiftCard $giftCard): View
    {
        return view('panel.promotions.gift-cards.show', ['card' => $giftCard->load(['soldBy', 'redeemedAttendance'])]);
    }

    public function cancel(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $dados = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo']);

        try {
            $this->giftCards->cancel($giftCard, $dados['reason'], $this->user($request));
        } catch (PromotionRejected|CashRuleViolation $e) {
            return back()->withInput()->withErrors(['gift_card' => $e->getMessage()]);
        }

        return back()->with('status', 'Vale-presente cancelado.'.($giftCard->is_legacy ? '' : ' O valor saiu do caixa como devolução.'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}
