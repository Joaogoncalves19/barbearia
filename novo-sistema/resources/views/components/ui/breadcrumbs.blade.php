{{-- :items = [['label' => 'Painel', 'href' => '...'], ['label' => 'Agenda']] (ultimo = pagina atual) --}}
@props(['items' => []])
<nav class="breadcrumbs" aria-label="Você está em">
    <ol>
        @foreach ($items as $item)
            <li>
                @if ($loop->last || empty($item['href']))
                    <span aria-current="page">{{ $item['label'] }}</span>
                @else
                    <a href="{{ $item['href'] }}">{{ $item['label'] }}</a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
