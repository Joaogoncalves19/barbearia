@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
@endphp
<x-layouts.account title="Comprovantes">
    <header class="stack stack-sm">
        <p class="eyebrow">Minha conta</p>
        <h1 class="h2">Comprovantes</h1>
        <p class="text-muted">Cada atendimento concluído tem um comprovante: o que foi feito, os descontos e como foi pago. Dá para imprimir ou receber no seu e-mail.</p>
    </header>

    @if ($attendances->isEmpty())
        <x-ui.empty-state title="Nenhum comprovante ainda" icon="receipt">O comprovante aparece aqui quando um atendimento seu é concluído na barbearia.</x-ui.empty-state>
    @else
        <x-ui.table caption="Comprovantes dos atendimentos" caption-hidden stacked>
            <thead>
                <tr><th scope="col">Data</th><th scope="col">Atendimento</th><th scope="col">Profissional</th><th scope="col">Total</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr>
            </thead>
            <tbody>
                @foreach ($attendances as $t)
                    <tr data-receipt="{{ $t->code }}">
                        <td data-label="Data" class="numeric">{{ $t->completed_at ? BusinessTime::formatLocal($t->completed_at, 'd/m/Y') : '—' }}</td>
                        <td data-label="Atendimento" class="numeric">{{ $t->code }}</td>
                        <td data-label="Profissional">{{ $t->professional_name ?? '—' }}</td>
                        <td data-label="Total" class="numeric">{{ $t->total_cents !== null ? Money::fromCents((int) $t->total_cents)->format() : '—' }}</td>
                        <td><a href="{{ route('account.attendances.show', $t) }}">Ver comprovante<span class="visually-hidden"> {{ $t->code }}</span></a></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
        {{ $attendances->links() }}
    @endif
</x-layouts.account>
