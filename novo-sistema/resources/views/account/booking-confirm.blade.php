@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.booking title="Confirmar agendamento" :step="4">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('booking.slots', ['service' => $service, 'profissional' => $query['profissional'], 'data' => $query['data']]) }}">Trocar horário</a>
        <h1 class="h2">Confirme seu horário</h1>
    </header>

    <x-ui.card>
        <dl class="summary-list">
            <div><dt>Serviço</dt><dd>{{ $service->name }}</dd></div>
            <div><dt>Quando</dt><dd>{{ BusinessTime::local($start)->locale('pt_BR')->translatedFormat('l, d/m/Y') }}, {{ BusinessTime::formatLocal($start, 'H:i') }} às {{ BusinessTime::formatLocal($end, 'H:i') }}</dd></div>
            <div><dt>Profissional</dt><dd>{{ $anyProfessional ? 'Sem preferência (hoje: '.$professional->display_name.')' : $professional->display_name }}</dd></div>
            <div><dt>Duração</dt><dd>{{ $service->durationLabel() }}</dd></div>
        </dl>
        <div class="summary-total"><span>Valor</span><strong>{{ $service->price()->format() }}</strong></div>
    </x-ui.card>

    <form method="POST" action="{{ route('account.booking.store') }}" class="stack" novalidate>
        @csrf
        @foreach ($query as $campo => $valor)
            <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
        @endforeach
        <x-ui.textarea name="notes" label="Observação para a barbearia" rows="2" optional />
        <p class="text-sm text-muted">O valor fica registrado agora: se o preço mudar depois, o seu não muda. Pagamento na barbearia.</p>
        <x-ui.button type="submit" variant="accent" block>Confirmar agendamento</x-ui.button>
    </form>
</x-layouts.booking>
