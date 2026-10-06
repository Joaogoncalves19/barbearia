<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Services\CustomerEmailChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Troca do e-mail da conta pelo proprio cliente (CustomerEmailChange).
 * Pedir pede a senha de novo (customer.reauth); confirmar exige estar
 * conectado na MESMA conta e o link enviado ao endereco novo. A resposta ao
 * pedido e sempre a mesma, exista ou nao outro cadastro com o endereco.
 */
class EmailController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.email', ['customer' => $this->customer($request)]);
    }

    public function update(Request $request, CustomerEmailChange $change): RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ], [], ['email' => 'novo e-mail']);
        $cliente = $this->customer($request);

        if (mb_strtolower(trim($dados['email'])) === $cliente->email) {
            return back()->withErrors(['email' => 'Este já é o e-mail da sua conta.']);
        }
        $change->request($cliente, $dados['email']);

        return redirect()->route('account.profile.edit')
            ->with('status', 'Se o endereço puder ser usado, enviamos para ele um link de confirmação. O e-mail da conta só muda depois que você confirmar pelo link.');
    }

    public function show(Request $request, string $token, CustomerEmailChange $change): View
    {
        $novo = $change->pending($this->customer($request), $token);
        abort_if($novo === null, 404);

        return view('account.email-confirm', ['token' => $token, 'newEmail' => $novo]);
    }

    public function confirm(Request $request, string $token, CustomerEmailChange $change): RedirectResponse
    {
        if (! $change->confirm($this->customer($request), $token)) {
            return redirect()->route('account.profile.edit')->withErrors(['email' => 'O link não vale mais ou o endereço não pode ser usado. Peça a troca de novo.']);
        }

        return redirect()->route('account.profile.edit')->with('status', 'E-mail da conta alterado. Avisamos o endereço antigo.');
    }

    public function cancel(Request $request, CustomerEmailChange $change): RedirectResponse
    {
        $change->cancel($this->customer($request));

        return redirect()->route('account.profile.edit')->with('status', 'Pedido de troca de e-mail cancelado.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}
