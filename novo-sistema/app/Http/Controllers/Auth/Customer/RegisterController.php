<?php

namespace App\Http\Controllers\Auth\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerRegisterRequest;
use App\Modules\Identity\Services\CustomerRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Cadastro do cliente pelo site. Resposta sempre igual (ver
 * CustomerRegistration): a conta so e usada depois de confirmar o e-mail.
 */
class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.customer.register');
    }

    public function store(CustomerRegisterRequest $request, CustomerRegistration $registration): RedirectResponse
    {
        $registration->register($request->registrationData(), $request->ip());

        return redirect()->route('customer.login')->with('status',
            'Pronto! Enviamos um e-mail para '.$request->string('email')->trim()->value().'. Abra a mensagem para confirmar o cadastro e depois entre.'
        );
    }
}
