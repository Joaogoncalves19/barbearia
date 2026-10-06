<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\DuplicateCustomerFinder;
use App\Modules\Customers\Support\Phone;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Dados que o PROPRIO cliente pode alterar: nome, celular e nascimento.
 *
 * Fora daqui, de proposito:
 * - CPF: obrigatorio e fixo depois de informado (correcao so pela equipe);
 * - e-mail: troca propria, confirmada pelo link no endereco novo (EmailController);
 * - status, consentimentos, mesclagem: acoes explicitas de outros fluxos.
 * O registro alterado e sempre o do cliente logado (nunca um id enviado).
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $cliente = $this->customer($request);

        return view('account.profile', [
            'customer' => $cliente,
            // Troca de e-mail pedida e ainda valendo (Fase 12).
            'pendingEmail' => $cliente->pending_email !== null && $cliente->pending_email_expires_at?->isFuture() ? $cliente->pending_email : null,
        ]);
    }

    public function update(Request $request, DuplicateCustomerFinder $duplicates): RedirectResponse
    {
        $customer = $this->customer($request);
        Gate::forUser($customer)->authorize('update', $customer);

        $dados = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:25', function (string $attribute, mixed $value, Closure $fail) use ($duplicates, $customer): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                if (Phone::normalize($value) === null) {
                    $fail('Informe um celular com DDD.');
                } elseif (isset($duplicates->conflicts(null, $value, null, $customer->id)['phone'])) {
                    $fail('Este celular já está em outro cadastro. Fale com a barbearia.');
                }
            }],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
        ], [], ['name' => 'nome', 'phone' => 'celular', 'birth_date' => 'data de nascimento']);

        $customer->name = trim($dados['name']);
        $customer->phone = $dados['phone'] ?? null;
        $customer->birth_date = $dados['birth_date'] ?? null;
        $customer->save();

        return redirect()->route('account.profile.edit')->with('status', 'Dados atualizados.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}
