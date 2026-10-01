@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $q = $quote;
    $escolhido = $q->chosen;
@endphp
<x-layouts.booking title="Confirmar agendamento" :step="4">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('booking.slots', ['service' => $service, 'profissional' => $query['profissional'], 'data' => $query['data']]) }}">Trocar horário</a>
        <h1 class="h2">Confirme seu horário</h1>
    </header>

    @error('promotion')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card>
        <dl class="summary-list">
            <div><dt>Serviço</dt><dd>{{ $service->name }}</dd></div>
            <div><dt>Quando</dt><dd>{{ BusinessTime::local($start)->locale('pt_BR')->translatedFormat('l, d/m/Y') }}, {{ BusinessTime::formatLocal($start, 'H:i') }} às {{ BusinessTime::formatLocal($end, 'H:i') }}</dd></div>
            <div><dt>Profissional</dt><dd>{{ $anyProfessional ? 'Sem preferência (hoje: '.$professional->display_name.')' : $professional->display_name }}</dd></div>
            <div><dt>Duração</dt><dd>{{ $service->durationLabel() }}</dd></div>
            <div><dt>Valor</dt><dd class="numeric">{{ $service->price()->format() }}</dd></div>
            @if ($escolhido)
                <div><dt>Desconto · {{ $escolhido->label }}</dt><dd class="numeric" data-discount>−{{ Money::fromCents($escolhido->amountCents)->format() }}</dd></div>
            @endif
        </dl>
        <div class="summary-total"><span>Total</span><strong data-total>{{ Money::fromCents((int) $q->totalCents())->format() }}</strong></div>
        @foreach ($q->notes as $nota)<p class="text-sm text-muted">{{ $nota }}</p>@endforeach
        @foreach ($q->problems as $problema)<x-ui.alert variant="warning">{{ $problema }}</x-ui.alert>@endforeach
    </x-ui.card>

    <x-ui.card title="Cupom e pontos">
        <form method="GET" action="{{ route('account.booking.confirm') }}" class="stack" novalidate>
            @foreach ($query as $campo => $valor)
                <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
            @endforeach
            <x-ui.input name="cupom" label="Cupom" :value="$promotion->couponCode" optional />
            @if ($policy->bool('loyalty_enabled'))
                <x-ui.checkbox name="pontos" label="Usar meus pontos" :checked="$promotion->useLoyalty" :hint="'Você tem '.$loyaltyAvailable.' pontos disponíveis; o resgate usa '.$policy->int('loyalty_points_required').'. Só saem do saldo quando o atendimento for concluído.'" />
            @endif
            <p class="text-sm text-muted">Vale um desconto só, o maior (cupom, pontos, aniversário ou indicação).</p>
            <div><x-ui.button type="submit" variant="secondary">Atualizar valor</x-ui.button></div>
        </form>
    </x-ui.card>

    <form method="POST" action="{{ route('account.booking.store') }}" class="stack" novalidate>
        @csrf
        @foreach ($query as $campo => $valor)
            <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
        @endforeach
        @if ($promotion->couponCode && ! isset($q->problems['coupon']))<input type="hidden" name="cupom" value="{{ $promotion->couponCode }}">@endif
        @if ($promotion->useLoyalty && ! isset($q->problems['loyalty']))<input type="hidden" name="pontos" value="1">@endif
        <input type="hidden" name="expected_total" value="{{ (int) $q->totalCents() }}">
        <x-ui.textarea name="notes" label="Observação para a barbearia" rows="2" optional />
        <p class="text-sm text-muted">O valor fica registrado agora: se o preço mudar depois, o seu não muda. Pagamento na barbearia.</p>
        <x-ui.button type="submit" variant="accent" block>Confirmar agendamento</x-ui.button>
    </form>
</x-layouts.booking>
