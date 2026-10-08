@php
    use App\Modules\Scheduling\Support\BusinessTime;
@endphp
<x-layouts.staff title="Clientes">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Clientes</h1>
            <p class="text-muted">Cadastro, contato, histórico e benefícios de quem frequenta a barbearia.</p>
        </div>
    </header>

    <form method="GET" action="{{ route('panel.customers.index') }}" class="cluster" role="search">
        <x-ui.input name="busca" :label="auth('web')->user()->can('customers.view_cpf') ? 'Nome, e-mail, celular ou CPF' : 'Nome, e-mail ou celular'" :value="$term" />
        <x-ui.select name="situacao" label="Situação" :options="$situations" :value="$situation" />
        <x-ui.button type="submit" variant="secondary" icon="search">Buscar</x-ui.button>
    </form>

    @if ($customers->isEmpty())
        <x-ui.empty-state icon="search" :title="$term !== '' ? 'Nenhum cliente encontrado.' : 'Nenhum cliente nesta situação.'">
            @if ($term !== '')Confira a grafia ou procure pelo celular.@endif
        </x-ui.empty-state>
    @else
        <x-ui.table caption="Clientes" caption-hidden stacked>
            <thead>
                <tr>
                    <th scope="col">Cliente</th>
                    <th scope="col">Celular</th>
                    <th scope="col">E-mail</th>
                    <th scope="col">Último atendimento</th>
                    <th scope="col">Situação</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($customers as $c)
                    <tr data-customer-row>
                        <td data-label="Cliente"><a href="{{ route('panel.customers.show', $c->public_id) }}">{{ $c->name }}</a></td>
                        <td data-label="Celular" class="numeric">{{ $c->phone ?? '—' }}</td>
                        <td data-label="E-mail">{{ $c->email ?? '—' }}</td>
                        <td data-label="Último atendimento" class="numeric">{{ $c->last_attendance_at ? BusinessTime::formatLocal($c->last_attendance_at, 'd/m/Y') : '—' }}</td>
                        <td data-label="Situação">
                            @if ($c->anonymized_at !== null)
                                <x-ui.badge>Anonimizado</x-ui.badge>
                            @elseif ($c->status->value === 'active')
                                <x-ui.badge variant="success">Ativo</x-ui.badge>
                            @else
                                <x-ui.badge variant="danger">Inativo</x-ui.badge>
                            @endif
                            @if ($c->anonymized_at === null && $c->cpf === null)<x-ui.badge variant="warning">Sem CPF</x-ui.badge>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        {{ $customers->links() }}
    @endif
</x-layouts.staff>
