{{--
    Pagina de erro (redesign): na marca, sem banco de dados e sem detalhe
    tecnico, com caminho de volta. O numero grande faz o papel da imagem.
--}}
@props(['code', 'title'])
<x-layouts.document :title="$title" surface="escura" area="site" :noindex="true" :csrf="false">
    <main id="conteudo" class="error-page">
        <div class="container error-page__inner">
            <a class="brand" href="{{ url('/') }}">
                <span class="brand__mark" aria-hidden="true">{{ mb_substr(config('app.name'), 0, 1) }}</span>
                <span class="brand__name">{{ config('app.name') }}</span>
            </a>
            <div class="error-page__body">
                <p class="error-page__code" aria-hidden="true">{{ $code }}</p>
                <div class="stack">
                    <p class="eyebrow">Erro {{ $code }}</p>
                    <h1 class="h1 caps">{{ $title }}</h1>
                    <p class="lead">{{ $slot }}</p>
                    <div class="cluster">
                        <x-ui.button href="{{ url('/') }}" variant="accent" icon="house">Ir para o início</x-ui.button>
                    </div>
                </div>
            </div>
            <span class="ruler ruler--accent" aria-hidden="true"></span>
        </div>
    </main>
</x-layouts.document>
