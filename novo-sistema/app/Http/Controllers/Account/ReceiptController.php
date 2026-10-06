<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Receipts\Enums\ReceiptType;
use App\Modules\Receipts\Services\Receipts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * O cliente imprime ou recebe por e-mail o comprovante dos proprios
 * atendimentos concluidos (a policy da rota: alheio = 404). O e-mail vai SO
 * para o endereco da propria conta.
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly Receipts $receipts) {}

    /** Fase 12: comprovantes = atendimentos concluidos do proprio cliente (com ou sem agendamento). */
    public function index(Request $request): View
    {
        return view('account.receipts', [
            'attendances' => Attendance::query()->where('customer_id', $this->customer($request)->id)
                ->where('status', AttendanceStatus::Completed->value)
                ->orderByDesc('completed_at')->orderByDesc('id')->paginate(15),
        ]);
    }

    public function show(Request $request, Attendance $attendance): View
    {
        return view('receipts.page', [
            'partial' => ReceiptType::Attendance->view(),
            'back' => route('account.attendances.show', $attendance),
            'emailUrl' => $this->customer($request)->email !== null ? route('account.attendances.email', $attendance) : null,
            'emailDefault' => $this->customer($request)->email,
            'emailLocked' => true,
            ...$this->receipts->data(ReceiptType::Attendance, $attendance->id),
        ]);
    }

    public function email(Request $request, Attendance $attendance): RedirectResponse
    {
        $dados = $request->validate(['request_key' => ['required', 'string', 'uuid']]);
        $cliente = $this->customer($request);
        abort_if($cliente->email === null, 404);

        try {
            $this->receipts->send(ReceiptType::Attendance, $attendance, $cliente->email, $cliente, $dados['request_key']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('status', 'Comprovante enviado para '.$cliente->email.'.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}
