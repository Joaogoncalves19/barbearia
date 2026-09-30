<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inicio da area do cliente (fundacao da Fase 3; a area completa e da Fase
 * 12). Lista SO os agendamentos do proprio cliente: a consulta parte do
 * cliente logado, nunca de um id vindo da requisicao.
 */
class AccountHomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $agendamentos = $customer->appointments()
            ->with('professional')
            ->orderByDesc('starts_at')
            ->limit(20)
            ->get();

        return view('account.home', ['customer' => $customer, 'appointments' => $agendamentos]);
    }
}
