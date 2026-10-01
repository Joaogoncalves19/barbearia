{{-- Razao do caixa: $movements. --}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
@endphp
@if ($movements->isEmpty())
    <p class="text-sm text-muted">Nenhuma movimentação ainda.</p>
@else
    <x-ui.table caption="Movimentações do caixa" caption-hidden stacked>
        <thead><tr><th scope="col">Quando</th><th scope="col">Tipo</th><th scope="col">Forma</th><th scope="col">Valor</th><th scope="col">Origem / motivo</th><th scope="col">Quem</th></tr></thead>
        <tbody>
            @foreach ($movements as $m)
                <tr>
                    <td data-label="Quando" class="numeric">{{ BusinessTime::formatLocal($m->occurred_at, 'd/m H:i') }}</td>
                    <td data-label="Tipo"><x-ui.badge :variant="$m->amount_cents > 0 ? 'success' : 'danger'">{{ $m->type->label() }}</x-ui.badge></td>
                    <td data-label="Forma">{{ $m->method->label() }}</td>
                    <td data-label="Valor" class="numeric">{{ Money::fromCents($m->amount_cents)->format() }}</td>
                    <td data-label="Origem / motivo">{{ $m->description }}</td>
                    <td data-label="Quem">{{ $m->createdBy->name ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>
@endif
