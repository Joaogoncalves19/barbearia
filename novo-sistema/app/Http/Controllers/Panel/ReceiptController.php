<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\CashSessionStatus;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Models\GiftCard;
use App\Modules\Receipts\Enums\ReceiptType;
use App\Modules\Receipts\Services\Receipts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Comprovantes impressos e por e-mail (comprovantes.md): atendimento,
 * repasse, vale-presente e fechamento de caixa. Quem ve o documento decide a
 * rota (a mesma permissao/policy da tela do documento); enviar por e-mail
 * tem limite (throttle:receipts) e fica registrado.
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly Receipts $receipts) {}

    public function attendance(Attendance $attendance): View
    {
        abort_unless($attendance->status === AttendanceStatus::Completed, 404);
        $email = $attendance->customer_id !== null ? Customer::query()->whereKey($attendance->customer_id)->value('email') : null;

        return $this->page(ReceiptType::Attendance, $attendance, route('panel.attendances.show', $attendance), route('panel.receipts.attendance.email', $attendance), $email);
    }

    public function emailAttendance(Request $request, Attendance $attendance): RedirectResponse
    {
        abort_unless($attendance->status === AttendanceStatus::Completed, 404);

        return $this->send($request, ReceiptType::Attendance, $attendance);
    }

    public function payout(CommissionPayout $payout): View
    {
        $email = $payout->professional?->user?->email;

        return $this->page(ReceiptType::Payout, $payout, route('panel.payouts.show', $payout), route('panel.receipts.payout.email', $payout), $email);
    }

    public function emailPayout(Request $request, CommissionPayout $payout): RedirectResponse
    {
        return $this->send($request, ReceiptType::Payout, $payout);
    }

    public function giftCard(GiftCard $giftCard): View
    {
        return $this->page(ReceiptType::GiftCard, $giftCard, route('panel.gift-cards.show', $giftCard), route('panel.receipts.gift-card.email', $giftCard), $giftCard->recipient_email ?? $giftCard->purchaser_email);
    }

    public function emailGiftCard(Request $request, GiftCard $giftCard): RedirectResponse
    {
        return $this->send($request, ReceiptType::GiftCard, $giftCard);
    }

    public function cash(Request $request, CashSession $session): View
    {
        abort_unless($session->status === CashSessionStatus::Closed, 404);

        return $this->page(ReceiptType::CashSession, $session, route('panel.cash.show', $session), route('panel.receipts.cash.email', $session), $session->closedBy->email ?? $this->user($request)->email);
    }

    public function emailCash(Request $request, CashSession $session): RedirectResponse
    {
        abort_unless($session->status === CashSessionStatus::Closed, 404);

        return $this->send($request, ReceiptType::CashSession, $session);
    }

    private function page(ReceiptType $type, Model $doc, string $back, string $emailUrl, ?string $email): View
    {
        return view('receipts.page', [
            'partial' => $type->view(),
            'back' => $back,
            'emailUrl' => $emailUrl,
            'emailDefault' => $email,
            'emailLocked' => false,
            ...$this->receipts->data($type, (int) $doc->getKey()),
        ]);
    }

    private function send(Request $request, ReceiptType $type, Model $doc): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ], [], ['email' => 'e-mail']);

        try {
            $this->receipts->send($type, $doc, $dados['email'], $this->user($request), $dados['request_key']);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('status', $type->label().' enviado para '.mb_strtolower(trim($dados['email'])).'.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}
