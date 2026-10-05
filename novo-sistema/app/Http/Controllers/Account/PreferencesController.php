<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Services\CommunicationPreferences;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Preferencias de e-mail do proprio cliente (consentimento.md §2), na tela
 * "Meus dados". Marketing: sim/nao explicito (sem escolha = fica como esta).
 * Lembretes: liga/desliga. Mudancas ficam registradas como prova.
 */
class PreferencesController extends Controller
{
    public function update(Request $request, CommunicationPreferences $preferences): RedirectResponse
    {
        $dados = $request->validate([
            'marketing' => ['nullable', Rule::in(['sim', 'nao'])],
        ]);
        /** @var Customer $cliente */
        $cliente = $request->user('customer');
        $marketing = match ($dados['marketing'] ?? null) {
            'sim' => true, 'nao' => false, default => null,
        };
        $mudou = $preferences->update($cliente, $marketing, $request->boolean('reminders'), 'account_preferences', 'ip:'.$request->ip());

        return redirect()->route('account.profile.edit')->with('status', $mudou === [] ? 'Nada mudou nas suas preferências.' : 'Preferências salvas: '.implode('; ', $mudou).'.');
    }
}
