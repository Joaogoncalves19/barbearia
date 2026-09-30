<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Enums\MergeCandidateStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerMergeCandidate;
use App\Modules\Customers\Support\Cpf;
use App\Modules\System\Services\AuditTrail;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * CPF obrigatorio: cliente sem CPF informa aqui antes de usar a conta.
 *
 * CPF que ja pertence a OUTRO cadastro: nada e mesclado (estrategia de
 * duplicidades). O par vira candidato a mesclagem para a equipe revisar, e o
 * cliente e orientado a falar com a barbearia.
 */
class CompleteProfileController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $this->customer($request)->needsProfileCompletion()) {
            return redirect()->route('account.home');
        }

        return view('account.complete-profile');
    }

    public function update(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);

        if (! $customer->needsProfileCompletion()) {
            return redirect()->route('account.home');
        }

        $request->validate(['cpf' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail): void {
            if (Cpf::normalize(is_string($value) ? $value : null) === null) {
                $fail('Informe um CPF válido.');
            }
        }]], [], ['cpf' => 'CPF']);

        $cpf = (string) Cpf::normalize($request->string('cpf')->value());
        $dono = Customer::withTrashed()->where('cpf', $cpf)->whereKeyNot($customer->id)->first();

        if ($dono !== null) {
            CustomerMergeCandidate::query()->firstOrCreate(
                ['customer_id' => $dono->id, 'duplicate_customer_id' => $customer->id, 'match_field' => 'cpf'],
                ['match_value' => Cpf::mask($cpf), 'status' => MergeCandidateStatus::Pending, 'notes' => 'CPF informado pelo cliente ao completar o cadastro.'],
            );
            AuditTrail::record('customer.cpf_conflict', $customer, $customer, 'CPF informado já pertence a outro cadastro; par enviado para revisão.');

            throw ValidationException::withMessages([
                'cpf' => 'Este CPF já está ligado a outro cadastro. Fale com a barbearia para unirmos os cadastros com segurança.',
            ]);
        }

        $customer->cpf = $cpf;
        $customer->save();

        return redirect()->route('account.home')->with('status', 'Cadastro completo. Obrigado!');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}
