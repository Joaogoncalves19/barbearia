{{--
    Corpo generico dos e-mails (templates.md): titulo, paragrafos, detalhes
    (rotulo => valor), botao e nota. Tudo escapado: texto de campanha escrito
    pela equipe e nomes de clientes nunca viram HTML.
--}}
@extends('mail.communication.layout')

@section('content')
    <h1>{{ $heading }}</h1>
    @foreach ($paragraphs ?? [] as $p)
        <p>{{ $p }}</p>
    @endforeach
    @if (! empty($details))
        <table class="details" role="presentation">
            @foreach ($details as $rotulo => $valor)
                <tr><th scope="row">{{ $rotulo }}</th><td>{{ $valor }}</td></tr>
            @endforeach
        </table>
    @endif
    @if (! empty($button))
        <p><a class="btn" href="{{ $button['url'] }}">{{ $button['label'] }}</a></p>
    @endif
    @if (! empty($secondary))
        <p class="muted">{{ $secondary['text'] }} <a href="{{ $secondary['url'] }}">{{ $secondary['label'] }}</a></p>
    @endif
    @foreach ($notes ?? [] as $n)
        <p class="muted">{{ $n }}</p>
    @endforeach
@endsection
