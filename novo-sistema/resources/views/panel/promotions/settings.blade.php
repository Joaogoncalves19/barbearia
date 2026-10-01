@php
    use App\Modules\Shared\Support\Money;
    $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',');
    $p = $policy;
@endphp
<x-layouts.staff title="Fidelidade, aniversário e indicação">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Fidelidade, aniversário e indicação</h1>
            <p class="text-muted">Vale um desconto só por atendimento: entre cupom, pontos, aniversário, indicação e desconto manual, o maior. Mudanças valem para o que acontecer daqui em diante e ficam na auditoria.</p>
        </div>
    </header>

    @error('policy')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <form method="POST" action="{{ route('panel.promotions.settings.update') }}" class="stack" novalidate>
        @csrf
        @method('PUT')
        <div class="dashboard-grid">
            <x-ui.card title="Fidelidade: ganhar pontos">
                <div class="stack">
                    <x-ui.switch name="loyalty_enabled" label="Programa de fidelidade ativo" :checked="$p['loyalty_enabled']" />
                    <x-ui.select name="loyalty_earn_mode" label="O cliente ganha pontos" :options="['visit' => 'Por atendimento concluído', 'value' => 'Pelo valor pago']" :value="$p['loyalty_earn_mode']" />
                    <x-ui.input name="loyalty_points_per_visit" label="Pontos por atendimento" type="number" min="1" inputmode="numeric" :value="$p['loyalty_points_per_visit']" />
                    <x-ui.input name="loyalty_cents_per_point" label="Valor pago para ganhar 1 ponto" inputmode="decimal" :value="Money::fromCents((int) $p['loyalty_cents_per_point'])->toInput()" hint="Usado quando o ganho é pelo valor pago. Ex.: 10,00." />
                </div>
            </x-ui.card>
            <x-ui.card title="Fidelidade: resgatar">
                <div class="stack">
                    <x-ui.input name="loyalty_points_required" label="Pontos para um resgate" type="number" min="1" inputmode="numeric" :value="$p['loyalty_points_required']" />
                    <x-ui.select name="loyalty_reward_type" label="Recompensa" :options="['percent' => 'Percentual', 'fixed' => 'Valor fixo', 'free_service' => 'Serviço mais caro grátis']" :value="$p['loyalty_reward_type']" />
                    <x-ui.select name="loyalty_reward_base" label="O percentual incide sobre" :options="['cheapest' => 'O serviço mais barato', 'most_expensive' => 'O serviço mais caro', 'total' => 'O total dos serviços']" :value="$p['loyalty_reward_base']" />
                    <x-ui.input name="loyalty_reward_percent_bp" label="Percentual do resgate" inputmode="decimal" :value="$pct((int) $p['loyalty_reward_percent_bp'])" hint="Ex.: 50 (para 50%)." />
                    <x-ui.input name="loyalty_reward_fixed_cents" label="Valor do resgate" inputmode="decimal" :value="Money::fromCents((int) $p['loyalty_reward_fixed_cents'])->toInput()" hint="Usado quando a recompensa é valor fixo." />
                </div>
            </x-ui.card>
            <x-ui.card title="Aniversário">
                <div class="stack">
                    <x-ui.switch name="birthday_enabled" label="Desconto de aniversário ativo" :checked="$p['birthday_enabled']" />
                    <x-ui.input name="birthday_percent_bp" label="Desconto no mês do aniversário" inputmode="decimal" :value="$pct((int) $p['birthday_percent_bp'])" hint="Uma vez no mês do aniversário, no primeiro atendimento que usar." />
                </div>
            </x-ui.card>
            <x-ui.card title="Indicação">
                <div class="stack">
                    <x-ui.switch name="referral_enabled" label="Indicação ativa" :checked="$p['referral_enabled']" />
                    <x-ui.input name="referral_percent_bp" label="Desconto no primeiro atendimento do indicado" inputmode="decimal" :value="$pct((int) $p['referral_percent_bp'])" />
                    <x-ui.input name="referral_bonus_points" label="Pontos para quem indicou" type="number" min="0" inputmode="numeric" :value="$p['referral_bonus_points']" hint="Quando o indicado conclui o primeiro atendimento. Uma vez por indicado." />
                </div>
            </x-ui.card>
        </div>
        <div><x-ui.button type="submit">Salvar regras</x-ui.button></div>
    </form>
</x-layouts.staff>
