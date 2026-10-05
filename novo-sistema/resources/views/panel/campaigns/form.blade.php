@php
    $editando = $campaign->exists;
    $params = $campaign->segment_params ?? [];
@endphp
<x-layouts.staff :title="$editando ? 'Editar campanha' : 'Nova campanha'">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ $editando ? route('panel.campaigns.show', $campaign) : route('panel.campaigns.index') }}">Voltar</a>
            <h1 class="page-head__title">{{ $editando ? 'Editar campanha' : 'Nova campanha' }}</h1>
        </div>
    </header>

    @error('campaign')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card>
        <form method="POST" action="{{ $editando ? route('panel.campaigns.update', $campaign) : route('panel.campaigns.store') }}" class="stack" novalidate>
            @csrf
            @if ($editando) @method('PUT') @endif
            <x-ui.input name="name" label="Nome interno" :value="$campaign->name" hint="Só a equipe vê." maxlength="255" />
            <x-ui.input name="subject" label="Assunto do e-mail" :value="$campaign->subject" maxlength="200" />
            <x-ui.textarea name="body" label="Texto" :value="$campaign->body" rows="10" maxlength="10000"
                hint="Texto simples: separe parágrafos com uma linha em branco. Marcadores: {primeiro_nome}, {nome_cliente}, {nome_barbearia}, {link_agendamento}. O botão “Agendar” e o link de descadastro entram sozinhos." />
            <x-ui.select name="segment" label="Público" :options="$segments" :value="$campaign->segment ?? 'todos'" hint="Sempre só quem aceitou receber novidades e não se descadastrou." />
            <x-ui.input name="dias" type="number" label="Sem atendimento há mais de (dias)" :value="$params['dias'] ?? 60" min="1" max="3650" optional hint="Só para o público “Sem atendimento há mais de N dias”." />
            <x-ui.select name="professional_id" label="Profissional" :options="$professionals->pluck('display_name', 'id')->all()" :value="$params['professional_id'] ?? null" placeholder="Escolha" optional hint="Só para o público “Já atendidos por um profissional”." />
            <div><x-ui.button type="submit" variant="accent">Salvar rascunho</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.staff>
