@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Pricing\Discount;
    use App\Modules\Shared\Support\Money;
    $p = $policy;
    $ganho = $p->string('loyalty_earn_mode') === 'value'
        ? '1 ponto a cada '.Money::fromCents($p->int('loyalty_cents_per_point'))->format().' pagos'
        : $p->int('loyalty_points_per_visit').' ponto(s) por atendimento concluído';
    $recompensa = match ($p->string('loyalty_reward_type')) {
        'fixed' => Money::fromCents($p->int('loyalty_reward_fixed_cents'))->format().' de desconto',
        'free_service' => 'o serviço mais caro grátis',
        default => Discount::percent($p->int('loyalty_reward_percent_bp'))->label().' de desconto '.match ($p->string('loyalty_reward_base')) {
            'total' => 'no total dos serviços', 'most_expensive' => 'no serviço mais caro', default => 'no serviço mais barato' },
    };
@endphp
<x-layouts.account title="Benefícios">
    <header class="stack stack-sm">
        <p class="eyebrow">Minha conta</p>
        <h1 class="h2">Benefícios e fidelidade</h1>
    </header>

    {{-- Fase 12: so o que o cliente tem direito hoje, calculado no servidor (PromotionEngine). --}}
    <x-ui.card title="Vale para você hoje">
        @if ($entitlements === [])
            <p class="text-sm text-muted" data-entitlements-empty>Nenhum benefício disponível hoje.</p>
        @else
            <ul class="stack stack-sm" data-entitlements>
                @foreach ($entitlements as $e)
                    <li>{{ $e['label'] }}</li>
                @endforeach
            </ul>
            <p class="text-sm text-muted">Vale um desconto por atendimento: ao agendar, o sistema aplica o maior. Cupons divulgados pela barbearia são informados na confirmação.</p>
        @endif
    </x-ui.card>

    <x-ui.card title="Seus pontos">
        @if ($p->bool('loyalty_enabled'))
            <dl class="summary-list">
                <div><dt>Saldo</dt><dd class="numeric" data-loyalty-balance>{{ $balance }} pontos</dd></div>
                <div><dt>Disponível para resgate</dt><dd class="numeric">{{ $available }} pontos</dd></div>
            </dl>
            <p class="text-sm">Você ganha {{ $ganho }}. Com {{ $p->int('loyalty_points_required') }} pontos, troque por {{ $recompensa }}: escolha "usar meus pontos" ao agendar ou peça na barbearia. Os pontos só saem do saldo quando o atendimento é concluído; se cancelar, nada é perdido.</p>
        @else
            <p class="text-sm text-muted">O programa de fidelidade não está ativo no momento. Seu saldo: {{ $balance }} pontos.</p>
        @endif
    </x-ui.card>

    @if ($p->bool('referral_enabled'))
        <x-ui.card title="Indique um amigo">
            @if ($customer->referral_code)
                <p>Seu código: <strong class="numeric">{{ $customer->referral_code }}</strong></p>
                <p class="text-sm">Quem se cadastrar com o seu código ganha {{ Discount::percent($p->int('referral_percent_bp'))->label() }} de desconto no primeiro atendimento{{ $p->int('referral_bonus_points') > 0 ? ', e você ganha '.$p->int('referral_bonus_points').' ponto(s) quando ele for atendido' : '' }}.</p>
            @else
                <form method="POST" action="{{ route('account.loyalty.code') }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">Gerar meu código de indicação</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card title="Extrato">
        @if ($entries->isEmpty())
            <p class="text-sm text-muted">Nenhum lançamento ainda.</p>
        @else
            <x-ui.table caption="Extrato de pontos" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Descrição</th><th scope="col">Pontos</th></tr></thead>
                <tbody>
                    @foreach ($entries as $e)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $e->occurred_at ? BusinessTime::formatLocal($e->occurred_at, 'd/m/Y') : '—' }}</td>
                            <td data-label="Descrição">{{ $e->kind->label() }}{{ $e->description ? ' · '.$e->description : '' }}</td>
                            <td data-label="Pontos" class="numeric">{{ $e->points > 0 ? '+' : '' }}{{ $e->points }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.account>
