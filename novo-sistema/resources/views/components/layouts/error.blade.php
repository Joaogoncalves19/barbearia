{{-- Pagina de erro: sem banco, sem detalhe tecnico, com caminho de volta. --}}
@props(['code', 'title'])
<x-layouts.document :title="$title" surface="clara" area="panel" :noindex="true" :csrf="false">
    <main id="conteudo" class="auth-page">
        <div class="auth-card stack">
            <p class="eyebrow">Erro {{ $code }}</p>
            <h1 class="h2">{{ $title }}</h1>
            <p class="text-muted">{{ $slot }}</p>
            <div><x-ui.button href="{{ url('/') }}" variant="secondary" icon="house">Ir para o início</x-ui.button></div>
        </div>
    </main>
</x-layouts.document>
