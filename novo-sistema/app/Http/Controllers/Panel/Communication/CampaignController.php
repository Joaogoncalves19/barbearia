<?php

namespace App\Http\Controllers\Panel\Communication;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Templates\CampaignTemplate;
use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Exceptions\CampaignRejected;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Services\Campaigns;
use App\Modules\Marketing\Services\Segments;
use App\Modules\Team\Models\Professional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Campanhas (campanhas.md). Rascunho e teste: campaigns.manage. Disparar e
 * cancelar: campaigns.send. O envio sai aos poucos pela rotina (nunca nesta
 * requisicao) e so para quem aceitou receber novidades.
 */
class CampaignController extends Controller
{
    public function index(): View
    {
        return view('panel.campaigns.index', [
            'campaigns' => Campaign::query()->orderByRaw('is_legacy')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('panel.campaigns.form', ['campaign' => new Campaign(['segment' => 'todos']), 'segments' => Segments::ALL, 'professionals' => $this->professionals()]);
    }

    public function store(Request $request, Campaigns $campaigns): RedirectResponse
    {
        try {
            $c = $campaigns->saveDraft(null, ...$this->validated($request), actor: $this->user($request));
        } catch (CampaignRejected $e) {
            return back()->withInput()->withErrors(['campaign' => $e->getMessage()]);
        }

        return redirect()->route('panel.campaigns.show', $c)->with('status', 'Rascunho salvo. Confira o público e envie um teste antes de disparar.');
    }

    public function show(Campaign $campaign, Campaigns $campaigns): View
    {
        return view('panel.campaigns.show', [
            'campaign' => $campaign,
            'audience' => $campaign->status === 'draft' && ! $campaign->is_legacy ? $campaigns->audienceCount($campaign) : null,
            'preview' => $campaign->body !== null ? (new CampaignTemplate)->compose((string) $campaign->subject, (string) $campaign->body, 'Maria Exemplo', '#') : null,
            'segments' => Segments::ALL,
            'recent' => EmailMessage::query()->where('campaign_id', $campaign->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function edit(Campaign $campaign): View|RedirectResponse
    {
        if ($campaign->status !== 'draft' || $campaign->is_legacy) {
            return redirect()->route('panel.campaigns.show', $campaign)->with('status', 'Só rascunho pode ser editado.');
        }

        return view('panel.campaigns.form', ['campaign' => $campaign, 'segments' => Segments::ALL, 'professionals' => $this->professionals()]);
    }

    public function update(Request $request, Campaign $campaign, Campaigns $campaigns): RedirectResponse
    {
        try {
            $campaigns->saveDraft($campaign, ...$this->validated($request), actor: $this->user($request));
        } catch (CampaignRejected $e) {
            return back()->withInput()->withErrors(['campaign' => $e->getMessage()]);
        }

        return redirect()->route('panel.campaigns.show', $campaign)->with('status', 'Rascunho atualizado.');
    }

    public function test(Request $request, Campaign $campaign, Campaigns $campaigns): RedirectResponse
    {
        $user = $this->user($request);
        if ($user->email === null || $user->email === '') {
            return back()->withErrors(['campaign' => 'Cadastre um e-mail na sua conta para receber o teste.']);
        }
        $campaigns->sendTest($campaign, (string) $user->email, $user);

        return back()->with('status', 'Teste enviado para o seu e-mail ('.EmailMessage::mask((string) $user->email).').');
    }

    public function start(Request $request, Campaign $campaign, Campaigns $campaigns): RedirectResponse
    {
        $dados = $request->validate(['request_key' => ['required', 'string', 'size:36'], 'confirm' => ['accepted']], ['confirm.accepted' => 'Confirme que revisou o texto e o público.']);
        try {
            $c = $campaigns->start($campaign, $this->user($request), $dados['request_key']);
        } catch (CampaignRejected $e) {
            return back()->withErrors(['campaign' => $e->getMessage()]);
        }

        return redirect()->route('panel.campaigns.show', $c)->with('status', 'Campanha iniciada para '.$c->total_recipients.' cliente(s). O envio sai aos poucos.');
    }

    public function cancel(Request $request, Campaign $campaign, Campaigns $campaigns): RedirectResponse
    {
        try {
            $campaigns->cancel($campaign, $this->user($request));
        } catch (CampaignRejected $e) {
            return back()->withErrors(['campaign' => $e->getMessage()]);
        }

        return back()->with('status', 'Campanha cancelada. O que ainda não saiu não sai mais.');
    }

    /**
     * @return array{name: string, subject: string, body: string, segment: string, params: array<string, int|string|null>}
     */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'segment' => ['required', Rule::in(array_keys(Segments::ALL))],
            'dias' => ['nullable', 'required_if:segment,sem_retorno', 'integer', 'between:1,3650'],
            'professional_id' => ['nullable', 'required_if:segment,profissional', 'integer', 'exists:professionals,id'],
        ], [], ['name' => 'nome', 'subject' => 'assunto', 'body' => 'texto', 'segment' => 'público', 'dias' => 'dias', 'professional_id' => 'profissional']);

        return [
            'name' => $d['name'], 'subject' => $d['subject'], 'body' => $d['body'], 'segment' => $d['segment'],
            'params' => match ($d['segment']) {
                'sem_retorno' => ['dias' => (int) $d['dias']],
                'profissional' => ['professional_id' => (int) $d['professional_id']],
                default => [],
            },
        ];
    }

    /**
     * @return Collection<int, Professional>
     */
    private function professionals(): Collection
    {
        return Professional::query()->orderBy('display_name')->get(['id', 'display_name']);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
