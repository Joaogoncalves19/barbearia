<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerDataExport;
use App\Modules\Customers\Services\CustomerErasure;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\SiteContent\Support\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Privacidade (LGPD) na conta do cliente: o que a barbearia guarda e por
 * quanto tempo, prova dos consentimentos, exportar os dados e excluir a conta.
 * Exportar e excluir pedem a senha de novo (customer.reauth). Tudo parte do
 * cliente logado; nenhum id vem da requisicao.
 */
class PrivacyController extends Controller
{
    public function show(Request $request): View
    {
        $cliente = $this->customer($request);

        return view('account.privacy', [
            'customer' => $cliente,
            'consents' => ConsentRecord::query()->where('customer_id', $cliente->id)->orderByDesc('occurred_at')->orderByDesc('id')->limit(20)->get(),
            'hasPolicy' => SiteSettings::current()->has('privacy_policy'),
        ]);
    }

    /** Download do JSON (gerado na hora, nada fica salvo no servidor). */
    public function export(Request $request, CustomerDataExport $export): Response
    {
        $dados = $export->build($this->customer($request));
        $nome = 'meus-dados-'.BusinessTime::today().'.json';

        return response()->streamDownload(function () use ($dados): void {
            echo json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }, $nome, ['Content-Type' => 'application/json; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function confirmClose(Request $request, CustomerErasure $erasure): View
    {
        return view('account.close', ['blockers' => $erasure->blockers($this->customer($request))]);
    }

    public function destroy(Request $request, CustomerErasure $erasure): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:EXCLUIR'],
        ], ['confirmation.in' => 'Digite EXCLUIR, em maiúsculas, para confirmar.', 'confirmation.required' => 'Digite EXCLUIR, em maiúsculas, para confirmar.']);

        try {
            $erasure->erase($this->customer($request), $this->customer($request));
        } catch (DomainRuleViolation $e) {
            return redirect()->route('account.close')->withErrors(['delete' => 'Ainda não é possível excluir a conta.']);
        }

        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Sua conta foi excluída e seus dados pessoais foram apagados. O histórico financeiro ficou guardado sem o seu nome, como manda a lei.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}
