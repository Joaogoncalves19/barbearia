<x-layouts.staff title="Encaixe">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.attendances.index') }}">Voltar para atendimentos</a>
            <h1 class="page-head__title">Encaixe</h1>
            <p class="text-muted">Para cliente que chegou sem hora marcada. O encaixe entra na agenda do profissional a partir de agora, pela duração do serviço, com o preço do catálogo. Se o profissional não estiver livre (outro agendamento, pausa, bloqueio, folga ou fora do expediente), o encaixe é recusado.</p>
        </div>
    </header>

    @error('attendance')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    @if ($professionals->isEmpty())
        <x-ui.empty-state title="Nenhum profissional disponível" icon="users">Não há profissional ativo que você possa atender.</x-ui.empty-state>
    @else
        <form method="POST" action="{{ route('panel.attendances.store') }}" class="stack" novalidate>
            @csrf
            <div class="dashboard-grid">
                <x-ui.card title="1. Serviço e profissional">
                    <div class="stack">
                        <x-ui.select name="service_id" label="Serviço" :options="$services->mapWithKeys(fn ($s) => [$s->id => $s->name.' — '.$s->price()->format()])->all()" placeholder="Escolha o serviço" />
                        <x-ui.select name="professional_id" label="Profissional" :options="$professionals->pluck('display_name', 'id')->all()" :value="$professionals->count() === 1 ? $professionals->first()->id : null" placeholder="Escolha quem vai atender" />
                        <p class="text-sm text-muted">Outros serviços e produtos entram depois, na tela do atendimento.</p>
                    </div>
                </x-ui.card>

                <x-ui.card title="2. Cliente">
                    <div class="stack">
                        @can('customers.view')
                            <div class="stack stack-sm">
                                <x-ui.input name="cliente" label="Buscar cliente cadastrado" :value="$term" hint="Nome, e-mail ou telefone. Depois toque em Buscar." form="busca-cliente" optional />
                                <div><x-ui.button type="submit" variant="secondary" size="sm" icon="search" form="busca-cliente">Buscar</x-ui.button></div>
                            </div>
                            @if ($customers->isNotEmpty())
                                <fieldset class="check-group">
                                    <legend>Resultado</legend>
                                    <div class="stack stack-sm">
                                        @foreach ($customers as $c)
                                            <x-ui.radio name="customer_id" :value="$c->id" :label="$c->name" :hint="$c->phone ?? $c->email" />
                                        @endforeach
                                    </div>
                                </fieldset>
                            @elseif ($term !== '')
                                <p class="text-sm text-muted">Nenhum cliente encontrado. Use os campos abaixo.</p>
                            @endif
                        @endcan
                        <p class="text-sm text-muted">Sem cadastro? Informe o nome (e o telefone) de quem vai ser atendido.</p>
                        <x-ui.input name="contact_name" label="Nome do cliente" optional />
                        <x-ui.input name="contact_phone" label="Telefone" type="tel" optional />
                    </div>
                </x-ui.card>
            </div>
            <div><x-ui.button type="submit" icon="plus">Abrir atendimento</x-ui.button></div>
        </form>

        <form id="busca-cliente" method="GET" action="{{ route('panel.attendances.create') }}"></form>
    @endif
</x-layouts.staff>
