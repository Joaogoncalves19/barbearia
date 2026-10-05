<x-layouts.staff title="Gerar link de assinatura">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.subscriptions.index') }}">Voltar para assinaturas</a>
            <h1 class="page-head__title">Gerar link de assinatura</h1>
            <p class="text-muted">O cliente paga pelo link (Stripe). A assinatura só ativa quando o Stripe confirmar o pagamento. O link também aparece na conta do cliente.</p>
        </div>
    </header>

    @unless ($stripeReady)
        <x-ui.alert variant="warning">Pagamento online não configurado neste ambiente: não é possível gerar o link.</x-ui.alert>
    @endunless
    @error('subscription')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <form method="GET" action="{{ route('panel.subscriptions.link') }}" class="cluster" role="search">
        <x-ui.input name="busca" label="Cliente (nome, e-mail ou telefone)" :value="$search" />
        <x-ui.button type="submit" variant="secondary" icon="search">Buscar</x-ui.button>
    </form>

    @if ($search !== '')
        <x-ui.card title="Clientes encontrados">
            @if ($customers->isEmpty())
                <x-ui.empty-state title="Nenhum cliente" icon="users">Nenhum cliente com este nome, e-mail ou telefone.</x-ui.empty-state>
            @elseif ($plans === [])
                <x-ui.empty-state title="Nenhum plano aberto" icon="badge-check">Abra um plano para novas adesões antes de gerar o link.</x-ui.empty-state>
            @else
                <ul class="stack">
                    @foreach ($customers as $c)
                        <li>
                            <form method="POST" action="{{ route('panel.subscriptions.link.store') }}" class="cluster" novalidate>
                                @csrf
                                <input type="hidden" name="customer" value="{{ $c->public_id }}">
                                <span><strong>{{ $c->name }}</strong> <span class="text-sm text-muted">{{ $c->email ?? 'sem e-mail' }}</span></span>
                                <x-ui.select name="plan_id" :id="'plano-'.$c->public_id" label="Plano" :options="$plans" />
                                <x-ui.button type="submit" size="sm" icon="external-link" :disabled="! $stripeReady || $c->email === null">Gerar link</x-ui.button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif
</x-layouts.staff>
