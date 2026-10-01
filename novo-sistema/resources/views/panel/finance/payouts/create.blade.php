@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    use Illuminate\Support\Str;
    $fmt = fn (int $c) => Money::fromCents($c)->format();
    $nada = $commissions->isEmpty() && $tips->isEmpty() && $advances->isEmpty();
@endphp
<x-layouts.staff :title="'Repasse · '.$professional->display_name">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.commissions.show', $professional) }}">Voltar para o extrato</a>
            <h1 class="page-head__title">Repasse a {{ $professional->display_name }}</h1>
            <p class="text-muted">Confira o que está em aberto até agora. Ao registrar, tudo isto fica marcado como repassado e não entra em outro repasse.</p>
        </div>
    </header>

    @error('payout')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <div class="stats">
        <div class="stat"><span class="stat__label">Comissão</span><span class="stat__value numeric">{{ $fmt($open['commission']) }}</span></div>
        <div class="stat"><span class="stat__label">Gorjeta</span><span class="stat__value numeric">{{ $fmt($open['tips']) }}</span></div>
        <div class="stat"><span class="stat__label">Vales (abatidos)</span><span class="stat__value numeric">−{{ $fmt($open['advances']) }}</span></div>
        <div class="stat"><span class="stat__label">Líquido a pagar</span><span class="stat__value numeric" data-payout-net>{{ $fmt($open['net']) }}</span></div>
    </div>

    @if ($nada)
        <x-ui.empty-state title="Nada em aberto" icon="circle-check">Não há comissão, gorjeta ou vale em aberto para este profissional.</x-ui.empty-state>
    @else
        <div class="dashboard-grid">
            <x-ui.card title="Comissões">
                @if ($commissions->isEmpty())
                    <p class="text-sm text-muted">Nenhuma.</p>
                @else
                    <dl class="summary-list">
                        @foreach ($commissions as $e)
                            <div><dt>{{ $e->occurred_at ? BusinessTime::formatLocal($e->occurred_at, 'd/m') : '' }} · {{ $e->attendance?->code ?? $e->kind->label() }} · {{ $e->item_name ?? $e->reason }}</dt><dd class="numeric">{{ $fmt($e->amount_cents) }}</dd></div>
                        @endforeach
                    </dl>
                @endif
            </x-ui.card>
            <x-ui.card title="Gorjetas e vales">
                @if ($tips->isEmpty() && $advances->isEmpty())
                    <p class="text-sm text-muted">Nenhum.</p>
                @else
                    <dl class="summary-list">
                        @foreach ($tips as $t)
                            <div><dt>Gorjeta · {{ BusinessTime::formatLocal($t->occurred_at, 'd/m') }} · {{ $t->attendance?->code ?? $t->kind->label() }}</dt><dd class="numeric">{{ $fmt($t->amount_cents) }}</dd></div>
                        @endforeach
                        @foreach ($advances as $v)
                            <div><dt>{{ $v->kind->label() }} · {{ $v->occurred_at ? BusinessTime::formatLocal($v->occurred_at, 'd/m') : '' }} · {{ $v->description }}</dt><dd class="numeric">−{{ $fmt($v->amount_cents) }}</dd></div>
                        @endforeach
                    </dl>
                @endif
            </x-ui.card>
        </div>

        @if ($open['net'] < 0)
            <x-ui.alert variant="warning">O valor a pagar está negativo: vales, estornos ou ajustes em aberto são maiores que o valor a receber. O repasse só pode ser registrado quando houver saldo (novos atendimentos) ou depois de estornar o lançamento indevido.</x-ui.alert>
        @else
            <x-ui.card title="Registrar o repasse">
                <form method="POST" action="{{ route('panel.payouts.store', $professional) }}" class="stack" novalidate>
                    @csrf
                    <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                    <x-ui.select name="method" label="Forma de pagamento" :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->value === 'other' ? 'Outro (transferência)' : $m->label()])->all()" value="pix" hint="Em dinheiro, a saída vai para o caixa aberto." />
                    <x-ui.textarea name="notes" label="Observação" optional />
                    <div><x-ui.button type="submit" icon="banknote">Registrar repasse de {{ $fmt($open['net']) }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    @endif
</x-layouts.staff>
