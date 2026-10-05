<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Avisos na conta (notificacoes in-app; emails.md §5): lembretes, pedido de
 * avaliacao e assinatura. So os do cliente logado; marcar como lido vale
 * so para os dele.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.notifications', [
            'notifications' => CustomerNotification::query()->where('customer_id', $this->customer($request)->id)
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function markRead(Request $request): RedirectResponse
    {
        CustomerNotification::query()->where('customer_id', $this->customer($request)->id)->whereNull('read_at')->update(['read_at' => now()]);

        return redirect()->route('account.notifications')->with('status', 'Avisos marcados como lidos.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}
