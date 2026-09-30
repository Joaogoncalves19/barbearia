{{--
    Escolha do dia (links; funciona sem JavaScript). Os dias vem do servidor
    (Availability::bookableDates): so dias com a barbearia aberta, dentro do
    alcance do canal.
    Parametros: $days (list<Y-m-d>), $date (Y-m-d), $url (Closure: string $dia => string)
--}}
@php $zona = \App\Modules\Scheduling\Support\BusinessTime::zone(); @endphp
<nav class="days" aria-label="Dias disponíveis">
    @foreach ($days as $d)
        @php $c = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $d, $zona)->locale('pt_BR'); @endphp
        <a class="day" href="{{ $url($d) }}" @if ($d === $date) aria-current="date" @endif
           aria-label="{{ $c->translatedFormat('l, d \d\e F') }}">
            <span>{{ $c->translatedFormat('D') }}</span>
            <strong>{{ $c->format('d') }}</strong>
            <span>{{ $c->translatedFormat('M') }}</span>
        </a>
    @endforeach
</nav>
