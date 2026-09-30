{{--
    Agendamento pelo site (Fase 5): layout do site com a barra de etapas.
    :step = 1 servico | 2 profissional | 3 horario | 4 confirmacao
--}}
@props(['title', 'step' => 1])
@php
    $nav = [
        ['label' => 'Agendar', 'href' => route('booking.services'), 'current' => true],
        auth('customer')->check()
            ? ['label' => 'Minha conta', 'href' => route('account.home')]
            : ['label' => 'Entrar', 'href' => route('customer.login')],
    ];
    $etapas = [1 => 'Serviço', 2 => 'Profissional', 3 => 'Dia e horário', 4 => 'Confirmação'];
@endphp
<x-layouts.site :title="$title" :brand="config('app.name')" :nav="$nav" :booking-url="route('booking.services')" :bottom-bar="false" :home-url="route('home')">
    <div class="section section--tight">
        <div class="container-narrow stack stack-lg">
            <ol class="steps" aria-label="Etapas do agendamento">
                @foreach ($etapas as $n => $rotulo)
                    <li @class(['steps__item', 'is-done' => $n < $step]) @if ($n === $step) aria-current="step" @endif>{{ $rotulo }}</li>
                @endforeach
            </ol>

            @if (session('status'))
                <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
            @endif
            @error('slot')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            {{ $slot }}
        </div>
    </div>
</x-layouts.site>
