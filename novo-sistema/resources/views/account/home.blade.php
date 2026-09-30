<x-layouts.account title="Minha conta">
    <header class="stack stack-sm">
        <p class="eyebrow">Minha conta</p>
        <h1 class="h2">Olá, {{ strtok($customer->name, ' ') }}</h1>
    </header>

    <x-ui.card title="Seus dados">
        <dl class="summary-list">
            <div><dt>Nome</dt><dd>{{ $customer->name }}</dd></div>
            <div><dt>E-mail</dt><dd>{{ $customer->email }}</dd></div>
            <div><dt>Celular</dt><dd>{{ $customer->phone ?? 'Não informado' }}</dd></div>
            {{-- CPF sempre mascarado na tela. --}}
            <div><dt>CPF</dt><dd>{{ $customer->cpf ? \App\Modules\Customers\Support\Cpf::mask($customer->cpf) : 'Não informado' }}</dd></div>
        </dl>
        <x-slot:actions>
            <x-ui.button :href="route('account.profile.edit')" variant="secondary" size="sm" icon="pencil">Editar</x-ui.button>
        </x-slot:actions>
    </x-ui.card>

    <section class="stack">
        <h2 class="h3">Seus horários</h2>

        @if ($appointments->isEmpty())
            <x-ui.empty-state title="Nenhum horário ainda" icon="calendar">
                Quando você agendar, seus horários aparecem aqui.
            </x-ui.empty-state>
        @else
            <x-ui.table caption="Seus horários" caption-hidden stacked>
                <thead>
                    <tr><th scope="col">Data</th><th scope="col">Profissional</th><th scope="col">Situação</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($appointments as $a)
                        <tr>
                            <td data-label="Data">{{ $a->starts_at?->timezone(config('barbearia.display_timezone'))->format('d/m/Y H:i') }}</td>
                            <td data-label="Profissional">{{ $a->professional_name ?? 'A definir' }}</td>
                            <td data-label="Situação"><x-ui.badge>{{ $a->status->label() }}</x-ui.badge></td>
                            <td><a href="{{ route('account.appointments.show', $a) }}">Ver detalhes<span class="visually-hidden"> do horário de {{ $a->starts_at?->timezone(config('barbearia.display_timezone'))->format('d/m/Y H:i') }}</span></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </section>
</x-layouts.account>
